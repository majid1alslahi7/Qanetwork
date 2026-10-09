<?php

namespace Tests\Feature\Console;

use App\Jobs\CheckNetworkConnectionHealthJob;
use App\Services\Operations\OperationsMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RunQueueBurstCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database', 'cache.default' => 'database']);
    }

    #[DataProvider('queues')]
    public function test_starts_only_the_requested_queue_with_bounded_process_and_lock_cleanup(string $queue): void
    {
        Process::fake();

        $this->artisan('qanetwork:work', ['queue' => $queue])->assertSuccessful();

        Process::assertRanTimes(fn (PendingProcess $process): bool => $process->path === base_path()
            && $process->timeout === 120 && $process->quietly === true
            && $process->command === [PHP_BINARY, base_path('artisan'), 'queue:work', 'database',
                '--queue='.$queue, '--stop-when-empty', '--max-time=50', '--max-jobs=100',
                '--timeout=60', '--sleep=1', '--tries=3', '--no-interaction', '--quiet'], 1);
        $this->assertDatabaseCount('cache_locks', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame('unknown', app(OperationsMonitor::class)->summary()['workers'][0]['status']);
    }

    public static function queues(): array
    {
        return [['sales'], ['reconciliation'], ['network-health'], ['delivery']];
    }

    #[DataProvider('invalidArguments')]
    public function test_rejects_invalid_arguments_without_starting_any_process(string $queue, string $seconds): void
    {
        Process::fake();

        $this->artisan('qanetwork:work', ['queue' => $queue, '--seconds' => $seconds])->assertFailed();

        Process::assertNothingRan();
        $this->assertDatabaseCount('cache_locks', 0);
    }

    public static function invalidArguments(): array
    {
        return [['default', '50'], ['sales,delivery', '50'], ['sales;echo secret', '50'],
            ['sales', '0'], ['sales', '51'], ['sales', '-1'], ['sales', '1.5'], ['sales', 'invalid']];
    }

    public function test_skips_overlap_without_releasing_the_other_worker_lock(): void
    {
        Process::fake();
        $lock = Cache::lock('qanetwork:cron-worker:testing:database:sales', 150);
        $this->assertTrue($lock->get());

        $this->artisan('qanetwork:work', ['queue' => 'sales'])
            ->expectsOutput('A Cron worker already holds this queue lock; skipped.')->assertSuccessful();

        Process::assertNothingRan();
        $this->assertFalse(Cache::lock('qanetwork:cron-worker:testing:database:sales', 150)->get());
        $lock->release();
    }

    public function test_independent_queues_do_not_block_each_other(): void
    {
        Process::fake();
        $lock = Cache::lock('qanetwork:cron-worker:testing:database:sales', 150);
        $lock->get();

        $this->artisan('qanetwork:work', ['queue' => 'delivery', '--seconds' => 1])->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => in_array('--queue=delivery', $process->command, true) && $process->timeout === 71);
        $this->assertDatabaseCount('cache_locks', 1);
        $lock->release();
    }

    public function test_process_failure_is_reported_without_exposing_worker_output_and_lock_is_released(): void
    {
        Process::fake(['*' => Process::result(output: 'private-card-password', errorOutput: 'private-provider-secret', exitCode: 1)]);

        $this->assertSame(1, Artisan::call('qanetwork:work', ['queue' => 'sales']));

        $this->assertStringNotContainsString('private-', Artisan::output());
        $this->assertDatabaseCount('cache_locks', 0);
        Process::assertRanTimes(fn (PendingProcess $process): bool => in_array('--queue=sales', $process->command, true), 1);
    }

    public function test_process_exception_is_reported_without_exception_details_and_lock_is_released(): void
    {
        Process::fake(fn (): never => throw new RuntimeException('private-process-secret'));

        $this->assertSame(1, Artisan::call('qanetwork:work', ['queue' => 'sales']));

        $this->assertStringNotContainsString('private-', Artisan::output());
        $this->assertDatabaseCount('cache_locks', 0);
    }

    #[DataProvider('unsafeSettings')]
    public function test_rejects_unsafe_settings_without_processing_jobs(string $setting, mixed $value): void
    {
        Process::fake();
        config([$setting => $value]);

        $this->artisan('qanetwork:work', ['queue' => 'sales'])->assertFailed();

        Process::assertNothingRan();
    }

    public static function unsafeSettings(): array
    {
        return [['queue.default', 'sync'], ['cache.default', 'array'], ['queue.connections.database.retry_after', 60],
            ['queue.connections.database.retry_after', 90], ['queue.connections.database.retry_after', 130]];
    }

    public function test_unavailable_lock_storage_does_not_start_a_worker(): void
    {
        Process::fake();
        config(['cache.stores.database.lock_table' => 'missing_burst_locks']);

        $this->artisan('qanetwork:work', ['queue' => 'sales'])->assertFailed();

        Process::assertNothingRan();
    }

    public function test_real_child_worker_processes_a_health_job_and_records_only_its_queue_activity(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'qanetwork-burst-');
        $this->assertNotFalse($databasePath);
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $databasePath, 'DB_URL' => '', 'CACHE_STORE' => 'database',
            'CACHE_PREFIX' => 'burst_test_', 'QUEUE_CONNECTION' => 'database', 'QUEUE_FAILED_DRIVER' => 'database-uuids'];
        config(['database.connections.burst_test' => ['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '', 'foreign_key_constraints' => true],
            'queue.connections.burst_test' => ['driver' => 'database', 'connection' => 'burst_test', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]]);
        try {
            $migration = Process::path(base_path())->env($environment)->timeout(60)->run([PHP_BINARY, base_path('artisan'), 'migrate', '--force', '--no-interaction']);
            $this->assertTrue($migration->successful(), $migration->errorOutput());
            Queue::connection('burst_test')->push(new CheckNetworkConnectionHealthJob(999999), queue: 'network-health');

            $result = Process::path(base_path())->env($environment)->timeout(90)->run([PHP_BINARY, base_path('artisan'), 'qanetwork:work', 'network-health', '--seconds=1', '--no-interaction']);

            $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
            $database = DB::connection('burst_test');
            $this->assertSame(0, $database->table('jobs')->count());
            $this->assertSame(0, $database->table('failed_jobs')->count());
            $this->assertSame(0, $database->table('cache_locks')->count());
            $this->assertTrue($database->table('cache')->where('key', 'like', '%worker:network-health')->exists());
            $this->assertFalse($database->table('cache')->where('key', 'like', '%worker:sales')->exists());
        } finally {
            DB::purge('burst_test');
            unlink($databasePath);
        }
    }
}
