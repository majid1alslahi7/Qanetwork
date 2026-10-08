<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Jobs\ProcessSaleJob;
use App\Models\CommissionRule;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\NetworkProduct;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Pricing\PublishPricingRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SellerCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_and_preview_show_seller_prices_without_internal_or_connection_data(): void
    {
        [$product, $wallet] = $this->scenario();
        Queue::fake();
        $this->getJson('/api/v1/seller/networks')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Network');
        $response = $this->getJson($this->url($product))->assertOk()->assertJsonPath('data.purchasable', true)
            ->assertJsonPath('data.pricing.seller_commission', '150.0000')->assertJsonPath('data.pricing.seller_net_amount', '850.0000');
        $this->getJson('/api/v1/seller/networks/'.$product->network_id.'/products')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(['pricing_rule_id', 'commission_rule_id', 'face_value', 'seller_commission', 'seller_net_amount', 'currency_code'], array_keys($response->json('data.pricing')));
        foreach (['provider_amount', 'platform_commission', 'internal-host', 'provider-secret', 'hotspot', 'metadata', 'external_product_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_reservations', 0);
        $this->assertSame('1000.0000', $wallet->fresh()->balance);
        Queue::assertNothingPushed();
    }

    public function test_seller_override_and_default_are_resolved_separately(): void
    {
        [$product, $wallet, $admin] = $this->scenario();
        $rule = $product->pricingRules()->where('is_active', true)->firstOrFail();
        $override = CommissionRule::query()->create(['pricing_rule_id' => $rule->id, 'seller_id' => $wallet->seller_id,
            'seller_commission' => '175', 'created_by' => $admin->id]);
        $this->getJson($this->url($product))->assertOk()->assertJsonPath('data.pricing.seller_commission', '175.0000')
            ->assertJsonPath('data.pricing.seller_net_amount', '825.0000')->assertJsonPath('data.pricing.commission_rule_id', $override->id);
        $other = User::factory()->create(['role' => UserRole::SELLER]);
        $seller = Seller::query()->create(['user_id' => $other->id, 'code' => 'other']);
        $seller->status = 'active';
        $seller->save();
        Sanctum::actingAs($other, ['account', 'seller']);
        $this->getJson($this->url($product))->assertOk()->assertJsonPath('data.pricing.seller_commission', '150.0000');
    }

    #[DataProvider('unavailableNetworks')]
    public function test_unavailable_network_is_hidden_and_cannot_sell(string $case): void
    {
        $this->freezeTime();
        [$product, $wallet] = $this->scenario();
        $network = $product->network()->firstOrFail();
        if ($case === 'paused') {
            $network->sales_enabled = false;
        } elseif ($case === 'inactive') {
            $network->status = 'inactive';
        } elseif ($case === 'stale') {
            $network->last_health_check_at = now()->subMinutes(6);
        } elseif ($case === 'owner') {
            $network->owner()->update(['status' => 'suspended']);
        } elseif ($case === 'connection') {
            $network->connections()->update(['last_checked_at' => now()->subMinutes(6)]);
        } else {
            $network->connections()->create(['name' => 'Duplicate', 'driver' => 'fake', 'is_enabled' => true, 'is_primary' => true]);
        }
        $network->save();
        $this->getJson('/api/v1/seller/networks')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url($product))->assertNotFound();
        $this->postJson('/api/v1/seller/sales', $this->purchase($product, $wallet))->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame('0.0000', $wallet->fresh()->reserved_balance);
    }

    public static function unavailableNetworks(): array
    {
        return [['paused'], ['inactive'], ['stale'], ['owner'], ['connection'], ['duplicate']];
    }

    public function test_owner_account_suspension_removes_network_from_catalog(): void
    {
        [$product] = $this->scenario();
        $owner = $product->network()->firstOrFail()->owner()->firstOrFail();
        $user = User::factory()->create(['role' => UserRole::NETWORK_OWNER, 'status' => 'suspended']);
        $owner->user_id = $user->id;
        $owner->save();
        $this->getJson('/api/v1/seller/networks')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url($product))->assertNotFound();
    }

    #[DataProvider('invalidPricingCases')]
    public function test_invalid_pricing_is_unpurchasable_without_internal_errors(string $case): void
    {
        [$product] = $this->scenario();
        $pricing = $product->pricingRules()->where('is_active', true)->firstOrFail();
        if ($case === 'missing') {
            $pricing->is_active = false;
            $pricing->save();
        } elseif ($case === 'face') {
            $product->face_value = '1100';
            $product->save();
        } elseif ($case === 'negative') {
            DB::table('commission_rules')->where('pricing_rule_id', $pricing->id)->update(['seller_commission' => '-1']);
        } elseif ($case === 'duplicate') {
            CommissionRule::query()->create(['pricing_rule_id' => $pricing->id, 'seller_commission' => '100', 'created_by' => $pricing->created_by]);
        } else {
            DB::table('commission_rules')->where('pricing_rule_id', $pricing->id)->update(['seller_commission' => '300']);
        }
        $this->getJson($this->url($product))->assertOk()->assertJsonPath('data.purchasable', false)
            ->assertJsonPath('data.pricing', null)->assertJsonPath('data.unavailable_reason', 'pricing_unavailable');
    }

    public static function invalidPricingCases(): array
    {
        return [['missing'], ['face'], ['negative'], ['duplicate'], ['overpriced']];
    }

    public function test_product_routes_are_scoped_and_inactive_products_are_hidden(): void
    {
        [$product] = $this->scenario();
        $other = Network::query()->create(['network_owner_id' => $product->network()->firstOrFail()->network_owner_id, 'code' => 'other', 'name' => 'Other']);
        $foreign = $other->products()->create(['code' => 'foreign', 'external_product_id' => 'foreign', 'name' => 'Foreign', 'face_value' => '10', 'currency_code' => 'YER']);
        $this->getJson('/api/v1/seller/networks/'.$product->network_id.'/products/'.$foreign->id)->assertNotFound();
        $product->status = 'inactive';
        $product->save();
        $this->getJson('/api/v1/seller/networks/'.$product->network_id.'/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url($product))->assertNotFound();
    }

    public function test_old_offer_rejects_changed_price_before_reserving_or_dispatching(): void
    {
        [$product, $wallet, $admin] = $this->scenario();
        Queue::fake();
        $offer = $this->getJson($this->url($product))->assertOk()->json('data.pricing');
        app(PublishPricingRuleService::class)->handle($admin, $product, '800', '125');
        $this->postJson('/api/v1/seller/sales', [...$this->purchase($product, $wallet),
            'expected_pricing_rule_id' => $offer['pricing_rule_id'], 'expected_commission_rule_id' => $offer['commission_rule_id']])->assertConflict();
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_financials', 0);
        $this->assertSame('0.0000', $wallet->fresh()->reserved_balance);
        Queue::assertNothingPushed();
    }

    public function test_old_offer_rejects_new_seller_commission_and_matching_offer_reserves_exactly(): void
    {
        [$product, $wallet, $admin] = $this->scenario();
        Queue::fake();
        $offer = $this->getJson($this->url($product))->assertOk()->json('data.pricing');
        CommissionRule::query()->create(['pricing_rule_id' => $offer['pricing_rule_id'], 'seller_id' => $wallet->seller_id,
            'seller_commission' => '175', 'created_by' => $admin->id]);
        $payload = [...$this->purchase($product, $wallet), 'expected_pricing_rule_id' => $offer['pricing_rule_id'],
            'expected_commission_rule_id' => $offer['commission_rule_id']];
        $this->postJson('/api/v1/seller/sales', $payload)->assertConflict();
        $offer = $this->getJson($this->url($product))->assertOk()->json('data.pricing');
        $payload['expected_commission_rule_id'] = $offer['commission_rule_id'];
        $id = $this->postJson('/api/v1/seller/sales', $payload)->assertAccepted()->assertJsonPath('data.financial.seller_net_amount', '825.0000')->json('data.id');
        $this->postJson('/api/v1/seller/sales', $payload)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame('825.0000', $wallet->fresh()->reserved_balance);
    }

    public function test_catalog_requires_seller_role_and_scope_and_valid_page(): void
    {
        [, $wallet] = $this->scenario();
        $this->getJson('/api/v1/seller/networks?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
        Sanctum::actingAs($wallet->seller()->firstOrFail()->user()->firstOrFail(), ['account']);
        $this->getJson('/api/v1/seller/networks')->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account', 'admin']);
        $this->getJson('/api/v1/seller/networks')->assertForbidden();
    }

    public function test_catalog_requires_authentication(): void
    {
        $this->getJson('/api/v1/seller/networks')->assertUnauthorized();
    }

    public function test_purchase_requires_both_offer_identifiers_when_either_is_supplied(): void
    {
        [$product, $wallet] = $this->scenario();
        $offer = $this->getJson($this->url($product))->assertOk()->json('data.pricing');
        $this->postJson('/api/v1/seller/sales', [...$this->purchase($product, $wallet),
            'expected_pricing_rule_id' => $offer['pricing_rule_id']])->assertUnprocessable()->assertJsonValidationErrors('expected_commission_rule_id');
        $this->postJson('/api/v1/seller/sales', [...$this->purchase($product, $wallet),
            'expected_commission_rule_id' => $offer['commission_rule_id']])->assertUnprocessable()->assertJsonValidationErrors('expected_pricing_rule_id');
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame('0.0000', $wallet->fresh()->reserved_balance);
    }

    public function test_api_checkout_reserves_the_reviewed_offer_without_issuing_another_token(): void
    {
        [$product, $wallet] = $this->scenario();
        Queue::fake();
        $offer = $this->getJson('/api/v1/seller/networks/'.$product->network_id.'/products/'.$product->id)
            ->assertOk()->json('data.pricing');
        $this->postJson('/api/v1/seller/sales', [...$this->purchase($product, $wallet),
            'expected_pricing_rule_id' => $offer['pricing_rule_id'], 'expected_commission_rule_id' => $offer['commission_rule_id']])
            ->assertAccepted()->assertJsonPath('data.financial.seller_net_amount', '850.0000');
        $this->assertSame('850.0000', $wallet->fresh()->reserved_balance);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Queue::assertPushed(ProcessSaleJob::class, 1);
    }

    public function test_seller_account_cannot_be_used_as_network_owner_for_sale_readiness(): void
    {
        [$product, $wallet] = $this->scenario();
        $owner = $product->network()->firstOrFail()->owner()->firstOrFail();
        $owner->user_id = $wallet->seller()->firstOrFail()->user_id;
        $owner->save();
        $this->getJson('/api/v1/seller/networks')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/seller/sales', $this->purchase($product, $wallet))->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->assertDatabaseCount('sales', 0);
    }

    private function scenario(): array
    {
        $user = User::factory()->create(['role' => UserRole::SELLER]);
        $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'seller']);
        $seller->status = 'active';
        $seller->save();
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => 'YER']);
        $wallet->balance = '1000';
        $wallet->save();
        $owner = NetworkOwner::query()->create(['code' => 'owner', 'name' => 'Owner']);
        $owner->status = 'active';
        $owner->save();
        $network = Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'network', 'name' => 'Network']);
        $network->status = 'active';
        $network->sales_enabled = true;
        $network->health_status = 'healthy';
        $network->last_health_check_at = now();
        $network->save();
        $connection = $network->connections()->create(['name' => 'Primary', 'driver' => 'test', 'host' => 'internal-host', 'is_enabled' => true, 'is_primary' => true]);
        $connection->setCredentials(['password' => 'provider-secret']);
        $connection->health_status = 'healthy';
        $connection->last_checked_at = now();
        $connection->save();
        $product = $network->products()->create(['code' => 'product', 'name' => 'Day', 'face_value' => '1000',
            'currency_code' => 'YER', 'external_product_id' => 'internal-profile', 'metadata' => ['hotspot' => ['allow_unlimited' => true]]]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        app(PublishPricingRuleService::class)->handle($admin, $product, '800', '150');
        Sanctum::actingAs($user, ['account', 'seller']);

        return [$product, $wallet, $admin];
    }

    private function url(NetworkProduct $product): string
    {
        return '/api/v1/seller/networks/'.$product->network_id.'/products/'.$product->id;
    }

    private function purchase(NetworkProduct $product, SellerWallet $wallet): array
    {
        return ['product_id' => $product->id, 'wallet_id' => $wallet->id, 'idempotency_key' => 'catalog-purchase-001'];
    }
}
