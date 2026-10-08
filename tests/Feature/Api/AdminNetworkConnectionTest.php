<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Network;
use App\Models\NetworkConnection;
use App\Models\NetworkOwner;
use App\Models\User;
use App\Providers\MikroTik\RouterOsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminNetworkConnectionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('drivers')]
    public function test_admin_stores_encrypted_connection_without_contacting_router(string $driver): void
    {
        $this->admin();
        $this->mock(RouterOsClient::class)->shouldNotReceive('execute');
        $network = $this->network();
        $payload = $this->payload();
        $payload['driver'] = $driver;
        $response = $this->postJson($this->url($network), $payload);
        $response->assertCreated()->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.is_primary', false)->assertJsonPath('data.config.tls', true)
            ->assertJsonPath('data.config.port', 8729)->assertJsonPath('data.connect_timeout', 5);
        $connection = NetworkConnection::query()->firstOrFail();
        $this->assertSame(' secret password ', $connection->credentials()['password']);
        $encrypted = DB::table('network_connections')->value('credentials_encrypted');
        $this->assertStringNotContainsString('secret password', $encrypted);
        $this->assertStringNotContainsString('router-user', $encrypted);
        $this->assertStringNotContainsString('secret password', $response->getContent());
        $this->assertStringNotContainsString('router-user', $response->getContent());
        $this->assertStringNotContainsString('secret password', AuditEvent::query()->firstOrFail()->toJson());
        $this->assertSame('connection.created', AuditEvent::query()->firstOrFail()->event_type);
    }

    public static function drivers(): array
    {
        return [['mikrotik_hotspot'], ['mikrotik_user_manager']];
    }

    public function test_explicit_plain_connection_and_numeric_inputs_are_normalized(): void
    {
        $this->admin();
        $network = $this->network();
        $payload = $this->payload();
        $payload['config']['tls'] = '0';
        $payload['config']['port'] = '8728';
        $payload['request_timeout'] = '20';
        $this->postJson($this->url($network), $payload)->assertCreated()
            ->assertJsonPath('data.config.tls', false)->assertJsonPath('data.config.port', 8728)
            ->assertJsonPath('data.request_timeout', 20);
    }

    public function test_only_one_primary_connection_and_switching_disables_sales(): void
    {
        $this->admin();
        $network = $this->network();
        $first = $this->createConnection($network);
        $second = $this->createConnection($network);
        $this->patchJson($this->url($network).'/'.$first->id.'/status', ['is_enabled' => true, 'is_primary' => true])->assertOk();
        $network->sales_enabled = true;
        $network->save();
        $this->patchJson($this->url($network).'/'.$second->id.'/status', ['is_enabled' => true, 'is_primary' => true])->assertOk();
        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertFalse($network->fresh()->sales_enabled);
        $this->assertSame(1, $network->connections()->where('is_primary', true)->count());
        $this->assertDatabaseCount('audit_events', 6);
        $this->patchJson($this->url($network).'/'.$second->id.'/status', ['is_enabled' => true, 'is_primary' => true])->assertOk();
        $this->assertDatabaseCount('audit_events', 6);
        $this->patchJson($this->url($network).'/'.$second->id.'/status', ['is_enabled' => false, 'is_primary' => false])->assertOk();
        $this->assertFalse($second->fresh()->is_enabled);
    }

    public function test_connection_cannot_be_updated_through_another_network(): void
    {
        $this->admin();
        $first = $this->network();
        $other = $this->network();
        $connection = $this->createConnection($first);
        $this->patchJson($this->url($other).'/'.$connection->id.'/status', ['is_enabled' => true, 'is_primary' => true])->assertNotFound();
        $this->assertFalse($connection->fresh()->is_enabled);
        $this->getJson($this->url($other))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url($first))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_invalid_host_and_unsafe_config_do_not_persist_credentials(): void
    {
        $this->admin();
        $network = $this->network();
        $payload = $this->payload();
        $payload['config']['host'] = 'https://router.example.test/path';
        $this->postJson($this->url($network), $payload)->assertUnprocessable()->assertJsonValidationErrors('config');
        $payload = $this->payload();
        $payload['config']['password'] = 'accidental-secret';
        $payload['config']['verify_peer'] = false;
        $this->postJson($this->url($network), $payload)->assertUnprocessable()->assertJsonValidationErrors('config');
        $this->assertDatabaseCount('network_connections', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_creation_validates_driver_credentials_bounds_and_initial_state(): void
    {
        $this->admin();
        $network = $this->network();
        $payload = $this->payload();
        $payload['driver'] = 'unknown';
        $payload['credentials']['password'] = '';
        $payload['config']['port'] = 70000;
        $payload['request_timeout'] = 121;
        $payload['is_enabled'] = true;
        $this->postJson($this->url($network), $payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['driver', 'credentials.password', 'config.port', 'request_timeout', 'is_enabled']);
        $this->assertDatabaseCount('network_connections', 0);
    }

    public function test_disabled_primary_and_endpoint_changes_are_rejected(): void
    {
        $this->admin();
        $network = $this->network();
        $connection = $this->createConnection($network);
        $url = $this->url($network).'/'.$connection->id.'/status';
        $this->patchJson($url, ['is_enabled' => false, 'is_primary' => true])->assertUnprocessable()->assertJsonValidationErrors('is_primary');
        $this->patchJson($url, ['is_enabled' => true, 'is_primary' => false, 'credentials' => ['password' => 'changed']])
            ->assertUnprocessable()->assertJsonValidationErrors('credentials');
        $this->assertFalse($connection->fresh()->is_enabled);
        $this->assertSame(' secret password ', $connection->fresh()->credentials()['password']);
    }

    public function test_corrupt_credentials_cannot_enable_connection_and_are_not_returned(): void
    {
        $this->admin();
        $network = $this->network();
        $connection = $this->createConnection($network);
        DB::table('network_connections')->where('id', $connection->id)->update(['credentials_encrypted' => 'broken-secret']);
        $response = $this->patchJson($this->url($network).'/'.$connection->id.'/status', ['is_enabled' => true, 'is_primary' => false]);
        $response->assertUnprocessable();
        $this->assertStringNotContainsString('broken-secret', $response->getContent());
        $this->assertFalse($connection->fresh()->is_enabled);
    }

    public function test_non_admin_and_unscoped_admin_cannot_manage_connections(): void
    {
        $network = $this->network();
        $owner = $network->owner()->firstOrFail();
        $user = User::factory()->create(['role' => UserRole::NETWORK_OWNER]);
        $owner->user_id = $user->id;
        $owner->status = 'active';
        $owner->save();
        Sanctum::actingAs($user, ['account', 'admin']);
        $this->postJson($this->url($network), $this->payload())->assertForbidden();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        Sanctum::actingAs($admin, ['account']);
        $this->getJson($this->url($network))->assertForbidden();
        $this->assertDatabaseCount('network_connections', 0);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson($this->url($this->network()))->assertUnauthorized();
    }

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account', 'admin']);
    }

    private function network(): Network
    {
        $owner = NetworkOwner::query()->create(['code' => 'OWN-'.Str::ulid(), 'name' => 'Owner']);

        return Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'NET-'.Str::ulid(), 'name' => 'Network']);
    }

    private function createConnection(Network $network): NetworkConnection
    {
        $id = $this->postJson($this->url($network), $this->payload())->assertCreated()->json('data.id');

        return NetworkConnection::query()->findOrFail($id);
    }

    private function url(Network $network): string
    {
        return '/api/v1/admin/networks/'.$network->id.'/connections';
    }

    private function payload(): array
    {
        return ['name' => 'Router', 'driver' => 'mikrotik_hotspot', 'config' => ['host' => 'router.example.test'],
            'credentials' => ['username' => 'router-user', 'password' => ' secret password ']];
    }
}
