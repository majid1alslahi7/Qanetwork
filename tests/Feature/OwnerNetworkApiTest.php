<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\CheckNetworkConnectionHealthJob;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OwnerNetworkApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_reads_only_own_networks_and_safe_products_including_paused_records(): void
    {
        $owner = $this->owner();
        $own = $this->network($owner);
        $this->network($this->owner());
        $product = $own->products()->create(['code' => 'P1', 'name' => 'Own package', 'face_value' => '1000',
            'currency_code' => 'YER', 'external_product_id' => 'private-profile', 'metadata' => ['secret' => 'private-secret']]);
        Sanctum::actingAs($owner->user()->firstOrFail(), ['account', 'network_owner']);
        $this->getJson('/api/v1/owner/networks')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)->assertJsonPath('meta.per_page', 25);
        $this->getJson('/api/v1/owner/networks/'.$own->id)->assertOk()->assertJsonPath('data.id', $own->id);
        $response = $this->getJson('/api/v1/owner/networks/'.$own->id.'/products')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.face_value', '1000.0000');
        foreach (['metadata', 'external_product_id', 'private-secret', 'private-profile', 'pricing', 'connections', 'credentials'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_owner_cannot_read_another_owners_network_or_products(): void
    {
        $owner = $this->owner();
        $foreign = $this->network($this->owner());
        Sanctum::actingAs($owner->user()->firstOrFail(), ['account', 'network_owner']);
        $this->getJson('/api/v1/owner/networks/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/v1/owner/networks/'.$foreign->id.'/products')->assertNotFound();
        $this->getJson('/api/v1/owner/networks?network_owner_id='.$foreign->network_owner_id)
            ->assertUnprocessable()->assertJsonValidationErrors('network_owner_id');
        $this->getJson('/api/v1/owner/networks?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    #[TestWith(['admin'])]
    #[TestWith(['seller'])]
    public function test_other_roles_cannot_use_owner_endpoints(string $role): void
    {
        $network = $this->network($this->owner());
        $user = User::factory()->create(['role' => UserRole::from($role)]);
        if ($role === 'seller') {
            $seller = new Seller(['code' => 'SEL-'.Str::ulid()]);
            $seller->user_id = $user->id;
            $seller->status = 'active';
            $seller->save();
        }
        Sanctum::actingAs($user, ['account', $role]);
        $this->getJson('/api/v1/owner/networks')->assertForbidden();
        $this->getJson('/api/v1/owner/networks/'.$network->id)->assertForbidden();
        $this->getJson('/api/v1/owner/networks/'.$network->id.'/products')->assertForbidden();
    }

    public function test_missing_authentication_and_owner_token_ability_are_rejected(): void
    {
        $this->getJson('/api/v1/owner/networks')->assertUnauthorized();
        $owner = $this->owner();
        Sanctum::actingAs($owner->user()->firstOrFail(), ['account']);
        $this->getJson('/api/v1/owner/networks')->assertForbidden();
    }

    public function test_owner_creates_own_network_packages_prices_and_inventory_connection(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner->user()->firstOrFail(), ['account', 'network_owner']);
        $response = $this->postJson('/api/v1/owner/networks', ['name' => 'New owner network', 'currency_code' => 'YER'])
            ->assertCreated()->assertJsonPath('data.sales_enabled', false);
        $networkId = $response->json('data.id');
        $this->assertDatabaseHas('networks', ['id' => $networkId, 'network_owner_id' => $owner->id, 'status' => 'inactive']);
        $this->postJson('/api/v1/owner/networks/'.$networkId.'/products', ['name' => 'Daily card', 'face_value' => '250.5000', 'external_product_id' => 'day-stock'])
            ->assertCreated()->assertJsonPath('data.face_value', '250.5000');
        $this->postJson('/api/v1/owner/networks/'.$networkId.'/connections', ['name' => 'Stored inventory', 'driver' => 'stored_cards'])
            ->assertCreated()->assertJsonPath('data.driver', 'stored_cards')->assertJsonPath('data.is_enabled', false);
        $this->postJson('/api/v1/owner/networks', ['name' => 'Spoofed owner', 'currency_code' => 'YER', 'network_owner_id' => $this->owner()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('network_owner_id');
        $foreign = $this->network($this->owner());
        $this->postJson('/api/v1/owner/networks/'.$foreign->id.'/products', ['name' => 'Foreign', 'face_value' => '100', 'external_product_id' => 'foreign'])->assertNotFound();
        $this->postJson('/api/v1/owner/networks/'.$foreign->id.'/connections', ['name' => 'Foreign', 'driver' => 'stored_cards'])->assertNotFound();
    }

    public function test_owner_manages_own_product_and_connection_status_and_queues_health_without_cross_tenant_access(): void
    {
        Queue::fake();
        $owner = $this->owner();
        $network = $this->network($owner);
        Sanctum::actingAs($owner->user()->firstOrFail(), ['account', 'network_owner']);
        $product = $network->products()->create(['code' => 'manage-own', 'name' => 'Own', 'external_product_id' => 'own', 'face_value' => '100', 'currency_code' => 'YER']);
        $connection = $network->connections()->create(['name' => 'Inventory', 'driver' => 'stored_cards', 'is_enabled' => false]);
        $base = '/api/v1/owner/networks/'.$network->id;
        $this->patchJson($base.'/products/'.$product->id.'/status', ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->patchJson($base.'/connections/'.$connection->id.'/status', ['is_enabled' => true, 'is_primary' => true])->assertOk()->assertJsonPath('data.is_enabled', true);
        $this->postJson($base.'/connections/'.$connection->id.'/health')->assertAccepted();
        Queue::assertPushed(CheckNetworkConnectionHealthJob::class, fn ($job) => $job->connectionId === $connection->id);
        $foreign = $this->network($this->owner());
        $foreignProduct = $foreign->products()->create(['code' => 'foreign-product', 'name' => 'Foreign', 'external_product_id' => 'foreign', 'face_value' => '100', 'currency_code' => 'YER']);
        $this->patchJson('/api/v1/owner/networks/'.$foreign->id.'/products/'.$foreignProduct->id.'/status', ['status' => 'active'])->assertNotFound();
        $this->patchJson($base.'/products/'.$foreignProduct->id.'/status', ['status' => 'active'])->assertNotFound();
        $this->assertFalse($network->fresh()->sales_enabled);
    }

    private function owner(): NetworkOwner
    {
        $owner = new NetworkOwner(['code' => 'OWN-'.Str::ulid(), 'name' => 'Owner']);
        $owner->user_id = User::factory()->create(['role' => UserRole::NETWORK_OWNER])->id;
        $owner->status = 'active';
        $owner->save();

        return $owner;
    }

    private function network(NetworkOwner $owner): Network
    {
        return $owner->networks()->create(['code' => 'NET-'.Str::ulid(), 'name' => 'Own network', 'currency_code' => 'YER']);
    }
}
