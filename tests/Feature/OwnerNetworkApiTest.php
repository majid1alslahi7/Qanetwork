<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
