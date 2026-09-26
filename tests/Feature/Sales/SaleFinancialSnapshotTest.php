<?php

namespace Tests\Feature\Sales;

use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\NetworkProduct;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SaleFinancialSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function makeSale(): Sale
    {
        $user = User::factory()->create();

        $seller = Seller::query()->create([
            'user_id' => $user->id,
            'code' => 'SELLER-001',
            'business_name' => 'Test Seller',
        ]);

        $wallet = SellerWallet::query()->create([
            'seller_id' => $seller->id,
            'currency_code' => 'YER',
        ]);

        $owner = NetworkOwner::query()->create([
            'code' => 'OWNER-001',
            'name' => 'Test Owner',
        ]);

        $network = Network::query()->create([
            'network_owner_id' => $owner->id,
            'code' => 'NET-001',
            'name' => 'Test Network',
            'currency_code' => 'YER',
        ]);

        $product = NetworkProduct::query()->create([
            'network_id' => $network->id,
            'external_product_id' => 'PROVIDER-CARD-1000',
            'code' => 'CARD-1000',
            'name' => '1000 YER Card',
            'face_value' => '1000.0000',
            'currency_code' => 'YER',
        ]);

        return Sale::query()->create([
            'seller_id' => $seller->id,
            'seller_wallet_id' => $wallet->id,
            'network_id' => $network->id,
            'network_product_id' => $product->id,
            'reference_no' => 'SALE-001',
            'idempotency_key' => 'sale-test-001',
            'currency_code' => 'YER',
            'delivery_method' => 'screen',
        ]);
    }

    public function test_it_creates_a_balanced_financial_snapshot(): void
    {
        $sale = $this->makeSale();

        $snapshot = app(SaleFinancialSnapshotService::class)->create(
            $sale,
            '1000',
            '800',
            '150',
            '50'
        );

        $this->assertSame('1000.0000', $snapshot->face_value);
        $this->assertSame('800.0000', $snapshot->provider_amount);
        $this->assertSame('150.0000', $snapshot->seller_commission);
        $this->assertSame('50.0000', $snapshot->platform_commission);
        $this->assertSame('850.0000', $snapshot->seller_net_amount);

        $this->assertDatabaseCount('sale_financials', 1);
    }

    public function test_it_rejects_an_unbalanced_snapshot(): void
    {
        $sale = $this->makeSale();

        $this->expectException(InvalidArgumentException::class);

        app(SaleFinancialSnapshotService::class)->create(
            $sale,
            '1000',
            '800',
            '150',
            '40'
        );
    }

    public function test_same_snapshot_request_is_idempotent(): void
    {
        $sale = $this->makeSale();

        $service = app(SaleFinancialSnapshotService::class);

        $first = $service->create(
            $sale,
            '1000',
            '800',
            '150',
            '50'
        );

        $second = $service->create(
            $sale,
            '1000.0000',
            '800.0000',
            '150.0000',
            '50.0000'
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('sale_financials', 1);
    }

    public function test_existing_snapshot_cannot_be_changed_by_retry(): void
    {
        $sale = $this->makeSale();

        $service = app(SaleFinancialSnapshotService::class);

        $service->create(
            $sale,
            '1000',
            '800',
            '150',
            '50'
        );

        $this->expectException(InvalidArgumentException::class);

        $service->create(
            $sale,
            '1000',
            '750',
            '200',
            '50'
        );
    }

    public function test_negative_money_is_rejected(): void
    {
        $sale = $this->makeSale();

        $this->expectException(InvalidArgumentException::class);

        app(SaleFinancialSnapshotService::class)->create(
            $sale,
            '1000',
            '1050',
            '-100',
            '50'
        );
    }
}
