<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\User;
use App\Services\Networks\ManageNetworkService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminNetworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_inactive_network_with_audit_and_explicit_public_fields(): void
    {
        $admin = $this->admin();
        $owner = $this->owner();
        $response = $this->postJson('/api/v1/admin/networks', $this->payload($owner));

        $response->assertCreated()->assertJsonPath('data.country_code', 'SA')
            ->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.sales_enabled', false)
            ->assertJsonPath('data.health_status', 'unknown');
        $network = Network::query()->firstOrFail();
        $this->assertSame('Internal note', $network->notes);
        $this->assertStringNotContainsString('Internal note', $response->getContent());
        $this->assertSame('Asia/Riyadh', $network->timezone);
        $event = AuditEvent::query()->firstOrFail();
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame('network.created', $event->event_type);
        $this->assertSame($network->id, $event->subject_id);
    }

    public function test_admin_can_list_and_show_network_without_provider_credentials(): void
    {
        $this->admin();
        $network = $this->network();
        $connection = $network->connections()->create([
            'name' => 'Secret connection', 'driver' => 'mikrotik_hotspot',
        ]);
        $connection->setCredentials(['username' => 'router-user', 'password' => 'router-secret']);
        $connection->save();

        $response = $this->getJson('/api/v1/admin/networks/'.$network->id);
        $response->assertOk()->assertJsonPath('data.id', $network->id);
        $this->assertArrayNotHasKey('connections', $response->json('data'));
        $this->assertStringNotContainsString('router-secret', $response->getContent());
        $this->getJson('/api/v1/admin/networks')->assertOk()
            ->assertJsonPath('meta.per_page', 25)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/networks?page=0')->assertUnprocessable();
        $this->getJson('/api/v1/admin/networks/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
    }

    public function test_suspension_disables_sales_and_reactivation_does_not_enable_sales(): void
    {
        $this->admin();
        $network = $this->network();
        $network->status = 'active';
        $network->sales_enabled = true;
        $network->save();

        $this->patchJson('/api/v1/admin/networks/'.$network->id.'/status', ['status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.sales_enabled', false);
        $this->assertNotNull($network->fresh()->suspended_at);
        $this->patchJson('/api/v1/admin/networks/'.$network->id.'/status', ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.sales_enabled', false);
        $this->assertNull($network->fresh()->suspended_at);
        $this->assertNotNull($network->fresh()->activated_at);
        $this->assertDatabaseCount('audit_events', 2);
        $this->patchJson('/api/v1/admin/networks/'.$network->id.'/status', ['status' => 'active'])->assertOk();
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_inactive_owner_cannot_receive_new_network_or_activate_existing_network(): void
    {
        $this->admin();
        $network = $this->network();
        $owner = $network->owner()->firstOrFail();
        $owner->status = 'suspended';
        $owner->save();

        $this->postJson('/api/v1/admin/networks', $this->payload($owner))
            ->assertUnprocessable()->assertJsonValidationErrors('network_owner_id');
        $this->patchJson('/api/v1/admin/networks/'.$network->id.'/status', ['status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('network_owner_id');
        $this->assertDatabaseCount('networks', 1);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertSame('inactive', $network->fresh()->status);
    }

    public function test_suspended_owner_account_cannot_receive_network(): void
    {
        $this->admin();
        $owner = $this->owner();
        $ownerUser = User::factory()->create(['role' => UserRole::NETWORK_OWNER, 'status' => 'suspended']);
        $owner->user_id = $ownerUser->id;
        $owner->save();
        $this->postJson('/api/v1/admin/networks', $this->payload($owner))->assertUnprocessable();
        $this->assertDatabaseCount('networks', 0);
    }

    public function test_validation_rejects_unknown_owner_invalid_location_and_state_injection(): void
    {
        $this->admin();
        $payload = $this->payload($this->owner());
        $payload['network_owner_id'] = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $payload['country_code'] = 'invalid';
        $payload['timezone'] = 'Invalid/Zone';
        $payload['sales_enabled'] = true;
        $payload['health_status'] = 'healthy';
        $payload['currency_code'] = 'BTC';
        $this->postJson('/api/v1/admin/networks', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['network_owner_id', 'country_code', 'timezone', 'sales_enabled', 'health_status', 'currency_code']);
        $this->assertDatabaseCount('networks', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_status_change_cannot_change_currency_or_inject_sales_and_health_state(): void
    {
        $this->admin();
        $network = $this->network();
        $this->patchJson('/api/v1/admin/networks/'.$network->id.'/status', [
            'status' => 'active', 'currency_code' => 'USD', 'sales_enabled' => true, 'health_status' => 'healthy',
        ])->assertUnprocessable()->assertJsonValidationErrors(['currency_code', 'sales_enabled', 'health_status']);
        $this->assertSame('YER', $network->fresh()->currency_code);
        $this->assertSame('inactive', $network->fresh()->status);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_owner_cannot_manage_networks_even_with_admin_token_ability(): void
    {
        $owner = $this->owner();
        $user = User::factory()->create(['role' => UserRole::NETWORK_OWNER]);
        $owner->user_id = $user->id;
        $owner->save();
        Sanctum::actingAs($user, ['account', 'admin']);
        $this->getJson('/api/v1/admin/networks')->assertForbidden();
        $this->postJson('/api/v1/admin/networks', $this->payload($owner))->assertForbidden();
        $this->assertDatabaseCount('networks', 0);
    }

    public function test_admin_requires_admin_token_scope(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        Sanctum::actingAs($admin, ['account']);
        $this->getJson('/api/v1/admin/networks')->assertForbidden();
    }

    public function test_stale_suspended_admin_cannot_create_network_through_service(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $owner = $this->owner();
        User::query()->whereKey($admin->id)->update(['status' => 'suspended']);
        $this->expectException(AuthorizationException::class);
        try {
            app(ManageNetworkService::class)->create($admin, $this->payload($owner));
        } finally {
            $this->assertDatabaseCount('networks', 0);
            $this->assertDatabaseCount('audit_events', 0);
        }
    }

    public function test_unauthenticated_request_cannot_list_networks(): void
    {
        $this->getJson('/api/v1/admin/networks')->assertUnauthorized();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        Sanctum::actingAs($admin, ['account', 'admin']);

        return $admin;
    }

    private function owner(): NetworkOwner
    {
        $owner = new NetworkOwner(['code' => 'OWN-'.Str::ulid(), 'name' => 'Network Owner']);
        $owner->status = 'active';
        $owner->save();

        return $owner;
    }

    private function network(): Network
    {
        return Network::query()->create([
            'network_owner_id' => $this->owner()->id, 'code' => 'network',
            'name' => 'Network', 'currency_code' => 'YER',
        ]);
    }

    private function payload(NetworkOwner $owner): array
    {
        return [
            'network_owner_id' => $owner->id, 'name' => 'New Network',
            'country_code' => 'SA', 'timezone' => 'Asia/Riyadh', 'currency_code' => 'SAR',
            'notes' => 'Internal note',
        ];
    }
}
