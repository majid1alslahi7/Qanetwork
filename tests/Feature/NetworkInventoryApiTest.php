<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InventoryCard;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\NetworkProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NetworkInventoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_imports_mixed_credentials_without_trimming_and_reads_only_stock_metadata(): void
    {
        [$user, $network, $product] = $this->catalog();
        Sanctum::actingAs($user, ['account', 'network_owner']);
        $path = $this->path($network, $product);
        $this->postJson($path, ['cards' => [['username' => '00123'], ['username' => 'user-card', 'password' => ' secret with spaces ']]])
            ->assertCreated()->assertJsonPath('data.imported_count', 2)->assertJsonPath('data.product_id', $product->id);
        $credentials = InventoryCard::query()->get()->map(fn (InventoryCard $card) => $card->credentials_encrypted)->all();
        $this->assertContains(['username' => '00123', 'login_mode' => 'username_only'], $credentials);
        $this->assertContains(['username' => 'user-card', 'password' => ' secret with spaces '], $credentials);
        $response = $this->getJson($path)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('summary.available', 2);
        foreach (['00123', 'user-card', 'secret with spaces', 'credentials', 'fingerprint'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->postJson($path, ['cards' => [['username' => 'fresh'], ['username' => '00123', 'password' => 'changed']]])->assertUnprocessable();
        $this->assertDatabaseCount('inventory_cards', 2);
    }

    public function test_csv_upload_preserves_leading_zeroes_and_extra_columns_are_rejected_atomically(): void
    {
        [$user, $network, $product] = $this->catalog();
        Sanctum::actingAs($user, ['account', 'network_owner']);
        $path = $this->path($network, $product);
        $this->post($path, ['file' => UploadedFile::fake()->createWithContent('cards.csv', "username,password\n0009,0004\n")], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.imported_count', 1);
        $this->assertSame(['username' => '0009', 'password' => '0004'], InventoryCard::query()->sole()->credentials_encrypted);
        $this->post($path, ['file' => UploadedFile::fake()->createWithContent('cards.csv', "username,password,price\nnew,p,100\n")], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('inventory_cards', 1);
    }

    public function test_inventory_requires_authentication_ability_and_owned_product(): void
    {
        [$user, $network, $product] = $this->catalog();
        $path = $this->path($network, $product);
        $this->getJson($path)->assertUnauthorized();
        Sanctum::actingAs($user, ['account']);
        $this->postJson($path, ['cards' => [['username' => '001']]])->assertForbidden();
        Sanctum::actingAs($user, ['account', 'network_owner']);
        [, $foreign, $foreignProduct] = $this->catalog();
        $this->getJson($this->path($foreign, $foreignProduct))->assertNotFound();
        $this->postJson($this->path($foreign, $foreignProduct), ['cards' => [['username' => '001']]])->assertNotFound();
        $this->postJson($this->path($network, $foreignProduct), ['cards' => [['username' => '001']]])->assertNotFound();
        $this->assertDatabaseCount('inventory_cards', 0);
    }

    /** @return array{User, Network, NetworkProduct} */
    private function catalog(): array
    {
        $user = User::factory()->create(['role' => UserRole::NETWORK_OWNER]);
        $owner = new NetworkOwner(['code' => 'OWN-'.Str::ulid(), 'name' => 'Inventory owner']);
        $owner->user_id = $user->id;
        $owner->status = 'active';
        $owner->save();
        $network = $owner->networks()->create(['code' => 'NET-'.Str::ulid(), 'name' => 'Inventory network', 'currency_code' => 'YER']);
        $product = $network->products()->create(['code' => 'PRD-'.Str::ulid(), 'name' => 'Day', 'external_product_id' => 'day', 'face_value' => '100', 'currency_code' => 'YER']);

        return [$user, $network, $product];
    }

    private function path(Network $network, NetworkProduct $product): string
    {
        return '/api/v1/owner/networks/'.$network->id.'/products/'.$product->id.'/inventory';
    }
}
