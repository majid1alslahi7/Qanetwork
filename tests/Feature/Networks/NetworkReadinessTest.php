<?php

namespace Tests\Feature\Networks;

use App\Enums\UserRole;
use App\Jobs\CheckNetworkConnectionHealthJob;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\User;
use App\Providers\Contracts\ProviderAdapter;
use App\Providers\Registry\ProviderAdapterRegistry;
use App\Services\Networks\CheckNetworkConnectionHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class NetworkReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_updates_health_but_admin_must_explicitly_enable_sales(): void
    {
        [$network, $connection] = $this->setupNetwork();
        $this->adapter()->shouldReceive('healthCheck')->once()->andReturn(true);
        app(CheckNetworkConnectionHealthService::class)->handle($connection);
        $this->assertSame('healthy', $connection->fresh()->health_status);
        $this->assertSame('healthy', $network->fresh()->health_status);
        $this->assertFalse($network->fresh()->sales_enabled);
        $this->assertNotNull($connection->fresh()->last_success_at);
        $this->patchJson('/api/v1/admin/networks/'.$network->id.'/sales', ['sales_enabled' => true])->assertOk()->assertJsonPath('data.sales_enabled', true);
        $this->patchJson('/api/v1/admin/networks/'.$network->id.'/sales', ['sales_enabled' => true])->assertOk();
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_failure_disables_sales_counts_failures_and_hides_provider_error(): void
    {
        [$network, $connection] = $this->setupNetwork();
        $network->sales_enabled = true;
        $network->save();
        $this->adapter()->shouldReceive('healthCheck')->once()->andThrow(new RuntimeException('router password secret'));
        app(CheckNetworkConnectionHealthService::class)->handle($connection);
        $this->assertFalse($network->fresh()->sales_enabled);
        $this->assertSame('unhealthy', $network->fresh()->health_status);
        $this->assertSame(1, $connection->fresh()->consecutive_failures);
        $this->assertSame('Provider health check failed.', $connection->fresh()->last_error);
        $this->assertNotNull($connection->fresh()->last_failure_at);
        $this->adapter()->shouldReceive('healthCheck')->once()->andReturn(true);
        app(CheckNetworkConnectionHealthService::class)->handle($connection);
        $this->assertSame(0, $connection->fresh()->consecutive_failures);
        $this->assertFalse($network->fresh()->sales_enabled);
    }

    public function test_settings_changed_during_check_discard_old_result(): void
    {
        [$network, $connection] = $this->setupNetwork();
        $this->adapter()->shouldReceive('healthCheck')->once()->andReturnUsing(function () use ($connection): bool {
            $connection->config = ['host' => 'new-router.example.test'];
            $connection->save();

            return true;
        });
        app(CheckNetworkConnectionHealthService::class)->handle($connection);
        $this->assertSame('unknown', $connection->fresh()->health_status);
        $this->assertNull($connection->fresh()->last_checked_at);
        $this->assertSame('unknown', $network->fresh()->health_status);
    }

    public function test_nonprimary_health_does_not_change_primary_network_health(): void
    {
        [$network, $connection] = $this->setupNetwork();
        $connection->is_primary = false;
        $connection->save();
        $this->adapter()->shouldReceive('healthCheck')->once()->andReturn(true);
        app(CheckNetworkConnectionHealthService::class)->handle($connection);
        $this->assertSame('healthy', $connection->fresh()->health_status);
        $this->assertSame('unknown', $network->fresh()->health_status);
    }

    public function test_disabled_connection_is_not_contacted(): void
    {
        [, $connection] = $this->setupNetwork();
        $connection->is_enabled = false;
        $connection->save();
        $this->adapter()->shouldNotReceive('healthCheck');
        app(CheckNetworkConnectionHealthService::class)->handle($connection);
        $this->assertNull($connection->fresh()->last_checked_at);
    }

    public function test_stale_health_or_suspended_owner_prevents_sales_but_pause_is_always_allowed(): void
    {
        [$network, $connection] = $this->setupNetwork();
        $this->adapter()->shouldReceive('healthCheck')->once()->andReturn(true);
        app(CheckNetworkConnectionHealthService::class)->handle($connection);
        $this->travel(6)->minutes();
        $this->patchJson('/api/v1/admin/networks/'.$network->id.'/sales', ['sales_enabled' => true])->assertUnprocessable();
        $this->travelBack();
        $owner = $network->owner()->firstOrFail();
        $owner->status = 'suspended';
        $owner->save();
        $this->patchJson('/api/v1/admin/networks/'.$network->id.'/sales', ['sales_enabled' => true])->assertUnprocessable();
        $network->sales_enabled = true;
        $network->save();
        $this->patchJson('/api/v1/admin/networks/'.$network->id.'/sales', ['sales_enabled' => false])->assertOk()->assertJsonPath('data.sales_enabled', false);
    }

    public function test_health_endpoint_queues_job_and_rejects_foreign_connection_or_disabled_connection(): void
    {
        Queue::fake();
        [$network, $connection] = $this->setupNetwork();
        $url = '/api/v1/admin/networks/'.$network->id.'/connections/'.$connection->id.'/health';
        $this->postJson($url)->assertAccepted();
        Queue::assertPushed(CheckNetworkConnectionHealthJob::class, fn ($job) => $job->connectionId === $connection->id && $job->queue === 'network-health');
        [$other] = $this->setupNetwork();
        $this->postJson('/api/v1/admin/networks/'.$other->id.'/connections/'.$connection->id.'/health')->assertNotFound();
        $connection->is_enabled = false;
        $connection->save();
        $this->postJson($url)->assertUnprocessable();
        Queue::assertPushed(CheckNetworkConnectionHealthJob::class, 1);
    }

    public function test_command_dispatches_due_primary_connections_only(): void
    {
        Queue::fake();
        [, $due] = $this->setupNetwork();
        [, $fresh] = $this->setupNetwork();
        $fresh->last_checked_at = now();
        $fresh->save();
        [, $disabled] = $this->setupNetwork();
        $disabled->is_enabled = false;
        $disabled->save();
        $this->artisan('networks:check-health')->assertSuccessful();
        Queue::assertPushed(CheckNetworkConnectionHealthJob::class, 1);
        Queue::assertPushed(CheckNetworkConnectionHealthJob::class, fn ($job) => $job->connectionId === $due->id);
    }

    public function test_missing_admin_scope_cannot_enable_sales_or_queue_check(): void
    {
        Queue::fake();
        [$network, $connection] = $this->setupNetwork();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account']);
        $this->patchJson('/api/v1/admin/networks/'.$network->id.'/sales', ['sales_enabled' => true])->assertForbidden();
        $this->postJson('/api/v1/admin/networks/'.$network->id.'/connections/'.$connection->id.'/health')->assertForbidden();
        Queue::assertNothingPushed();
    }

    private function adapter(): mixed
    {
        $adapter = $this->mock(ProviderAdapter::class);
        app(ProviderAdapterRegistry::class)->register('test-provider', $adapter);

        return $adapter;
    }

    private function setupNetwork(): array
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account', 'admin']);
        $owner = NetworkOwner::query()->create(['code' => 'OWN-'.Str::ulid(), 'name' => 'Owner']);
        $owner->status = 'active';
        $owner->save();
        $network = Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'NET-'.Str::ulid(), 'name' => 'Network']);
        $network->status = 'active';
        $network->save();
        $connection = $network->connections()->create(['name' => 'Primary', 'driver' => 'test-provider', 'is_enabled' => true, 'is_primary' => true]);

        return [$network, $connection];
    }
}
