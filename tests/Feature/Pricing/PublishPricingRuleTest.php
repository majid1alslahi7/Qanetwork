<?php

namespace Tests\Feature\Pricing;

use App\Enums\UserRole;
use App\Models\CommissionRule;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\NetworkProduct;
use App\Models\PricingRule;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Pricing\CreatePricedSaleSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublishPricingRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_revision_preserves_sale_snapshot_and_new_sales_use_new_rule(): void
    {
        [$product, $sale] = $this->setupSale();
        $oldId = $this->postJson($this->url($product), ['provider_amount' => '800', 'seller_commission' => '150'])
            ->assertCreated()->assertJsonPath('data.provider_amount', '800.0000')->json('data.id');
        $oldSnapshot = app(CreatePricedSaleSnapshotService::class)->handle($sale);
        $this->assertSame('850.0000', $oldSnapshot->seller_net_amount);
        $this->assertSame('50.0000', $oldSnapshot->platform_commission);
        $newId = $this->postJson($this->url($product), ['provider_amount' => '750', 'seller_commission' => '100'])->assertCreated()->json('data.id');
        $this->assertFalse(PricingRule::query()->findOrFail($oldId)->is_active);
        $this->assertSame(1, PricingRule::query()->where('is_active', true)->count());
        $replay = app(CreatePricedSaleSnapshotService::class)->handle($sale);
        $this->assertSame($oldSnapshot->id, $replay->id);
        $this->assertSame($oldId, $replay->pricing_rule_id);
        $other = Sale::query()->create(array_merge($sale->only(['seller_id', 'seller_wallet_id', 'network_id', 'network_product_id', 'currency_code']),
            ['reference_no' => 'second', 'idempotency_key' => 'second', 'delivery_method' => 'screen']));
        $newSnapshot = app(CreatePricedSaleSnapshotService::class)->handle($other);
        $this->assertSame($newId, $newSnapshot->pricing_rule_id);
        $this->assertSame('900.0000', $newSnapshot->seller_net_amount);
        $this->assertSame('150.0000', $newSnapshot->platform_commission);
        $this->assertNotNull($newSnapshot->commission_rule_id);
        $this->getJson($this->url($product))->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseCount('audit_events', 2);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_pricing_does_not_replace_current_rule(string $provider, mixed $commission): void
    {
        [$product] = $this->setupSale();
        $this->postJson($this->url($product), ['provider_amount' => '800', 'seller_commission' => '150'])->assertCreated();
        $this->postJson($this->url($product), ['provider_amount' => $provider, 'seller_commission' => $commission])->assertUnprocessable();
        $this->assertDatabaseCount('pricing_rules', 1);
        $this->assertDatabaseCount('commission_rules', 1);
        $this->assertTrue(PricingRule::query()->firstOrFail()->is_active);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public static function invalidAmounts(): array
    {
        return [['900', '150'], ['800', '-1'], ['800', '150.00001'], ['800', 150], ['0', '1000']];
    }

    public function test_pricing_list_identifies_seller_overrides_by_business_name(): void
    {
        [$product, $sale] = $this->setupSale();
        Seller::query()->findOrFail($sale->seller_id)->update(['business_name' => 'متجر الشبكة']);
        $pricingId = $this->postJson($this->url($product), ['provider_amount' => '800', 'seller_commission' => '150'])
            ->assertCreated()->json('data.id');
        $this->postJson($this->url($product).'/'.$pricingId.'/commissions', [
            'seller_id' => $sale->seller_id, 'seller_commission' => '175',
        ])->assertCreated();

        $response = $this->getJson($this->url($product));

        $response->assertOk();
        $override = collect($response->json('data.0.commissions'))->firstWhere('seller_id', $sale->seller_id);
        $this->assertSame('متجر الشبكة', $override['seller_name'] ?? null);
    }

    public function test_unpriced_product_cannot_create_financial_snapshot(): void
    {
        [, $sale] = $this->setupSale();
        $this->expectException(ValidationException::class);
        try {
            app(CreatePricedSaleSnapshotService::class)->handle($sale);
        } finally {
            $this->assertDatabaseCount('sale_financials', 0);
        }
    }

    public function test_changed_product_face_value_requires_new_price(): void
    {
        [$product, $sale] = $this->setupSale();
        $this->postJson($this->url($product), ['provider_amount' => '800', 'seller_commission' => '150'])->assertCreated();
        $product->update(['face_value' => '1200']);
        $this->expectException(ValidationException::class);
        app(CreatePricedSaleSnapshotService::class)->handle($sale);
    }

    public function test_sale_cannot_be_repriced_after_processing_has_started(): void
    {
        [$product, $sale] = $this->setupSale();
        $this->postJson($this->url($product), ['provider_amount' => '800', 'seller_commission' => '150'])->assertCreated();
        $sale->status = 'processing_provider';
        $sale->save();
        $this->expectException(ValidationException::class);
        try {
            app(CreatePricedSaleSnapshotService::class)->handle($sale);
        } finally {
            $this->assertDatabaseCount('sale_financials', 0);
        }
    }

    public function test_published_financial_values_cannot_be_changed(): void
    {
        [$product] = $this->setupSale();
        $this->postJson($this->url($product), ['provider_amount' => '800', 'seller_commission' => '150'])->assertCreated();
        $rule = PricingRule::query()->firstOrFail();
        $this->expectException(LogicException::class);
        $rule->update(['provider_amount' => '900']);
    }

    public function test_seller_override_revisions_and_retirement_preserve_existing_sales(): void
    {
        [$product, $sale] = $this->setupSale();
        $pricingId = $this->postJson($this->url($product), ['provider_amount' => '800', 'seller_commission' => '150'])->assertCreated()->json('data.id');
        $url = $this->url($product).'/'.$pricingId.'/commissions';
        $firstId = $this->postJson($url, ['seller_id' => $sale->seller_id, 'seller_commission' => '175'])->assertCreated()->json('data.id');
        $snapshot = app(CreatePricedSaleSnapshotService::class)->handle($sale);
        $this->assertSame('825.0000', $snapshot->seller_net_amount);
        $this->assertSame('25.0000', $snapshot->platform_commission);
        $this->assertSame($firstId, $snapshot->commission_rule_id);
        $secondId = $this->postJson($url, ['seller_id' => $sale->seller_id, 'seller_commission' => '125'])->assertCreated()->json('data.id');
        $this->assertFalse(CommissionRule::query()->findOrFail($firstId)->is_active);
        $this->postJson($url, ['seller_id' => $sale->seller_id, 'seller_commission' => '125'])->assertOk()->assertJsonPath('data.id', $secondId);
        $this->assertDatabaseCount('commission_rules', 3);
        $this->deleteJson($url.'/'.$secondId)->assertOk()->assertJsonPath('data.is_active', false);
        $this->deleteJson($url.'/'.$secondId)->assertOk();
        $this->assertDatabaseCount('audit_events', 4);
        $this->assertSame($firstId, app(CreatePricedSaleSnapshotService::class)->handle($sale)->commission_rule_id);
        $other = Sale::query()->create(array_merge($sale->only(['seller_id', 'seller_wallet_id', 'network_id', 'network_product_id', 'currency_code']),
            ['reference_no' => 'after-retire', 'idempotency_key' => 'after-retire', 'delivery_method' => 'screen']));
        $fallback = app(CreatePricedSaleSnapshotService::class)->handle($other);
        $this->assertSame('150.0000', $fallback->seller_commission);
        $this->assertSame('850.0000', $fallback->seller_net_amount);
        $this->assertDatabaseCount('commission_rules', 3);
    }

    public function test_override_is_isolated_to_the_selected_seller(): void
    {
        [$product, $sale] = $this->setupSale();
        $pricingId = $this->postJson($this->url($product), ['provider_amount' => '800', 'seller_commission' => '150'])->assertCreated()->json('data.id');
        $otherSeller = Seller::query()->create(['user_id' => User::factory()->create()->id, 'code' => 'other-seller']);
        $this->postJson($this->url($product).'/'.$pricingId.'/commissions', ['seller_id' => $otherSeller->id, 'seller_commission' => '200'])->assertCreated();
        $this->assertSame('150.0000', app(CreatePricedSaleSnapshotService::class)->handle($sale)->seller_commission);
    }

    public function test_invalid_override_and_default_retirement_leave_pricing_unchanged(): void
    {
        [$product, $sale] = $this->setupSale();
        $pricingId = $this->postJson($this->url($product), ['provider_amount' => '800', 'seller_commission' => '150'])->assertCreated()->json('data.id');
        $url = $this->url($product).'/'.$pricingId.'/commissions';
        $this->postJson($url, ['seller_id' => $sale->seller_id, 'seller_commission' => '200.0001'])->assertUnprocessable()->assertJsonValidationErrors('seller_commission');
        $default = CommissionRule::query()->firstOrFail();
        $this->deleteJson($url.'/'.$default->id)->assertUnprocessable()->assertJsonValidationErrors('commission');
        $this->assertTrue($default->fresh()->is_active);
        $this->assertDatabaseCount('commission_rules', 1);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_inactive_price_and_wrong_product_cannot_receive_override(): void
    {
        [$product, $sale] = $this->setupSale();
        $pricingId = $this->postJson($this->url($product), ['provider_amount' => '800', 'seller_commission' => '150'])->assertCreated()->json('data.id');
        $this->postJson($this->url($product), ['provider_amount' => '750', 'seller_commission' => '150'])->assertCreated();
        $this->postJson($this->url($product).'/'.$pricingId.'/commissions', ['seller_id' => $sale->seller_id, 'seller_commission' => '100'])->assertUnprocessable();
        $otherProduct = NetworkProduct::query()->create(['network_id' => $product->network_id, 'code' => 'other-product',
            'external_product_id' => 'other', 'name' => 'Other', 'face_value' => '1000', 'currency_code' => 'YER']);
        $this->postJson($this->url($otherProduct).'/'.$pricingId.'/commissions', ['seller_id' => $sale->seller_id, 'seller_commission' => '100'])->assertNotFound();
        $this->assertDatabaseCount('commission_rules', 2);
    }

    public function test_unscoped_token_cannot_publish_or_retire_seller_override(): void
    {
        [$product, $sale] = $this->setupSale();
        $pricingId = $this->postJson($this->url($product), ['provider_amount' => '800', 'seller_commission' => '150'])->assertCreated()->json('data.id');
        $url = $this->url($product).'/'.$pricingId.'/commissions';
        $id = $this->postJson($url, ['seller_id' => $sale->seller_id, 'seller_commission' => '100'])->assertCreated()->json('data.id');
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account']);
        $this->postJson($url, ['seller_id' => $sale->seller_id, 'seller_commission' => '125'])->assertForbidden();
        $this->deleteJson($url.'/'.$id)->assertForbidden();
        $this->assertTrue(CommissionRule::query()->findOrFail($id)->is_active);
    }

    public function test_cross_network_route_and_unscoped_token_are_rejected(): void
    {
        [$product] = $this->setupSale();
        $this->postJson('/api/v1/admin/networks/01ARZ3NDEKTSV4RRFFQ69G5FAV/products/'.$product->id.'/pricing',
            ['provider_amount' => '800', 'seller_commission' => '150'])->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account']);
        $this->getJson($this->url($product))->assertForbidden();
        $this->assertDatabaseCount('pricing_rules', 0);
    }

    private function setupSale(): array
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account', 'admin']);
        $owner = NetworkOwner::query()->create(['code' => 'OWN-'.Str::ulid(), 'name' => 'Owner']);
        $network = Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'NET-'.Str::ulid(), 'name' => 'Network']);
        $product = NetworkProduct::query()->create(['network_id' => $network->id, 'code' => 'PRD-'.Str::ulid(),
            'external_product_id' => 'day', 'name' => 'Day', 'face_value' => '1000', 'currency_code' => 'YER']);
        $seller = Seller::query()->create(['user_id' => User::factory()->create()->id, 'code' => 'seller']);
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => 'YER']);
        $sale = Sale::query()->create(['seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id,
            'network_id' => $network->id, 'network_product_id' => $product->id, 'reference_no' => 'first',
            'idempotency_key' => 'first', 'currency_code' => 'YER', 'delivery_method' => 'screen']);

        return [$product, $sale];
    }

    private function url(NetworkProduct $product): string
    {
        return '/api/v1/admin/networks/'.$product->network_id.'/products/'.$product->id.'/pricing';
    }
}
