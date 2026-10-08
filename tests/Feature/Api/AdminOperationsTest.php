<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Jobs\CheckNetworkConnectionHealthJob;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_receives_401_for_operations_and_failed_jobs(): void
    {
        $this->getJson('/api/v1/admin/operations')->assertUnauthorized();
        $this->getJson('/api/v1/admin/operations/failed-jobs')->assertUnauthorized();
    }

    #[DataProvider('otherRoles')]
    public function test_non_admin_receives_403_even_with_admin_token_ability(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);
        $this->withToken($user->createToken('wrong-role', ['account', 'admin'])->plainTextToken);

        $this->getJson('/api/v1/admin/operations')->assertForbidden();
        $this->getJson('/api/v1/admin/operations/failed-jobs')->assertForbidden();
    }

    public static function otherRoles(): array
    {
        return ['seller' => [UserRole::SELLER], 'owner' => [UserRole::NETWORK_OWNER]];
    }

    public function test_admin_without_admin_token_ability_receives_403(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->withToken($admin->createToken('limited', ['account'])->plainTextToken);

        $this->getJson('/api/v1/admin/operations')->assertForbidden();
        $this->getJson('/api/v1/admin/operations/failed-jobs')->assertForbidden();
    }

    public function test_reading_operations_never_fabricates_scheduler_or_worker_activity(): void
    {
        $this->authenticateAdmin();

        $this->getJson('/api/v1/admin/operations')->assertOk()
            ->assertJsonPath('data.scheduler.status', 'unknown')
            ->assertJsonPath('data.scheduler.last_seen_at', null)
            ->assertJsonPath('data.workers.0.status', 'unknown')
            ->assertJsonPath('data.failed_jobs', ['status' => 'available', 'count' => 0]);
        $this->getJson('/api/v1/admin/operations')->assertJsonPath('data.scheduler.status', 'unknown');
    }

    public function test_actual_scheduler_execution_records_recent_activity(): void
    {
        $this->authenticateAdmin();
        $schedule = new Schedule('UTC');
        $schedule->call(fn (): bool => true)->everyMinute()->name('operations-test');
        $this->app->instance(Schedule::class, $schedule);

        $this->artisan('schedule:run')->assertSuccessful();

        $this->getJson('/api/v1/admin/operations')->assertJsonPath('data.scheduler.status', 'recent')
            ->assertJsonPath('data.workers.0.status', 'unknown');
    }

    public function test_real_once_worker_records_only_the_queue_it_processes(): void
    {
        $this->authenticateAdmin();
        CheckNetworkConnectionHealthJob::dispatch('nonexistent-connection');

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'network-health', '--sleep' => 0, '--tries' => 1])->assertSuccessful();

        $this->getJson('/api/v1/admin/operations')->assertJsonPath('data.workers.0.status', 'unknown')
            ->assertJsonPath('data.workers.2.queue', 'network-health')
            ->assertJsonPath('data.workers.2.status', 'recent');
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_wrong_connection_and_wrong_queue_cannot_mask_inactive_sales_worker(): void
    {
        $this->authenticateAdmin();

        Event::dispatch(new Looping('other-connection', 'sales'));
        Event::dispatch(new Looping('database', 'default'));

        $this->getJson('/api/v1/admin/operations')->assertJsonPath('data.workers.0.status', 'unknown');
        Event::dispatch(new Looping('database', 'sales, reconciliation'));
        $this->getJson('/api/v1/admin/operations')->assertJsonPath('data.workers.0.status', 'recent')
            ->assertJsonPath('data.workers.1.status', 'recent')->assertJsonPath('data.workers.3.status', 'unknown');
    }

    public function test_heartbeat_becomes_stale_and_a_new_observed_loop_refreshes_it(): void
    {
        $this->authenticateAdmin();
        $this->freezeTime();
        Event::dispatch(new Looping('database', 'sales'));
        $this->travel(180)->seconds();

        $this->getJson('/api/v1/admin/operations')->assertJsonPath('data.workers.0.status', 'recent')
            ->assertJsonPath('data.workers.0.age_seconds', 180);
        $this->travel(1)->seconds();
        $this->getJson('/api/v1/admin/operations')->assertJsonPath('data.workers.0.status', 'stale');
        Event::dispatch(new Looping('database', 'sales'));
        $this->getJson('/api/v1/admin/operations')->assertJsonPath('data.workers.0.status', 'recent')
            ->assertJsonPath('data.workers.0.age_seconds', 0);
    }

    public function test_future_timestamp_does_not_report_worker_as_recent(): void
    {
        $this->authenticateAdmin();
        $this->freezeTime();
        Event::dispatch(new Looping('database', 'sales'));
        $this->travel(-1)->seconds();

        $this->getJson('/api/v1/admin/operations')->assertJsonPath('data.workers.0.status', 'unknown');
    }

    public function test_process_local_cache_cannot_report_shared_activity(): void
    {
        $this->authenticateAdmin();
        config(['cache.default' => 'array']);
        Event::dispatch(new Looping('database', 'sales'));

        $this->getJson('/api/v1/admin/operations')->assertJsonPath('data.scheduler.status', 'unavailable')
            ->assertJsonPath('data.workers.0.status', 'unavailable');
    }

    public function test_sync_queue_cannot_report_an_independent_worker(): void
    {
        $this->authenticateAdmin();
        config(['queue.default' => 'sync']);
        Event::dispatch(new Looping('sync', 'sales'));

        $this->getJson('/api/v1/admin/operations')->assertJsonPath('data.workers.0.status', 'unavailable');
    }

    public function test_monitoring_cache_failure_does_not_interrupt_worker_event_or_expose_exception(): void
    {
        $this->authenticateAdmin();
        Cache::shouldReceive('put')->once()->andThrow(new RuntimeException('secret-storage-password'));
        Cache::shouldReceive('get')->times(5)->andThrow(new RuntimeException('secret-storage-password'));

        Event::dispatch(new Looping('database', 'sales'));

        $response = $this->getJson('/api/v1/admin/operations');
        $response->assertOk()->assertJsonPath('data.scheduler.status', 'unavailable')
            ->assertJsonPath('data.workers.0.status', 'unavailable');
        $this->assertStringNotContainsString('secret-storage-password', $response->getContent());
    }

    public function test_failed_job_list_is_paginated_and_never_exposes_payload_or_exception(): void
    {
        $this->authenticateAdmin();
        for ($index = 1; $index <= 26; $index++) {
            DB::table('failed_jobs')->insert(['uuid' => 'failure-'.$index, 'connection' => 'private-connection',
                'queue' => 'private-queue', 'payload' => '{"password":"card-secret"}',
                'exception' => 'provider-password stack-trace', 'failed_at' => '2026-10-09 00:00:00']);
        }

        $response = $this->getJson('/api/v1/admin/operations/failed-jobs');

        $response->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', 'failure-26');
        $this->assertSame(['id', 'failed_at'], array_keys($response->json('data.0')));
        foreach (['private-connection', 'private-queue', 'card-secret', 'provider-password', 'stack-trace'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->getJson('/api/v1/admin/operations/failed-jobs?page=2')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/operations')->assertJsonPath('data.failed_jobs.count', 26);
        $this->assertDatabaseCount('failed_jobs', 26);
    }

    public function test_disabled_failure_storage_is_unavailable_and_not_reported_as_zero(): void
    {
        $this->authenticateAdmin();
        config(['queue.failed.driver' => 'null']);

        $this->getJson('/api/v1/admin/operations')->assertJsonPath('data.failed_jobs', ['status' => 'unavailable', 'count' => null]);
        $this->getJson('/api/v1/admin/operations/failed-jobs')->assertServiceUnavailable();
    }

    public function test_failed_job_list_rejects_invalid_page_with_422(): void
    {
        $this->authenticateAdmin();

        $this->getJson('/api/v1/admin/operations/failed-jobs?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    private function authenticateAdmin(): void
    {
        config(['cache.default' => 'database', 'queue.default' => 'database', 'queue.failed.driver' => 'database-uuids']);
        $admin = User::factory()->create(['role' => UserRole::ADMIN, 'status' => 'active']);
        $this->withToken($admin->createToken('admin', ['account', 'admin'])->plainTextToken);
    }
}
