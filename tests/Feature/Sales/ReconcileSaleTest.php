<?php

namespace Tests\Feature\Sales;

use App\Models\Network;
use App\Models\NetworkConnection;
use App\Models\NetworkOwner;
use App\Models\NetworkProduct;
use App\Models\ProviderTransaction;
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
use App\Services\Providers\PrepareProviderTransactionService;
use App\Services\Providers\ReconcileProviderTransactionService;
use App\Services\Sales\FinalizeConfirmedSaleService;
use App\Services\Sales\FinalizeFailedSaleService;
use App\Services\Sales\ReconcileSaleService;
use App\Services\Sales\ReserveSaleBalanceService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ReconcileSaleTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(
        TransactionStatusResult $reconciliationResult,
        string $transactionStatus = 'timeout',
        string $saleStatus = 'timeout',
    ): array {
        $user = User::factory()->create();

        $seller = Seller::query()->create([
            'user_id' => $user->id,
            'code' => 'SELLER-RECON-ORCH',
            'business_name' => 'Reconcile Orchestrator Seller',
        ]);

        $wallet = SellerWallet::query()->create([
            'seller_id' => $seller->id,
            'currency_code' => 'YER',
        ]);

        $wallet->balance = '100000.0000';
        $wallet->save();

        $owner = NetworkOwner::query()->create([
            'code' => 'OWNER-RECON-ORCH',
            'name' => 'Reconcile Orchestrator Owner',
        ]);

        $network = Network::query()->create([
            'network_owner_id' => $owner->id,
            'code' => 'NET-RECON-ORCH',
            'name' => 'Reconcile Orchestrator Network',
            'currency_code' => 'YER',
        ]);

        $connection = NetworkConnection::query()->create([
            'network_id' => $network->id,
            'driver' => 'reconcile-orchestrator-test',
            'name' => 'Reconcile Orchestrator Connection',
            'is_enabled' => true,
            'is_primary' => true,
        ]);

        $product = NetworkProduct::query()->create([
            'network_id' => $network->id,
            'code' => 'PRODUCT-RECON-ORCH-1000',
            'external_product_id' => 'EXT-RECON-ORCH-1000',
            'name' => 'Reconcile Orchestrator 1000',
            'face_value' => '1000.0000',
            'currency_code' => 'YER',
            'is_active' => true,
        ]);

        $sale = Sale::query()->create([
            'seller_id' => $seller->id,
            'seller_wallet_id' => $wallet->id,
            'network_id' => $network->id,
            'network_product_id' => $product->id,
            'reference_no' => 'SALE-RECON-ORCH-001',
            'idempotency_key' => 'sale-recon-orch-001',
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
            'sale:'.$sale->id.':reservation'
        );

        $transaction = app(
            PrepareProviderTransactionService::class
        )->handle(
            $sale,
            $connection
        );

        $transaction->status = $transactionStatus;
        $transaction->attempt_count =
            $transactionStatus === 'created' ? 0 : 1;

        if ($transactionStatus !== 'created') {
            $transaction->request_started_at = now();
        }

        $transaction->save();

        $sale->status = $saleStatus;
        $sale->save();

        $adapter = new class($reconciliationResult)
            implements ProviderAdapter {
            public int $purchaseCalls = 0;
            public int $checkCalls = 0;

            public function __construct(
                private readonly TransactionStatusResult $result
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

                throw new RuntimeException(
                    'Reconciliation must never call purchaseCard().'
                );
            }

            public function checkTransaction(
                NetworkConnection $connection,
                string $internalTransactionId,
                ?string $providerTransactionId = null
            ): TransactionStatusResult {
                $this->checkCalls++;

                return $this->result;
            }
        };

        $registry = new ProviderAdapterRegistry();

        $registry->register(
            'reconcile-orchestrator-test',
            $adapter
        );

        $service = new ReconcileSaleService(
            new ReconcileProviderTransactionService(
                $registry
            ),
            app(FinalizeConfirmedSaleService::class),
            app(FinalizeFailedSaleService::class),
        );

        return [
            $sale,
            $wallet,
            $transaction,
            $adapter,
            $service,
        ];
    }

    public function test_timeout_confirmed_completes_sale_without_repurchasing(): void
    {
        [
            $sale,
            $wallet,
            $transaction,
            $adapter,
            $service,
        ] = $this->scenario(
            new TransactionStatusResult(
                status: ProviderTransactionStatus::CONFIRMED,
                providerTransactionId: 'PROVIDER-RECON-CONFIRMED-1',
                providerCardReference: 'CARD-RECON-1',
                credentials: [
                    'username' => 'RECON-123456',
                    'password' => 'RECON-654321',
                ],
                providerStatus: 'success',
            )
        );

        $result = $service->handle($sale);

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

        $this->assertSame(0, $adapter->purchaseCalls);
        $this->assertSame(1, $adapter->checkCalls);

        $this->assertSame(
            1,
            $sale->soldCard()->count()
        );

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            1
        );

        /*
         * Completed reconciliation is idempotent and must not
         * contact the provider again.
         */
        $second = $service->handle($sale);

        $wallet->refresh();

        $this->assertTrue($second->completed);
        $this->assertSame(0, $adapter->purchaseCalls);
        $this->assertSame(1, $adapter->checkCalls);

        $this->assertSame(
            '99150.0000',
            $wallet->balance
        );

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            1
        );
    }

    public function test_timeout_failed_releases_without_debit_or_repurchasing(): void
    {
        [
            $sale,
            $wallet,
            $transaction,
            $adapter,
            $service,
        ] = $this->scenario(
            new TransactionStatusResult(
                status: ProviderTransactionStatus::FAILED,
                providerTransactionId: 'PROVIDER-RECON-FAILED-1',
                providerStatus: 'failed',
                errorCode: 'NOT_FOUND',
                errorMessage: 'Provider confirms failure.',
            )
        );

        $result = $service->handle($sale);

        $sale->refresh();
        $wallet->refresh();
        $transaction->refresh();

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
        $this->assertSame('failed', $transaction->status);

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

        $this->assertSame(0, $adapter->purchaseCalls);
        $this->assertSame(1, $adapter->checkCalls);

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            0
        );

        $this->assertDatabaseCount(
            'sold_cards',
            0
        );

        $second = $service->handle($sale);

        $wallet->refresh();

        $this->assertFalse($second->completed);
        $this->assertSame(0, $adapter->purchaseCalls);
        $this->assertSame(1, $adapter->checkCalls);

        $this->assertSame(
            '100000.0000',
            $wallet->balance
        );
    }

    public function test_unknown_reconciliation_keeps_money_reserved(): void
    {
        [
            $sale,
            $wallet,
            $transaction,
            $adapter,
            $service,
        ] = $this->scenario(
            new TransactionStatusResult(
                status: ProviderTransactionStatus::UNKNOWN,
                providerTransactionId: 'PROVIDER-RECON-UNKNOWN-1',
                providerStatus: 'pending',
                errorCode: 'UNKNOWN',
                errorMessage: 'Provider state remains unknown.',
            ),
            transactionStatus: 'unknown',
            saleStatus: 'unknown_provider_state',
        );

        $result = $service->handle($sale);

        $sale->refresh();
        $wallet->refresh();
        $transaction->refresh();

        $reservation = $sale->reservation()
            ->firstOrFail();

        $this->assertFalse($result->completed);
        $this->assertTrue(
            $result->requiresReconciliation
        );

        $this->assertSame(
            ProviderTransactionStatus::UNKNOWN,
            $result->providerStatus
        );

        $this->assertSame(
            'unknown_provider_state',
            $sale->status
        );

        $this->assertSame(
            'unknown',
            $transaction->status
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

        $this->assertSame(0, $adapter->purchaseCalls);
        $this->assertSame(1, $adapter->checkCalls);

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            0
        );

        $this->assertDatabaseCount(
            'sold_cards',
            0
        );
    }

    public function test_created_transaction_is_rejected_without_provider_call(): void
    {
        [
            $sale,
            $wallet,
            $transaction,
            $adapter,
            $service,
        ] = $this->scenario(
            new TransactionStatusResult(
                status: ProviderTransactionStatus::UNKNOWN,
            ),
            transactionStatus: 'created',
            saleStatus: 'balance_reserved',
        );

        try {
            $service->handle($sale);

            $this->fail(
                'Created transaction must not be reconciled.'
            );
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Created provider transaction must be processed before reconciliation.',
                $e->getMessage()
            );
        }

        $wallet->refresh();
        $transaction->refresh();

        $this->assertSame('created', $transaction->status);
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
    }

    public function test_processing_transaction_is_reconciled_via_check_only(): void
    {
        [
            $sale,
            $wallet,
            $transaction,
            $adapter,
            $service,
        ] = $this->scenario(
            new TransactionStatusResult(
                status: ProviderTransactionStatus::CONFIRMED,
                providerTransactionId:
                    'PROVIDER-PROCESSING-RECOVERED-1',
                providerCardReference:
                    'CARD-PROCESSING-RECOVERED-1',
                credentials: [
                    'username' => 'PROCESSING-123',
                    'password' => 'PROCESSING-456',
                ],
                providerStatus: 'success',
            ),
            transactionStatus: 'processing',
            saleStatus: 'processing_provider',
        );

        $result = $service->handle($sale);

        $sale->refresh();
        $wallet->refresh();
        $transaction->refresh();

        $this->assertTrue($result->completed);

        $this->assertSame(
            ProviderTransactionStatus::CONFIRMED,
            $result->providerStatus
        );

        $this->assertSame('completed', $sale->status);
        $this->assertSame(
            'confirmed',
            $transaction->status
        );

        /*
         * This is the critical crash-recovery invariant.
         */
        $this->assertSame(0, $adapter->purchaseCalls);
        $this->assertSame(1, $adapter->checkCalls);

        $this->assertSame(
            '99150.0000',
            $wallet->balance
        );

        $this->assertSame(
            '0.0000',
            $wallet->reserved_balance
        );

        $this->assertDatabaseCount(
            'sold_cards',
            1
        );

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            1
        );
    }
}
