<?php

namespace Tests\Feature\Sales;

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
use App\Services\Sales\FinalizeConfirmedSaleService;
use App\Services\Sales\FinalizeFailedSaleService;
use App\Services\Sales\ProcessSaleService;
use App\Services\Sales\ReserveSaleBalanceService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessSaleTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(
        PurchaseCardResult $providerResult
    ): array {
        $user = User::factory()->create();

        $seller = Seller::query()->create([
            'user_id' => $user->id,
            'code' => 'SELLER-PROCESS',
            'business_name' => 'Process Seller',
        ]);

        $wallet = SellerWallet::query()->create([
            'seller_id' => $seller->id,
            'currency_code' => 'YER',
        ]);

        $wallet->balance = '100000.0000';
        $wallet->save();

        $owner = NetworkOwner::query()->create([
            'code' => 'OWNER-PROCESS',
            'name' => 'Process Owner',
        ]);

        $network = Network::query()->create([
            'network_owner_id' => $owner->id,
            'code' => 'NET-PROCESS',
            'name' => 'Process Network',
            'currency_code' => 'YER',
        ]);

        $connection = NetworkConnection::query()->create([
            'network_id' => $network->id,
            'driver' => 'process-test',
            'name' => 'Process Test Connection',
            'is_enabled' => true,
            'is_primary' => true,
        ]);

        $product = NetworkProduct::query()->create([
            'network_id' => $network->id,
            'code' => 'PRODUCT-PROCESS-1000',
            'external_product_id' => 'EXT-PROCESS-1000',
            'name' => 'Process 1000',
            'face_value' => '1000.0000',
            'currency_code' => 'YER',
            'is_active' => true,
        ]);

        $sale = Sale::query()->create([
            'seller_id' => $seller->id,
            'seller_wallet_id' => $wallet->id,
            'network_id' => $network->id,
            'network_product_id' => $product->id,
            'reference_no' => 'SALE-PROCESS-001',
            'idempotency_key' => 'sale-process-001',
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

        $adapter = new class($providerResult)
            implements ProviderAdapter {
            public int $purchaseCalls = 0;

            public int $checkCalls = 0;

            public function __construct(
                private readonly PurchaseCardResult $result
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
                return '1000000.0000';
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
                $this->purchaseCalls++;

                return $this->result;
            }

            public function checkTransaction(
                NetworkConnection $connection,
                string $internalTransactionId,
                ?string $providerTransactionId = null
            ): TransactionStatusResult {
                $this->checkCalls++;

                throw new RuntimeException(
                    'ProcessSaleService must not reconcile automatically.'
                );
            }
        };

        $registry = new ProviderAdapterRegistry();
        $registry->register('process-test', $adapter);

        $service = new ProcessSaleService(
            app(ReserveSaleBalanceService::class),
            app(PrepareProviderTransactionService::class),
            new ExecuteProviderPurchaseService($registry),
            app(FinalizeConfirmedSaleService::class),
            app(FinalizeFailedSaleService::class),
        );

        return [
            $sale,
            $wallet,
            $connection,
            $adapter,
            $service,
        ];
    }

    public function test_confirmed_sale_completes_and_debits_once(): void
    {
        [
            $sale,
            $wallet,
            $connection,
            $adapter,
            $service,
        ] = $this->scenario(
            PurchaseCardResult::confirmed(
                providerTransactionId: 'PROVIDER-PROCESS-1',
                credentials: [
                    'username' => 'CARD-123456',
                    'password' => 'PASS-654321',
                ],
                providerCardReference: 'CARD-REF-PROCESS-1',
                providerStatus: 'success',
            )
        );

        $result = $service->handle(
            $sale,
            $connection
        );

        $sale->refresh();
        $wallet->refresh();

        $reservation = $sale->reservation()
            ->firstOrFail();

        $transaction = $sale->providerTransaction()
            ->firstOrFail();

        $soldCard = $sale->soldCard()
            ->firstOrFail();

        $this->assertTrue($result->completed);
        $this->assertFalse(
            $result->requiresReconciliation
        );

        $this->assertSame(
            ProviderTransactionStatus::CONFIRMED,
            $result->providerStatus
        );

        $this->assertSame('completed', $sale->status);
        $this->assertSame(
            'confirmed',
            $transaction->status
        );

        $this->assertSame(
            'captured',
            $reservation->status
        );

        $this->assertSame(
            '99150.0000',
            $wallet->balance
        );

        $this->assertSame(
            '0.0000',
            $wallet->reserved_balance
        );

        $this->assertSame(1, $adapter->purchaseCalls);
        $this->assertSame(0, $adapter->checkCalls);

        $this->assertSame(
            'CARD-REF-PROCESS-1',
            $soldCard->provider_card_reference
        );

        /*
         * Running the orchestrator again must not purchase,
         * debit, or create another card.
         */
        $second = $service->handle(
            $sale,
            $connection
        );

        $wallet->refresh();

        $this->assertTrue($second->completed);
        $this->assertSame(1, $adapter->purchaseCalls);

        $this->assertSame(
            '99150.0000',
            $wallet->balance
        );

        $this->assertSame(
            1,
            $sale->soldCard()->count()
        );

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            1
        );
    }

    public function test_explicit_failure_releases_without_debit(): void
    {
        [
            $sale,
            $wallet,
            $connection,
            $adapter,
            $service,
        ] = $this->scenario(
            PurchaseCardResult::failed(
                providerTransactionId: 'PROVIDER-FAIL-1',
                providerStatus: 'declined',
                errorCode: 'NO_CARD',
                errorMessage: 'Provider rejected purchase.',
            )
        );

        $result = $service->handle(
            $sale,
            $connection
        );

        $sale->refresh();
        $wallet->refresh();

        $reservation = $sale->reservation()
            ->firstOrFail();

        $this->assertFalse($result->completed);
        $this->assertFalse(
            $result->requiresReconciliation
        );

        $this->assertSame(
            ProviderTransactionStatus::FAILED,
            $result->providerStatus
        );

        $this->assertSame('failed', $sale->status);

        $this->assertSame(
            'released',
            $reservation->status
        );

        $this->assertSame(
            '100000.0000',
            $wallet->balance
        );

        $this->assertSame(
            '0.0000',
            $wallet->reserved_balance
        );

        $this->assertSame(1, $adapter->purchaseCalls);

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            0
        );

        $this->assertDatabaseCount(
            'sold_cards',
            0
        );

        /*
         * Explicit failure is terminal and idempotent.
         */
        $second = $service->handle($sale);

        $wallet->refresh();

        $this->assertFalse($second->completed);
        $this->assertSame(1, $adapter->purchaseCalls);

        $this->assertSame(
            '100000.0000',
            $wallet->balance
        );
    }

    public function test_timeout_keeps_reservation_and_never_repurchases(): void
    {
        [
            $sale,
            $wallet,
            $connection,
            $adapter,
            $service,
        ] = $this->scenario(
            PurchaseCardResult::timeout(
                providerTransactionId: 'PROVIDER-TIMEOUT-1'
            )
        );

        $first = $service->handle(
            $sale,
            $connection
        );

        $sale->refresh();
        $wallet->refresh();

        $reservation = $sale->reservation()
            ->firstOrFail();

        $this->assertFalse($first->completed);
        $this->assertTrue(
            $first->requiresReconciliation
        );

        $this->assertSame(
            ProviderTransactionStatus::TIMEOUT,
            $first->providerStatus
        );

        $this->assertSame('timeout', $sale->status);

        $this->assertSame(
            'reserved',
            $reservation->status
        );

        $this->assertSame(
            '100000.0000',
            $wallet->balance
        );

        $this->assertSame(
            '850.0000',
            $wallet->reserved_balance
        );

        $this->assertSame(1, $adapter->purchaseCalls);

        /*
         * Critical invariant:
         * calling ProcessSaleService again must NOT call
         * purchaseCard() again.
         */
        $second = $service->handle($sale);

        $wallet->refresh();

        $this->assertTrue(
            $second->requiresReconciliation
        );

        $this->assertSame(
            ProviderTransactionStatus::TIMEOUT,
            $second->providerStatus
        );

        $this->assertSame(1, $adapter->purchaseCalls);
        $this->assertSame(0, $adapter->checkCalls);

        $this->assertSame(
            '850.0000',
            $wallet->reserved_balance
        );

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            0
        );

        $this->assertDatabaseCount(
            'sold_cards',
            0
        );
    }

    public function test_unknown_keeps_reservation_and_never_repurchases(): void
    {
        [
            $sale,
            $wallet,
            $connection,
            $adapter,
            $service,
        ] = $this->scenario(
            PurchaseCardResult::unknown(
                providerTransactionId: 'PROVIDER-UNKNOWN-1',
                providerStatus: 'uncertain',
                errorMessage: 'Unknown provider state.',
            )
        );

        $first = $service->handle(
            $sale,
            $connection
        );

        $sale->refresh();
        $wallet->refresh();

        $reservation = $sale->reservation()
            ->firstOrFail();

        $this->assertFalse($first->completed);
        $this->assertTrue(
            $first->requiresReconciliation
        );

        $this->assertSame(
            ProviderTransactionStatus::UNKNOWN,
            $first->providerStatus
        );

        $this->assertSame(
            'unknown_provider_state',
            $sale->status
        );

        $this->assertSame(
            'reserved',
            $reservation->status
        );

        $this->assertSame(
            '100000.0000',
            $wallet->balance
        );

        $this->assertSame(
            '850.0000',
            $wallet->reserved_balance
        );

        $this->assertSame(1, $adapter->purchaseCalls);

        $second = $service->handle($sale);

        $wallet->refresh();

        $this->assertTrue(
            $second->requiresReconciliation
        );

        $this->assertSame(
            ProviderTransactionStatus::UNKNOWN,
            $second->providerStatus
        );

        $this->assertSame(1, $adapter->purchaseCalls);
        $this->assertSame(0, $adapter->checkCalls);

        $this->assertSame(
            '850.0000',
            $wallet->reserved_balance
        );

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            0
        );

        $this->assertDatabaseCount(
            'sold_cards',
            0
        );
    }


    public function test_existing_created_transaction_is_executed_once(): void
    {
        [
            $sale,
            $wallet,
            $connection,
            $adapter,
            $service,
        ] = $this->scenario(
            PurchaseCardResult::confirmed(
                providerTransactionId: 'PROVIDER-CREATED-RECOVERY-1',
                credentials: [
                    'username' => 'RECOVERED-123456',
                    'password' => 'RECOVERED-654321',
                ],
                providerCardReference:
                    'CARD-REF-CREATED-RECOVERY-1',
                providerStatus: 'success',
            )
        );

        /*
         * Simulate a process stopping after the local provider
         * transaction was created but before purchaseCard()
         * started.
         */
        app(ReserveSaleBalanceService::class)->handle(
            $sale,
            'sale:'.$sale->id.':reservation'
        );

        $transaction = app(
            PrepareProviderTransactionService::class
        )->handle(
            $sale,
            $connection
        );

        $originalTransactionId = $transaction->id;
        $originalInternalId =
            $transaction->internal_transaction_id;

        $this->assertSame(
            'created',
            $transaction->status
        );

        $this->assertSame(0, $adapter->purchaseCalls);

        $result = $service->handle(
            $sale,
            $connection
        );

        $sale->refresh();
        $wallet->refresh();
        $transaction->refresh();

        $reservation = $sale->reservation()
            ->firstOrFail();

        $this->assertTrue($result->completed);

        $this->assertFalse(
            $result->requiresReconciliation
        );

        $this->assertSame(
            ProviderTransactionStatus::CONFIRMED,
            $result->providerStatus
        );

        $this->assertSame(
            'completed',
            $sale->status
        );

        $this->assertSame(
            'confirmed',
            $transaction->status
        );

        /*
         * The persisted transaction identity must be reused.
         */
        $this->assertSame(
            $originalTransactionId,
            $transaction->id
        );

        $this->assertSame(
            $originalInternalId,
            $transaction->internal_transaction_id
        );

        $this->assertSame(
            1,
            $sale->providerTransaction()->count()
        );

        $this->assertSame(
            1,
            $adapter->purchaseCalls
        );

        $this->assertSame(
            0,
            $adapter->checkCalls
        );

        $this->assertSame(
            'captured',
            $reservation->status
        );

        $this->assertSame(
            '99150.0000',
            $wallet->balance
        );

        $this->assertSame(
            '0.0000',
            $wallet->reserved_balance
        );

        $this->assertSame(
            1,
            $sale->soldCard()->count()
        );

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            1
        );

        /*
         * A later duplicate call is terminal/idempotent.
         * It must not contact the provider again.
         */
        $second = $service->handle(
            $sale,
            $connection
        );

        $wallet->refresh();

        $this->assertTrue($second->completed);

        $this->assertSame(
            1,
            $adapter->purchaseCalls
        );

        $this->assertSame(
            '99150.0000',
            $wallet->balance
        );

        $this->assertSame(
            1,
            $sale->providerTransaction()->count()
        );

        $this->assertSame(
            1,
            $sale->soldCard()->count()
        );

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            1
        );
    }


    public function test_processing_transaction_is_never_blindly_reexecuted(): void
    {
        [
            $sale,
            $wallet,
            $connection,
            $adapter,
            $service,
        ] = $this->scenario(
            PurchaseCardResult::confirmed(
                providerTransactionId: 'NEVER-CALLED',
                credentials: [
                    'username' => 'NEVER',
                    'password' => 'NEVER',
                ],
            )
        );

        /*
         * Build the local state immediately before the external
         * provider call, then simulate a process crash after the
         * transaction became PROCESSING.
         */
        app(ReserveSaleBalanceService::class)->handle(
            $sale,
            'sale:'.$sale->id.':reservation'
        );

        $transaction = app(
            PrepareProviderTransactionService::class
        )->handle(
            $sale,
            $connection
        );

        $transaction->status = 'processing';
        $transaction->attempt_count = 1;
        $transaction->request_started_at = now();
        $transaction->save();

        $sale->status = 'processing_provider';
        $sale->save();

        try {
            $service->handle($sale);

            $this->fail(
                'Processing transaction must not be re-executed.'
            );
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Existing provider transaction cannot be executed again; reconciliation is required.',
                $e->getMessage()
            );
        }

        $wallet->refresh();
        $sale->refresh();

        $this->assertSame(0, $adapter->purchaseCalls);
        $this->assertSame(0, $adapter->checkCalls);

        $this->assertSame(
            '100000.0000',
            $wallet->balance
        );

        $this->assertSame(
            '850.0000',
            $wallet->reserved_balance
        );

        $this->assertSame(
            'processing_provider',
            $sale->status
        );

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            0
        );

        $this->assertDatabaseCount(
            'sold_cards',
            0
        );
    }
}
