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
use App\Providers\Contracts\ProviderAdapter;
use App\Providers\Data\PurchaseCardRequest;
use App\Providers\Data\PurchaseCardResult;
use App\Providers\Data\TransactionStatusResult;
use App\Providers\Enums\ProviderTransactionStatus;
use App\Providers\Registry\ProviderAdapterRegistry;
use App\Services\Providers\ExecuteProviderPurchaseService;
use App\Services\Providers\PrepareProviderTransactionService;
use App\Services\Sales\ReserveSaleBalanceService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecuteProviderPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function setupSale(): array
    {
        $user = User::factory()->create();

        $seller = Seller::query()->create([
            'user_id' => $user->id,
            'code' => 'SELLER-EXEC',
            'business_name' => 'Execution Seller',
        ]);

        $wallet = SellerWallet::query()->create([
            'seller_id' => $seller->id,
            'currency_code' => 'YER',
        ]);

        $wallet->balance = '100000.0000';
        $wallet->save();

        $owner = NetworkOwner::query()->create([
            'code' => 'OWNER-EXEC',
            'name' => 'Execution Owner',
        ]);

        $network = Network::query()->create([
            'network_owner_id' => $owner->id,
            'code' => 'NET-EXEC',
            'name' => 'Execution Network',
            'currency_code' => 'YER',
        ]);

        $connection = NetworkConnection::query()->create([
            'network_id' => $network->id,
            'name' => 'Primary',
            'driver' => 'test-provider',
            'base_url' => 'https://provider.example.test',
            'is_primary' => true,
            'is_enabled' => true,
        ]);

        $product = NetworkProduct::query()->create([
            'network_id' => $network->id,
            'external_product_id' => 'EXEC-1000',
            'code' => 'EXEC-CARD-1000',
            'name' => 'Execution Card',
            'face_value' => '1000.0000',
            'currency_code' => 'YER',
        ]);

        $sale = Sale::query()->create([
            'seller_id' => $seller->id,
            'seller_wallet_id' => $wallet->id,
            'network_id' => $network->id,
            'network_product_id' => $product->id,
            'reference_no' => 'SALE-EXEC-001',
            'idempotency_key' => 'sale-exec-001',
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

        app(ReserveSaleBalanceService::class)->handle(
            $sale,
            'reserve-exec-001'
        );

        $transaction = app(
            PrepareProviderTransactionService::class
        )->handle($sale, $connection);

        return [
            $sale,
            $wallet,
            $connection,
            $transaction,
        ];
    }

    private function serviceWithResult(
        PurchaseCardResult $result
    ): ExecuteProviderPurchaseService {
        $adapter = new class($result) implements ProviderAdapter {
            public function __construct(
                private PurchaseCardResult $result
            ) {
            }

            public function healthCheck(
                NetworkConnection $connection
            ): bool {
                return true;
            }

            public function getBalance(
                NetworkConnection $connection
            ): ?string {
                return null;
            }

            public function getProducts(
                NetworkConnection $connection
            ): array {
                return [];
            }

            public function checkAvailability(
                NetworkConnection $connection,
                string $externalProductId
            ): bool {
                return true;
            }

            public function purchaseCard(
                NetworkConnection $connection,
                PurchaseCardRequest $request
            ): PurchaseCardResult {
                return $this->result;
            }

            public function checkTransaction(
                NetworkConnection $connection,
                string $internalTransactionId,
                ?string $providerTransactionId = null
            ): TransactionStatusResult {
                return new TransactionStatusResult(
                    ProviderTransactionStatus::UNKNOWN
                );
            }
        };

        $registry = new ProviderAdapterRegistry();
        $registry->register('test-provider', $adapter);

        return new ExecuteProviderPurchaseService(
            $registry
        );
    }

    public function test_confirmed_purchase_is_recorded(): void
    {
        [$sale, $wallet, , $transaction] =
            $this->setupSale();

        $service = $this->serviceWithResult(
            PurchaseCardResult::confirmed(
                providerTransactionId: 'P-TX-1',
                credentials: [
                    'username' => '123456',
                    'password' => '654321',
                ],
                providerCardReference: 'CARD-1',
                providerStatus: 'success',
            )
        );

        $result = $service->handle($transaction);

        $sale->refresh();
        $wallet->refresh();
        $transaction->refresh();

        $this->assertSame(
            ProviderTransactionStatus::CONFIRMED,
            $result->status
        );

        $this->assertSame(
            'confirmed',
            $transaction->status
        );

        $this->assertSame(
            'provider_confirmed',
            $sale->status
        );

        /*
         * Execution does NOT capture money yet.
         */
        $this->assertSame(
            '100000.0000',
            $wallet->balance
        );

        $this->assertSame(
            '850.0000',
            $wallet->reserved_balance
        );

        $this->assertSame(
            1,
            $transaction->attempt_count
        );
    }

    public function test_explicit_failure_is_recorded_without_releasing_money(): void
    {
        [$sale, $wallet, , $transaction] =
            $this->setupSale();

        $service = $this->serviceWithResult(
            PurchaseCardResult::failed(
                providerTransactionId: 'P-TX-2',
                providerStatus: 'rejected',
                errorCode: 'OUT_OF_STOCK',
                errorMessage: 'No cards available.'
            )
        );

        $service->handle($transaction);

        $sale->refresh();
        $wallet->refresh();
        $transaction->refresh();

        $this->assertSame(
            'failed',
            $transaction->status
        );

        $this->assertSame(
            'failed',
            $sale->status
        );

        $this->assertSame(
            '850.0000',
            $wallet->reserved_balance
        );
    }

    public function test_timeout_keeps_money_reserved(): void
    {
        [$sale, $wallet, , $transaction] =
            $this->setupSale();

        $service = $this->serviceWithResult(
            PurchaseCardResult::timeout(
                errorMessage: 'Provider timed out.'
            )
        );

        $service->handle($transaction);

        $sale->refresh();
        $wallet->refresh();
        $transaction->refresh();

        $this->assertSame(
            'timeout',
            $transaction->status
        );

        $this->assertSame(
            'timeout',
            $sale->status
        );

        $this->assertSame(
            '850.0000',
            $wallet->reserved_balance
        );
    }

    public function test_unknown_state_keeps_money_reserved(): void
    {
        [$sale, $wallet, , $transaction] =
            $this->setupSale();

        $service = $this->serviceWithResult(
            PurchaseCardResult::unknown(
                errorMessage: 'Connection lost.'
            )
        );

        $service->handle($transaction);

        $sale->refresh();
        $wallet->refresh();
        $transaction->refresh();

        $this->assertSame(
            'unknown',
            $transaction->status
        );

        $this->assertSame(
            'unknown_provider_state',
            $sale->status
        );

        $this->assertSame(
            '850.0000',
            $wallet->reserved_balance
        );
    }

    public function test_confirmed_without_credentials_becomes_unknown(): void
    {
        [$sale, $wallet, , $transaction] =
            $this->setupSale();

        $service = $this->serviceWithResult(
            new PurchaseCardResult(
                status:
                    ProviderTransactionStatus::CONFIRMED,
                providerTransactionId: 'P-TX-3',
                credentials: null,
                providerStatus: 'success'
            )
        );

        $result = $service->handle($transaction);

        $sale->refresh();
        $wallet->refresh();
        $transaction->refresh();

        $this->assertSame(
            ProviderTransactionStatus::UNKNOWN,
            $result->status
        );

        $this->assertSame(
            'unknown',
            $transaction->status
        );

        $this->assertSame(
            'unknown_provider_state',
            $sale->status
        );

        $this->assertSame(
            '850.0000',
            $wallet->reserved_balance
        );
    }
}
