<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\NetworkProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminNetworkProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_product_with_exact_price_network_currency_and_explicit_limits(): void
    {
        $this->admin();
        $network = $this->network();
        $payload = $this->payload();
        $payload['metadata'] = ['hotspot' => ['limit_bytes_total' => '1073741824', 'limit_uptime_seconds' => '86400']];
        $response = $this->postJson($this->url($network), $payload);
        $response->assertCreated()->assertJsonPath('data.face_value', '1000.1250')
            ->assertJsonPath('data.currency_code', 'SAR')->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.metadata.hotspot.limit_bytes_total', 1073741824)
            ->assertJsonPath('data.metadata.hotspot.limit_uptime_seconds', 86400);
        $product = NetworkProduct::query()->firstOrFail();
        $this->assertSame($network->id, $product->network_id);
        $this->assertSame('unknown', $product->availability_status);
        $this->assertNull($product->available_quantity);
        $event = AuditEvent::query()->firstOrFail();
        $this->assertSame('product.created', $event->event_type);
        $this->assertSame($product->id, $event->subject_id);
        $this->assertSame('1000.1250', $event->after['face_value']);
        $this->assertDatabaseCount('sold_cards', 0);
    }

    #[DataProvider('invalidPrices')]
    public function test_invalid_money_is_rejected(mixed $price): void
    {
        $this->admin();
        $payload = $this->payload();
        $payload['face_value'] = $price;
        $this->postJson($this->url($this->network()), $payload)->assertUnprocessable()->assertJsonValidationErrors('face_value');
        $this->assertDatabaseCount('network_products', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public static function invalidPrices(): array
    {
        return [['0'], ['-1'], ['0.00001'], ['1e3'], ['10000000000000000'], [1000.125], ['1000,25']];
    }

    public function test_minimum_positive_price_is_preserved(): void
    {
        $this->admin();
        $payload = $this->payload();
        $payload['face_value'] = '0.0001';
        $this->postJson($this->url($this->network()), $payload)->assertCreated()->assertJsonPath('data.face_value', '0.0001');
    }

    public function test_provider_id_unique_per_network_and_reusable_in_other_network(): void
    {
        $this->admin();
        $first = $this->network();
        $this->postJson($this->url($first), $this->payload())->assertCreated();
        $this->postJson($this->url($first), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('external_product_id');
        $this->postJson($this->url($this->network()), $this->payload())->assertCreated();
        $this->assertDatabaseCount('network_products', 2);
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_hotspot_without_limits_requires_explicit_unlimited_selection(): void
    {
        $this->admin();
        $network = $this->network();
        $payload = $this->payload();
        $payload['metadata'] = ['hotspot' => ['server' => 'all']];
        $this->postJson($this->url($network), $payload)->assertUnprocessable()->assertJsonValidationErrors('metadata.hotspot');
        $payload['metadata']['hotspot']['allow_unlimited'] = '1';
        $this->postJson($this->url($network), $payload)->assertCreated()->assertJsonPath('data.metadata.hotspot.allow_unlimited', true);
    }

    public function test_unsafe_metadata_and_state_injection_are_rejected(): void
    {
        $this->admin();
        $payload = $this->payload();
        $payload['metadata'] = ['password' => 'secret'];
        $payload['currency_code'] = 'USD';
        $payload['status'] = 'active';
        $payload['available_quantity'] = 100;
        $this->postJson($this->url($this->network()), $payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['metadata', 'currency_code', 'status', 'available_quantity']);
        $this->assertDatabaseCount('network_products', 0);
    }

    public function test_products_list_is_scoped_and_foreign_product_status_cannot_be_changed(): void
    {
        $this->admin();
        $first = $this->network();
        $other = $this->network();
        $id = $this->postJson($this->url($first), $this->payload())->assertCreated()->json('data.id');
        $this->getJson($this->url($first))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 25);
        $this->getJson($this->url($other))->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson($this->url($other).'/'.$id.'/status', ['status' => 'active'])->assertNotFound();
        $this->assertSame('inactive', NetworkProduct::query()->findOrFail($id)->status);
    }

    public function test_status_changes_are_audited_idempotent_and_cannot_change_product_identity(): void
    {
        $this->admin();
        $network = $this->network();
        $id = $this->postJson($this->url($network), $this->payload())->assertCreated()->json('data.id');
        $url = $this->url($network).'/'.$id.'/status';
        $this->patchJson($url, ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->patchJson($url, ['status' => 'active'])->assertOk();
        $this->assertDatabaseCount('audit_events', 2);
        $this->patchJson($url, ['status' => 'archived'])->assertOk();
        $this->patchJson($url, ['status' => 'active', 'face_value' => '2000', 'external_product_id' => 'new-profile'])
            ->assertUnprocessable()->assertJsonValidationErrors(['face_value', 'external_product_id']);
        $this->assertSame('archived', NetworkProduct::query()->findOrFail($id)->status);
        $this->assertDatabaseCount('audit_events', 3);
    }

    public function test_owner_with_admin_scope_cannot_create_product(): void
    {
        $network = $this->network();
        $owner = $network->owner()->firstOrFail();
        $user = User::factory()->create(['role' => UserRole::NETWORK_OWNER]);
        $owner->user_id = $user->id;
        $owner->status = 'active';
        $owner->save();
        Sanctum::actingAs($user, ['account', 'admin']);
        $this->postJson($this->url($network), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('network_products', 0);
    }

    public function test_unauthenticated_and_unscoped_admin_are_rejected(): void
    {
        $network = $this->network();
        $this->getJson($this->url($network))->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account']);
        $this->postJson($this->url($network), $this->payload())->assertForbidden();
    }

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account', 'admin']);
    }

    private function network(): Network
    {
        $owner = NetworkOwner::query()->create(['code' => 'OWN-'.Str::ulid(), 'name' => 'Owner']);

        return Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'NET-'.Str::ulid(), 'name' => 'Network', 'currency_code' => 'SAR']);
    }

    private function url(Network $network): string
    {
        return '/api/v1/admin/networks/'.$network->id.'/products';
    }

    private function payload(): array
    {
        return ['name' => 'Day Card', 'external_product_id' => 'day-profile', 'face_value' => '1000.125'];
    }
}
