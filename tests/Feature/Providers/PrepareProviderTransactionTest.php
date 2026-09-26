<?php

namespace Tests\Feature\Providers;

use App\Models\Network;
use App\Models\NetworkConnection;
use App\Models\NetworkOwner;
use App\Models\NetworkProduct;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Providers\PrepareProviderTransactionService;
use App\Services\Sales\ReserveSaleBalanceService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PrepareProviderTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function preparedSale(
        bool $reserve = true,
        bool $createConnection = true
    ): array {
        $user = User::factory()->create();

        $seller = Seller::query()->create([
            'user_id' => $user->id,
            'code' => 'SELLER-PROVIDER',
            'business_name' => 'Provider Seller',
        ]);

        $wallet = SellerWallet::query()->create([
            'seller_id' => $seller->id,
            'currency_code' => 'YER',
        ]);

        $wallet->balance = '100000.0000';
        $wallet->save();

        $owner = NetworkOwner::query()->create([
            'code' => 'OWNER-PROVIDER',
            'name' => 'Provider Owner',
        ]);

        $network = Network::query()->create([
            'network_owner_id' => $owner->id,
            'code' => 'NET-PROVIDER',
            'name' => 'Provider Network',
            'currency_code' => 'YER',
        ]);

        $product = NetworkProduct::query()->create([
            'network_id' => $network->id,
            'external_product_id' => 'PROVIDER-1000',
            'code' => 'CARD-PROVIDER-1000',
            'name' => 'Provider 1000 Card',
            'face_value' => '1000.0000',
            'currency_code' => 'YER',
        ]);

        $sale = Sale::query()->create([
            'seller_id' => $seller->id,
            'seller_wallet_id' => $wallet->id,
            'network_id' => $network->id,
            'network_product_id' => $product->id,
            'reference_no' => 'SALE-PROVIDER-001',
            'idempotency_key' => 'sale-provider-001',
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

        if ($reserve) {
            app(ReserveSaleBalanceService::class)->handle(
                $sale,
                'reserve-provider-001'
            );
        }

        $connection = null;

        if ($createConnection) {
            $connection = NetworkConnection::query()->create([
                'network_id' => $network->id,
                'name' => 'Primary',
                'driver' => 'fake',
                'base_url' => 'https://provider.example.test',
                'is_primary' => true,
                'is_enabled' => true,
            ]);
        }

        return [
            $sale,
            $wallet,
            $network,
            $connection,
        ];
    }

    public function test_provider_transaction_is_created_after_reservation(): void
    {
        [$sale, , , $connection] = $this->preparedSale();

        $transaction = app(
            PrepareProviderTransactionService::class
        )->handle($sale);

        $this->assertSame(
            $sale->id,
            $transaction->sale_id
        );

        $this->assertSame(
            $connection->id,
            $transaction->network_connection_id
        );

        $this->assertSame('created', $transaction->status);
        $this->assertSame(0, $transaction->attempt_count);

        $this->assertNotEmpty(
            $transaction->internal_transaction_id
        );

        $this->assertSame(
            'provider-purchase:'.$sale->id,
            $transaction->idempotency_key
        );

        $this->assertDatabaseCount(
            'provider_transactions',
            1
        );
    }

    public function test_preparation_is_idempotent(): void
    {
        [$sale] = $this->preparedSale();

        $service = app(
            PrepareProviderTransactionService::class
        );

        $first = $service->handle($sale);
        $second = $service->handle($sale);

        $this->assertSame($first->id, $second->id);

        $this->assertSame(
            $first->internal_transaction_id,
            $second->internal_transaction_id
        );

        $this->assertSame(
            $first->idempotency_key,
            $second->idempotency_key
        );

        $this->assertDatabaseCount(
            'provider_transactions',
            1
        );
    }

    public function test_transaction_cannot_be_prepared_before_reservation(): void
    {
        [$sale] = $this->preparedSale(
            reserve: false
        );

        $this->expectException(
            RuntimeException::class
        );

        app(
            PrepareProviderTransactionService::class
        )->handle($sale);
    }

    public function test_enabled_connection_is_required(): void
    {
        [$sale] = $this->preparedSale(
            reserve: true,
            createConnection: false
        );

        $this->expectException(
            RuntimeException::class
        );

        app(
            PrepareProviderTransactionService::class
        )->handle($sale);
    }

    public function test_connection_from_another_network_is_rejected(): void
    {
        [$sale] = $this->preparedSale();

        $otherOwner = NetworkOwner::query()->create([
            'code' => 'OWNER-OTHER',
            'name' => 'Other Owner',
        ]);

        $otherNetwork = Network::query()->create([
            'network_owner_id' => $otherOwner->id,
            'code' => 'NET-OTHER',
            'name' => 'Other Network',
            'currency_code' => 'YER',
        ]);

        $wrongConnection = NetworkConnection::query()->create([
            'network_id' => $otherNetwork->id,
            'name' => 'Wrong',
            'driver' => 'fake',
            'base_url' => 'https://wrong.example.test',
            'is_primary' => true,
            'is_enabled' => true,
        ]);

        $this->expectException(
            RuntimeException::class
        );

        app(
            PrepareProviderTransactionService::class
        )->handle(
            $sale,
            $wrongConnection
        );
    }

    public function test_primary_enabled_connection_is_selected(): void
    {
        [$sale, , $network] = $this->preparedSale(
            reserve: true,
            createConnection: false
        );

        NetworkConnection::query()->create([
            'network_id' => $network->id,
            'name' => 'Secondary',
            'driver' => 'fake',
            'base_url' => 'https://secondary.example.test',
            'is_primary' => false,
            'is_enabled' => true,
        ]);

        $primary = NetworkConnection::query()->create([
            'network_id' => $network->id,
            'name' => 'Primary',
            'driver' => 'fake',
            'base_url' => 'https://primary.example.test',
            'is_primary' => true,
            'is_enabled' => true,
        ]);

        $transaction = app(
            PrepareProviderTransactionService::class
        )->handle($sale);

        $this->assertSame(
            $primary->id,
            $transaction->network_connection_id
        );
    }
}
