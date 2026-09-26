<?php

namespace Tests\Feature\Sales;

use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\NetworkProduct;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Sales\CaptureSaleReservationService;
use App\Services\Sales\ReleaseSaleReservationService;
use App\Services\Sales\ReserveSaleBalanceService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SaleReservationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function preparedSale(): array
    {
        $user = User::factory()->create();

        $seller = Seller::query()->create([
            'user_id' => $user->id,
            'code' => 'SELLER-LIFE',
            'business_name' => 'Lifecycle Seller',
        ]);

        $wallet = SellerWallet::query()->create([
            'seller_id' => $seller->id,
            'currency_code' => 'YER',
        ]);

        $wallet->balance = '100000.0000';
        $wallet->save();

        $owner = NetworkOwner::query()->create([
            'code' => 'OWNER-LIFE',
            'name' => 'Lifecycle Owner',
        ]);

        $network = Network::query()->create([
            'network_owner_id' => $owner->id,
            'code' => 'NET-LIFE',
            'name' => 'Lifecycle Network',
            'currency_code' => 'YER',
        ]);

        $product = NetworkProduct::query()->create([
            'network_id' => $network->id,
            'external_product_id' => 'PROVIDER-LIFE-1000',
            'code' => 'CARD-LIFE-1000',
            'name' => '1000 YER Card',
            'face_value' => '1000.0000',
            'currency_code' => 'YER',
        ]);

        $sale = Sale::query()->create([
            'seller_id' => $seller->id,
            'seller_wallet_id' => $wallet->id,
            'network_id' => $network->id,
            'network_product_id' => $product->id,
            'reference_no' => 'SALE-LIFE-001',
            'idempotency_key' => 'sale-life-001',
            'currency_code' => 'YER',
            'delivery_method' => 'screen',
        ]);

        app(SaleFinancialSnapshotService::class)->create(
            $sale,
            '1000',
            '800',
            '150',
            '50'
        );

        $reservation = app(ReserveSaleBalanceService::class)
            ->handle($sale, 'reserve-life-001');

        return [$sale, $wallet, $reservation];
    }

    public function test_reserved_money_can_be_captured_once(): void
    {
        [$sale, $wallet, $reservation] = $this->preparedSale();

        $service = app(CaptureSaleReservationService::class);

        $first = $service->handle(
            $reservation,
            'capture-life-001'
        );

        $second = $service->handle(
            $reservation,
            'capture-life-001'
        );

        $wallet->refresh();
        $reservation->refresh();
        $sale->refresh();

        $this->assertSame($first->id, $second->id);
        $this->assertSame('99150.0000', $wallet->balance);
        $this->assertSame('0.0000', $wallet->reserved_balance);
        $this->assertSame('captured', $reservation->status);
        $this->assertSame('accounting_posted', $sale->status);

        $this->assertDatabaseCount('seller_ledger_entries', 1);

        $this->assertDatabaseHas('seller_ledger_entries', [
            'reference_type' => 'sale',
            'reference_id' => $sale->id,
            'entry_type' => 'sale',
            'direction' => 'debit',
        ]);
    }

    public function test_releasing_reservation_does_not_debit_balance(): void
    {
        [, $wallet, $reservation] = $this->preparedSale();

        $service = app(ReleaseSaleReservationService::class);

        $service->handle($reservation);
        $service->handle($reservation);

        $wallet->refresh();
        $reservation->refresh();

        $this->assertSame('100000.0000', $wallet->balance);
        $this->assertSame('0.0000', $wallet->reserved_balance);
        $this->assertSame('released', $reservation->status);

        $this->assertDatabaseCount('seller_ledger_entries', 0);
    }

    public function test_captured_reservation_cannot_be_released(): void
    {
        [, , $reservation] = $this->preparedSale();

        app(CaptureSaleReservationService::class)->handle(
            $reservation,
            'capture-life-001'
        );

        $this->expectException(RuntimeException::class);

        app(ReleaseSaleReservationService::class)
            ->handle($reservation);
    }

    public function test_released_reservation_cannot_be_captured(): void
    {
        [, , $reservation] = $this->preparedSale();

        app(ReleaseSaleReservationService::class)
            ->handle($reservation);

        $this->expectException(RuntimeException::class);

        app(CaptureSaleReservationService::class)->handle(
            $reservation,
            'capture-life-001'
        );
    }
}
