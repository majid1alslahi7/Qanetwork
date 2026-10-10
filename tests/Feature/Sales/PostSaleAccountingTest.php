<?php

namespace Tests\Feature\Sales;

use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\Sale;
use App\Models\SaleAccountingEntry;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\SoldCard;
use App\Models\User;
use App\Services\Accounting\SaleAccountingReportService;
use App\Services\Providers\PrepareProviderTransactionService;
use App\Services\Sales\FinalizeConfirmedSaleService;
use App\Services\Sales\ReserveSaleBalanceService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class PostSaleAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_malki_sale_automatically_accrues_owner_share_and_retains_seller_commission_once(): void
    {
        $sale = $this->confirmedSale('200', '170', '14', '16');
        $owner = NetworkOwner::query()->findOrFail($sale->financial()->firstOrFail()->network_owner_id);
        $report = app(SaleAccountingReportService::class);
        $this->assertSame(['currencies' => []], $report->ownerBalance($owner));
        $this->assertSame('1000.0000', $sale->wallet()->firstOrFail()->balance);

        $finalizer = app(FinalizeConfirmedSaleService::class);
        $finalizer->handle($sale);
        $finalizer->handle($sale);

        $this->assertSame('814.0000', $sale->wallet()->firstOrFail()->balance);
        $this->assertSame('0.0000', $sale->wallet()->firstOrFail()->reserved_balance);
        $this->assertSame('14.0000', $sale->financial()->firstOrFail()->seller_commission);
        $this->assertSame(['currencies' => [['currency_code' => 'YER', 'accrued' => '170.0000', 'settled' => '0.0000', 'outstanding' => '170.0000']]], $report->ownerBalance($owner));
        $this->assertDatabaseHas('sale_accounting_entries', ['sale_id' => $sale->id, 'entry_type' => 'platform_revenue', 'amount' => '16.0000']);
        $this->assertDatabaseCount('sale_accounting_entries', 2);
        $this->assertDatabaseCount('seller_ledger_entries', 1);
    }

    public function test_sale_posts_provider_payable_and_platform_revenue_once_from_snapshot(): void
    {
        $sale = $this->confirmedSale();
        $service = app(FinalizeConfirmedSaleService::class);
        $service->handle($sale);
        $service->handle($sale);
        $this->assertDatabaseCount('sale_accounting_entries', 2);
        $this->assertDatabaseCount('seller_ledger_entries', 1);
        $provider = SaleAccountingEntry::query()->where('entry_type', 'provider_payable')->firstOrFail();
        $platform = SaleAccountingEntry::query()->where('entry_type', 'platform_revenue')->firstOrFail();
        $this->assertSame('800.0000', $provider->amount);
        $this->assertSame($sale->financial()->firstOrFail()->network_owner_id, $provider->network_owner_id);
        $this->assertSame('50.0000', $platform->amount);
        $this->assertNull($platform->network_owner_id);
        $this->assertSame('YER', $platform->currency_code);
        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertSame('150.0000', $sale->wallet()->firstOrFail()->balance);
    }

    public function test_changed_network_owner_does_not_change_provider_payee(): void
    {
        $sale = $this->confirmedSale();
        $oldOwner = $sale->financial()->firstOrFail()->network_owner_id;
        $replacement = NetworkOwner::query()->create(['code' => 'replacement', 'name' => 'New Owner']);
        $sale->network()->update(['network_owner_id' => $replacement->id]);
        app(FinalizeConfirmedSaleService::class)->handle($sale);
        $this->assertSame($oldOwner, SaleAccountingEntry::query()->where('entry_type', 'provider_payable')->firstOrFail()->network_owner_id);
    }

    public function test_conflicting_accounting_entry_rolls_back_seller_capture_and_completion(): void
    {
        $sale = $this->confirmedSale();
        SaleAccountingEntry::query()->create(['sale_id' => $sale->id, 'network_owner_id' => null,
            'entry_type' => 'platform_revenue', 'amount' => '99', 'currency_code' => 'YER',
            'idempotency_key' => 'sale:'.$sale->id.':platform_revenue', 'posted_at' => now()]);
        $this->expectException(RuntimeException::class);
        try {
            app(FinalizeConfirmedSaleService::class)->handle($sale);
        } finally {
            $this->assertSame('provider_confirmed', $sale->fresh()->status);
            $wallet = $sale->wallet()->firstOrFail();
            $this->assertSame('1000.0000', $wallet->balance);
            $this->assertSame('850.0000', $wallet->reserved_balance);
            $this->assertSame('reserved', $sale->reservation()->firstOrFail()->status);
            $this->assertDatabaseCount('seller_ledger_entries', 0);
            $this->assertDatabaseCount('sale_accounting_entries', 1);
        }
    }

    public function test_posted_accounting_values_cannot_be_changed(): void
    {
        $sale = $this->confirmedSale();
        app(FinalizeConfirmedSaleService::class)->handle($sale);
        $this->expectException(LogicException::class);
        SaleAccountingEntry::query()->firstOrFail()->update(['amount' => '1']);
    }

    public function test_snapshot_owner_and_amounts_cannot_be_changed(): void
    {
        $sale = $this->confirmedSale();
        $this->expectException(LogicException::class);
        $sale->financial()->firstOrFail()->update(['provider_amount' => '1']);
    }

    private function confirmedSale(string $faceValue = '1000', string $ownerShare = '800', string $sellerCommission = '150', string $platformShare = '50'): Sale
    {
        $seller = Seller::query()->create(['user_id' => User::factory()->create()->id, 'code' => 'seller']);
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => 'YER']);
        $wallet->balance = '1000';
        $wallet->save();
        $owner = NetworkOwner::query()->create(['code' => 'owner', 'name' => 'Owner']);
        $network = Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'network', 'name' => 'Network']);
        $connection = $network->connections()->create(['name' => 'Primary', 'driver' => 'not-called', 'is_enabled' => true]);
        $product = $network->products()->create(['code' => 'product', 'external_product_id' => 'day', 'name' => 'Day', 'face_value' => $faceValue, 'currency_code' => 'YER']);
        $sale = Sale::query()->create(['seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id, 'network_id' => $network->id,
            'network_product_id' => $product->id, 'reference_no' => 'sale', 'idempotency_key' => 'sale', 'currency_code' => 'YER']);
        app(SaleFinancialSnapshotService::class)->create($sale, $faceValue, $ownerShare, $sellerCommission, $platformShare);
        app(ReserveSaleBalanceService::class)->handle($sale, 'sale:'.$sale->id.':reservation');
        $transaction = app(PrepareProviderTransactionService::class)->handle($sale, $connection);
        $transaction->status = 'confirmed';
        $transaction->save();
        $card = new SoldCard(['sale_id' => $sale->id, 'sold_at' => now()]);
        $card->setCredentials(['username' => 'card', 'password' => 'secret']);
        $card->save();
        $sale->status = 'provider_confirmed';
        $sale->save();

        return $sale;
    }
}
