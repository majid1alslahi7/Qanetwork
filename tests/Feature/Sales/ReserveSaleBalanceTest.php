<?php

namespace Tests\Feature\Sales;

use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\NetworkProduct;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Sales\ReserveSaleBalanceService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ReserveSaleBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeSale(
        string $balance = '100000.0000',
        string $reserved = '0.0000'
    ): array {
        $user = User::factory()->create();

        $seller = Seller::query()->create([
            'user_id' => $user->id,
            'code' => 'SELLER-R1',
            'business_name' => 'Reservation Seller',
        ]);

        $wallet = SellerWallet::query()->create([
            'seller_id' => $seller->id,
            'currency_code' => 'YER',
        ]);

        // Test setup only. Production changes go through services.
        $wallet->balance = $balance;
        $wallet->reserved_balance = $reserved;
        $wallet->save();

        $owner = NetworkOwner::query()->create([
            'code' => 'OWNER-R1',
            'name' => 'Reservation Owner',
        ]);

        $network = Network::query()->create([
            'network_owner_id' => $owner->id,
            'code' => 'NET-R1',
            'name' => 'Reservation Network',
            'currency_code' => 'YER',
        ]);

        $product = NetworkProduct::query()->create([
            'network_id' => $network->id,
            'external_product_id' => 'PROVIDER-R1-1000',
            'code' => 'CARD-R1-1000',
            'name' => '1000 YER Card',
            'face_value' => '1000.0000',
            'currency_code' => 'YER',
        ]);

        $sale = Sale::query()->create([
            'seller_id' => $seller->id,
            'seller_wallet_id' => $wallet->id,
            'network_id' => $network->id,
            'network_product_id' => $product->id,
            'reference_no' => 'SALE-R1',
            'idempotency_key' => 'sale-r1',
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

        return [$sale, $wallet];
    }

    public function test_it_reserves_seller_net_amount_without_debiting_balance(): void
    {
        [$sale, $wallet] = $this->makeSale();

        $reservation = app(ReserveSaleBalanceService::class)
            ->handle($sale, 'reserve-r1');

        $wallet->refresh();
        $sale->refresh();

        $this->assertSame('850.0000', $reservation->amount);
        $this->assertSame('reserved', $reservation->status);

        $this->assertSame('100000.0000', $wallet->balance);
        $this->assertSame('850.0000', $wallet->reserved_balance);

        $available = bcsub(
            (string) $wallet->balance,
            (string) $wallet->reserved_balance,
            4
        );

        $this->assertSame('99150.0000', $available);
        $this->assertSame('balance_reserved', $sale->status);
    }

    public function test_same_reservation_request_is_idempotent(): void
    {
        [$sale, $wallet] = $this->makeSale();

        $service = app(ReserveSaleBalanceService::class);

        $first = $service->handle($sale, 'reserve-r1');
        $second = $service->handle($sale, 'reserve-r1');

        $wallet->refresh();

        $this->assertSame($first->id, $second->id);
        $this->assertSame('850.0000', $wallet->reserved_balance);
        $this->assertDatabaseCount('sale_reservations', 1);
    }

    public function test_same_sale_cannot_reserve_with_different_key(): void
    {
        [$sale] = $this->makeSale();

        $service = app(ReserveSaleBalanceService::class);

        $service->handle($sale, 'reserve-r1');

        $this->expectException(RuntimeException::class);

        $service->handle($sale, 'reserve-r2');
    }

    public function test_insufficient_available_balance_is_rejected(): void
    {
        [$sale, $wallet] = $this->makeSale(
            '1000.0000',
            '200.0000'
        );

        try {
            app(ReserveSaleBalanceService::class)
                ->handle($sale, 'reserve-r1');

            $this->fail(
                'Expected insufficient balance exception.'
            );
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Insufficient available wallet balance.',
                $e->getMessage()
            );
        }

        $wallet->refresh();

        $this->assertSame('1000.0000', $wallet->balance);
        $this->assertSame('200.0000', $wallet->reserved_balance);
        $this->assertDatabaseCount('sale_reservations', 0);
    }

    public function test_reservation_requires_financial_snapshot(): void
    {
        [$sale, $wallet] = $this->makeSale();

        $sale->financial()->delete();

        try {
            app(ReserveSaleBalanceService::class)
                ->handle($sale, 'reserve-r1');

            $this->fail(
                'Expected missing financial snapshot exception.'
            );
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Sale financial snapshot is required before reservation.',
                $e->getMessage()
            );
        }

        $wallet->refresh();

        $this->assertSame('0.0000', $wallet->reserved_balance);
        $this->assertDatabaseCount('sale_reservations', 0);
    }
}
