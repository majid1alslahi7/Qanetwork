<?php

namespace Tests\Feature\Console;

use App\Services\Delivery\Contracts\SmsSender;
use App\Services\Delivery\UnavailableSmsSender;
use App\Services\Operations\OperationsMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProductionPreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_without_financial_writes_or_messages_and_removes_probes(): void
    {
        $this->configureProduction();
        $cacheEntries = DB::table('cache')->count();

        $report = $this->report(0);

        $this->assertTrue($report['passed']);
        $this->assertCount(16, $report['checks']);
        $this->assertSame(['pass'], array_values(array_unique(array_column($report['checks'], 'status'))));
        $this->assertStringContainsString('backup restoration', $report['scope']);
        $this->assertDatabaseCount('cache', $cacheEntries);
        $this->assertDatabaseCount('cache_locks', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('card_deliveries', 0);
    }

    #[DataProvider('unsafeConfiguration')]
    public function test_rejects_unsafe_configuration(string $setting, mixed $value, string $check): void
    {
        $this->configureProduction();
        config([$setting => $value]);

        $report = $this->report(1);

        $this->assertFalse($report['passed']);
        $this->assertSame('fail', $this->checkStatus($report, $check));
    }

    public static function unsafeConfiguration(): array
    {
        return [
            'debug' => ['app.debug', true, 'debug'],
            'missing key' => ['app.key', '', 'encryption'],
            'invalid key' => ['app.key', 'base64:%%%not-a-key', 'encryption'],
            'short key' => ['app.key', 'base64:'.base64_encode('short'), 'encryption'],
            'no expiry' => ['sanctum.expiration', null, 'tokens'],
            'zero expiry' => ['sanctum.expiration', 0, 'tokens'],
            'sync queue' => ['queue.default', 'sync', 'queue'],
            'disabled failures' => ['queue.failed.driver', 'null', 'failed_jobs'],
            'timeout collision' => ['queue.connections.database.retry_after', 60, 'queue'],
            'local cache' => ['cache.default', 'array', 'cache'],
            'http' => ['app.url', 'http://alssemam.live', 'url'],
            'placeholder' => ['app.url', 'https://api.qanetwork.example', 'url'],
            'reserved' => ['app.url', 'https://example.com', 'url'],
            'local IP' => ['app.url', 'https://127.0.0.1', 'url'],
            'localhost' => ['app.url', 'https://localhost', 'url'],
            'password' => ['app.url', 'https://user:private-password@alssemam.live', 'url'],
            'username' => ['app.url', 'https://user@alssemam.live', 'url'],
            'query' => ['app.url', 'https://alssemam.live?token=private-token', 'url'],
            'fragment' => ['app.url', 'https://alssemam.live#secret', 'url'],
            'port' => ['app.url', 'https://alssemam.live:0', 'url'],
        ];
    }

    public function test_rejects_local_environment(): void
    {
        $this->configureProduction();
        app()->instance('env', 'local');

        $this->assertSame('fail', $this->checkStatus($this->report(1), 'environment'));
    }

    public function test_reports_pending_migrations_without_applying_them(): void
    {
        $this->configureProduction();
        DB::table('migrations')->where('migration', '2026_10_06_004448_create_card_deliveries_table')->delete();

        $report = $this->report(1);

        $this->assertSame('fail', $this->checkStatus($report, 'migrations'));
        $this->assertDatabaseMissing('migrations', ['migration' => '2026_10_06_004448_create_card_deliveries_table']);
    }

    public function test_reports_cache_lock_failure_and_removes_probe(): void
    {
        $this->configureProduction();
        config(['cache.stores.database.lock_table' => 'missing_preflight_locks']);
        Cache::purge('database');
        $cacheEntries = DB::table('cache')->count();

        $report = $this->report(1);

        $this->assertSame('fail', $this->checkStatus($report, 'cache'));
        $this->assertDatabaseCount('cache', $cacheEntries);
    }

    public function test_rejects_unknown_and_stale_activity_without_creating_heartbeats(): void
    {
        $this->configureProduction(false);
        app(OperationsMonitor::class)->recordWorker('database', 'sales');
        $this->travel(181)->seconds();

        $report = $this->report(1);

        $this->assertSame('fail', $this->checkStatus($report, 'scheduler'));
        $this->assertSame('fail', $this->checkStatus($report, 'worker:sales'));
        $this->assertSame('fail', $this->checkStatus($report, 'worker:delivery'));
        $this->assertSame('unknown', app(OperationsMonitor::class)->summary()['workers'][3]['status']);
    }

    public function test_rejects_unconfigured_sms(): void
    {
        $this->configureProduction();
        app()->instance(SmsSender::class, new UnavailableSmsSender);

        $this->assertSame('fail', $this->checkStatus($this->report(1), 'sms'));
    }

    public function test_reports_failed_jobs_without_discarding_or_exposing_them(): void
    {
        $this->configureProduction();
        DB::table('failed_jobs')->insert([
            'uuid' => 'failed-preflight-job', 'connection' => 'database', 'queue' => 'sales',
            'payload' => 'private-card-password', 'exception' => 'private-provider-password', 'failed_at' => now(),
        ]);

        $report = $this->report(1);

        $this->assertSame('fail', $this->checkStatus($report, 'failed_jobs'));
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertStringNotContainsString('private-', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_hides_dependency_errors_and_configuration_values_in_verbose_output(): void
    {
        $this->configureProduction();
        config(['app.url' => 'https://user:private-password@alssemam.live']);
        $this->mock(SmsSender::class)->shouldReceive('available')->once()->andThrow(new RuntimeException('private-provider-secret'));

        $exitCode = Artisan::call('qanetwork:preflight', ['--json' => true, '--verbose' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringNotContainsString('private-', $output);
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $this->checkStatus($report, 'sms'));
        $this->assertSame('pass', $this->checkStatus($report, 'database'));
    }

    public function test_reports_unreachable_queue_without_dispatching(): void
    {
        $this->configureProduction();
        config(['queue.connections.database.table' => 'missing_jobs']);
        Queue::swap(new QueueManager(app()));

        $report = $this->report(1);

        $this->assertSame('fail', $this->checkStatus($report, 'queue'));
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_human_report_shows_failed_requirements_and_external_verification_scope(): void
    {
        $this->configureProduction();
        config(['app.debug' => true]);

        $this->artisan('qanetwork:preflight')
            ->expectsOutputToContain('Disable APP_DEBUG.')
            ->expectsOutputToContain('backup restoration')
            ->expectsOutputToContain('Production preflight failed.')
            ->assertFailed();
    }

    private function configureProduction(bool $recordActivity = true): void
    {
        $this->freezeTime();
        app()->instance('env', 'production');
        config([
            'app.debug' => false, 'app.url' => 'https://alssemam.live',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'app.cipher' => 'AES-256-CBC',
            'queue.default' => 'database', 'queue.failed.driver' => 'database-uuids', 'cache.default' => 'database',
        ]);
        $sender = $this->mock(SmsSender::class);
        $sender->shouldReceive('available')->andReturnTrue();
        $sender->shouldNotReceive('submit');
        if ($recordActivity) {
            $monitor = app(OperationsMonitor::class);
            $monitor->recordScheduler();
            $monitor->recordWorker('database', 'sales,reconciliation,network-health,delivery');
        }
    }

    /** @return array{passed: bool, checks: list<array{check: string, status: string, message: string}>, scope: string} */
    private function report(int $exitCode): array
    {
        $this->assertSame($exitCode, Artisan::call('qanetwork:preflight', ['--json' => true]));

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array{checks: list<array{check: string, status: string, message: string}>} $report */
    private function checkStatus(array $report, string $check): string
    {
        return collect($report['checks'])->firstWhere('check', $check)['status'];
    }
}
