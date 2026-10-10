<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\NetworkOwner;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\User;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SellerEarningsReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_counts_only_own_completed_sales_by_completion_date_with_exact_separate_currencies(): void
    {
        $seller = $this->seller();
        Sanctum::actingAs($seller->user, ['account', 'seller']);
        $this->sale($seller, '200', 'YER', 'completed', '2026-10-01 00:00:00');
        $this->sale($seller, '500', 'YER', 'completed', '2026-10-02 23:59:59');
        $this->sale($seller, '0.1', 'YER');
        $this->sale($seller, '0.2', 'YER');
        $this->sale($seller, '1.25', 'SAR');
        $this->sale($seller, '5000', 'YER', 'completed', '2026-10-03 00:00:00');
        foreach (['pending', 'failed', 'timeout', 'provider_confirmed'] as $status) {
            $this->sale($seller, '5000', 'YER', $status);
        }
        $foreign = $this->sale($this->seller(), '9000', 'YER');

        $response = $this->getJson('/api/v1/seller/earnings/summary'.$this->period())->assertOk();

        $response->assertExactJson(['data' => ['period' => ['from' => '2026-10-01', 'to' => '2026-10-02', 'timezone' => 'UTC'], 'currencies' => [
            ['currency_code' => 'SAR', 'sale_count' => 1, 'face_value' => '1.2500', 'seller_commission' => '0.0875', 'seller_net_amount' => '1.1625'],
            ['currency_code' => 'YER', 'sale_count' => 4, 'face_value' => '700.3000', 'seller_commission' => '49.0210', 'seller_net_amount' => '651.2790'],
        ]]]);
        $sales = $this->getJson('/api/v1/seller/earnings/sales'.$this->period())->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.per_page', 25);
        $this->assertNotContains($foreign->id, array_column($sales->json('data'), 'id'));
        $this->assertArrayNotHasKey('provider_amount', $sales->json('data.0.financial'));
        $this->assertDatabaseCount('seller_ledger_entries', 0);
    }

    public function test_report_rejects_scope_overrides_and_invalid_dates_and_returns_empty_period(): void
    {
        $seller = $this->seller();
        Sanctum::actingAs($seller->user, ['account', 'seller']);
        $this->getJson('/api/v1/seller/earnings/summary'.$this->period().'&seller_id=foreign')->assertUnprocessable()->assertJsonValidationErrors('seller_id');
        $this->getJson('/api/v1/seller/earnings/sales?from=2026-10-03&to=2026-10-02')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/seller/earnings/summary')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->getJson('/api/v1/seller/earnings/summary'.$this->period())->assertOk()->assertJsonPath('data.currencies', []);
    }

    public function test_report_requires_authenticated_active_seller_and_seller_token_ability(): void
    {
        $url = '/api/v1/seller/earnings/summary'.$this->period();
        $this->getJson($url)->assertUnauthorized();
        $seller = $this->seller();
        Sanctum::actingAs($seller->user, ['account']);
        $this->getJson($url)->assertForbidden();
        $seller->status = 'suspended';
        $seller->save();
        Sanctum::actingAs($seller->user, ['account', 'seller']);
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account', 'seller', 'admin']);
        $this->getJson($url)->assertForbidden();
    }

    private function seller(): Seller
    {
        $seller = new Seller(['user_id' => User::factory()->create(['role' => UserRole::SELLER])->id, 'code' => 'SEL-'.Str::ulid()]);
        $seller->status = 'active';
        $seller->save();

        return $seller;
    }

    private function sale(Seller $seller, string $faceValue, string $currency, string $status = 'completed', string $completedAt = '2026-10-02 12:00:00'): Sale
    {
        $wallet = $seller->wallets()->firstOrCreate(['currency_code' => $currency]);
        $owner = NetworkOwner::query()->create(['code' => 'OWN-'.Str::ulid(), 'name' => 'Owner']);
        $network = $owner->networks()->create(['code' => 'NET-'.Str::ulid(), 'name' => 'Network']);
        $product = $network->products()->create(['code' => 'PRD-'.Str::ulid(), 'external_product_id' => (string) Str::ulid(), 'name' => 'Card', 'face_value' => $faceValue, 'currency_code' => $currency]);
        $sale = Sale::query()->create(['seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id, 'network_id' => $network->id, 'network_product_id' => $product->id,
            'reference_no' => 'SALE-'.Str::ulid(), 'idempotency_key' => (string) Str::ulid(), 'currency_code' => $currency]);
        $sale->status = $status;
        $sale->created_at = '2026-09-01 12:00:00';
        $sale->completed_at = $completedAt;
        $sale->save();
        $ownerShare = bcmul($faceValue, '0.85', 4);
        $commission = bcmul($faceValue, '0.07', 4);
        app(SaleFinancialSnapshotService::class)->create($sale, $faceValue, $ownerShare, $commission, bcsub(bcsub($faceValue, $ownerShare, 4), $commission, 4));

        return $sale;
    }

    private function period(): string
    {
        return '?from=2026-10-01&to=2026-10-02';
    }
}
