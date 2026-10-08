<?php

namespace Tests\Feature\Sales;

use App\Jobs\ReconcileSaleJob;
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
use App\Services\Providers\PrepareProviderTransactionService;
use App\Services\Providers\ReconcileProviderTransactionService;
use App\Services\Sales\FinalizeConfirmedSaleService;
use App\Services\Sales\FinalizeFailedSaleService;
use App\Services\Sales\ReconcileSaleService;
use App\Services\Sales\ReserveSaleBalanceService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ReconcileSaleJobTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(
        TransactionStatusResult $reconciliationResult,
        int $reconciliationAttempts = 0,
        string $transactionStatus = 'timeout',
        string $saleStatus = 'timeout',
    ): array {
        $user = User::factory()->create();

        $seller = Seller::query()->create([
            'user_id' => $user->id,
            'code' => 'SELLER-RECON-JOB',
            'business_name' => 'Reconcile Job Seller',
        ]);

        $wallet = SellerWallet::query()->create([
            'seller_id' => $seller->id,
            'currency_code' => 'YER',
        ]);

        $wallet->balance = '100000.0000';
        $wallet->save();

        $owner = NetworkOwner::query()->create([
            'code' => 'OWNER-RECON-JOB',
            'name' => 'Reconcile Job Owner',
        ]);

        $network = Network::query()->create([
            'network_owner_id' => $owner->id,
            'code' => 'NET-RECON-JOB',
            'name' => 'Reconcile Job Network',
            'currency_code' => 'YER',
        ]);

        $connection = NetworkConnection::query()->create([
            'network_id' => $network->id,
            'driver' => 'reconcile-job-test',
            'name' => 'Reconcile Job Connection',
            'is_enabled' => true,
            'is_primary' => true,
        ]);

        $product = NetworkProduct::query()->create([
            'network_id' => $network->id,
            'code' => 'PRODUCT-RECON-JOB-1000',
            'external_product_id' => 'EXT-RECON-JOB-1000',
            'name' => 'Reconcile Job 1000',
            'face_value' => '1000.0000',
            'currency_code' => 'YER',
            'is_active' => true,
        ]);

        $sale = Sale::query()->create([
            'seller_id' => $seller->id,
            'seller_wallet_id' => $wallet->id,
            'network_id' => $network->id,
            'network_product_id' => $product->id,
            'reference_no' => 'SALE-RECON-JOB-001',
            'idempotency_key' => 'sale-recon-job-001',
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
        $transaction->attempt_count = 1;
        $transaction->reconciliation_attempt_count =
            $reconciliationAttempts;
        $transaction->request_started_at = now();
        $transaction->save();

        $sale->status = $saleStatus;
        $sale->save();

        $adapter = new class($reconciliationResult) implements ProviderAdapter
        {
            public int $purchaseCalls = 0;

            public int $checkCalls = 0;

            public function __construct(
                private readonly TransactionStatusResult $result
            ) {}

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
                    'Queued reconciliation must never purchase.'
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

        $registry = new ProviderAdapterRegistry;

        $registry->register(
            'reconcile-job-test',
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

    #[DataProvider('backoffProvider')]
    public function test_uncertain_result_uses_expected_backoff(
        int $existingAttempts,
        int $expectedAttempts,
        int $expectedDelay,
    ): void {
        [
            $sale,
            $wallet,
            $transaction,
            $adapter,
            $service,
        ] = $this->scenario(
            new TransactionStatusResult(
                status: ProviderTransactionStatus::UNKNOWN,
                providerTransactionId: 'PROVIDER-JOB-UNKNOWN',
                providerStatus: 'pending',
                errorCode: 'UNKNOWN',
                errorMessage: 'Still unknown.',
            ),
            reconciliationAttempts: $existingAttempts,
            transactionStatus: 'unknown',
            saleStatus: 'unknown_provider_state',
        );

        $job = (new ReconcileSaleJob($sale->id))
            ->withFakeQueueInteractions();

        $job->handle($service);

        $transaction->refresh();
        $wallet->refresh();

        $reservation = $sale->reservation()
            ->firstOrFail();

        $this->assertSame(
            $expectedAttempts,
            $transaction->reconciliation_attempt_count
        );

        $this->assertNotNull(
            $transaction->last_reconciliation_at
        );

        $this->assertNull(
            $transaction->manual_review_required_at
        );

        /*
         * Purchase attempt count is completely independent
         * from reconciliation attempts.
         */
        $this->assertSame(
            1,
            $transaction->attempt_count
        );

        $this->assertSame(0, $adapter->purchaseCalls);
        $this->assertSame(1, $adapter->checkCalls);

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

        $job->assertReleased($expectedDelay);

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            0
        );

        $this->assertDatabaseCount(
            'sold_cards',
            0
        );
    }

    public static function backoffProvider(): array
    {
        return [
            'attempt 1 = 60 seconds' => [
                0,
                1,
                60,
            ],
            'attempt 2 = 300 seconds' => [
                1,
                2,
                300,
            ],
            'attempt 3 = 900 seconds' => [
                2,
                3,
                900,
            ],
            'attempt 4 = 1800 seconds' => [
                3,
                4,
                1800,
            ],
        ];
    }

    public function test_recent_processing_waits_without_provider_call_or_reconciliation_attempt(): void
    {
        [$sale, $wallet, $transaction, $adapter, $service] = $this->scenario(
            new TransactionStatusResult(status: ProviderTransactionStatus::UNKNOWN),
            transactionStatus: 'processing', saleStatus: 'processing_provider',
        );
        $job = (new ReconcileSaleJob($sale->id))->withFakeQueueInteractions();
        $job->handle($service);
        $job->assertReleased(120);
        $this->assertSame(0, $adapter->checkCalls);
        $this->assertSame(0, $adapter->purchaseCalls);
        $this->assertSame(0, $transaction->fresh()->reconciliation_attempt_count);
        $this->assertSame('850.0000', $wallet->fresh()->reserved_balance);
    }

    public function test_fifth_uncertain_attempt_requires_manual_review_without_release(): void
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
                providerTransactionId: 'PROVIDER-JOB-FIFTH-UNKNOWN',
                providerStatus: 'pending',
                errorCode: 'UNKNOWN',
                errorMessage: 'Still unknown.',
            ),
            reconciliationAttempts: 4,
            transactionStatus: 'unknown',
            saleStatus: 'unknown_provider_state',
        );

        $job = (new ReconcileSaleJob($sale->id))
            ->withFakeQueueInteractions();

        $job->handle($service);

        $transaction->refresh();
        $wallet->refresh();

        $reservation = $sale->reservation()
            ->firstOrFail();

        $this->assertSame(
            5,
            $transaction->reconciliation_attempt_count
        );

        $this->assertSame(
            1,
            $transaction->attempt_count
        );

        $this->assertNotNull(
            $transaction->last_reconciliation_at
        );

        $this->assertNotNull(
            $transaction->manual_review_required_at
        );

        $this->assertSame(0, $adapter->purchaseCalls);
        $this->assertSame(1, $adapter->checkCalls);

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

        $job->assertNotReleased();

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            0
        );

        $this->assertDatabaseCount(
            'sold_cards',
            0
        );
    }

    public function test_confirmed_reconciliation_completes_without_release_or_repurchase(): void
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
                providerTransactionId: 'PROVIDER-JOB-CONFIRMED',
                providerCardReference: 'CARD-JOB-CONFIRMED',
                credentials: [
                    'username' => 'JOB-123456',
                    'password' => 'JOB-654321',
                ],
                providerStatus: 'success',
            )
        );

        $job = (new ReconcileSaleJob($sale->id))
            ->withFakeQueueInteractions();

        $job->handle($service);

        $sale->refresh();
        $wallet->refresh();
        $transaction->refresh();

        $reservation = $sale->reservation()
            ->firstOrFail();

        $this->assertSame(
            1,
            $transaction->reconciliation_attempt_count
        );

        $this->assertSame(
            1,
            $transaction->attempt_count
        );

        $this->assertSame(
            'confirmed',
            $transaction->status
        );

        $this->assertSame(
            'completed',
            $sale->status
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

        $job->assertNotReleased();

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            1
        );

        $this->assertDatabaseCount(
            'sold_cards',
            1
        );
    }

    public function test_failed_reconciliation_releases_reservation_without_debit(): void
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
                providerTransactionId: 'PROVIDER-JOB-FAILED',
                providerStatus: 'failed',
                errorCode: 'NOT_FOUND',
                errorMessage: 'Provider confirms failure.',
            )
        );

        $job = (new ReconcileSaleJob($sale->id))
            ->withFakeQueueInteractions();

        $job->handle($service);

        $sale->refresh();
        $wallet->refresh();
        $transaction->refresh();

        $reservation = $sale->reservation()
            ->firstOrFail();

        $this->assertSame(
            1,
            $transaction->reconciliation_attempt_count
        );

        $this->assertSame(
            1,
            $transaction->attempt_count
        );

        $this->assertSame(
            'failed',
            $transaction->status
        );

        $this->assertSame(
            'failed',
            $sale->status
        );

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

        $job->assertNotReleased();

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            0
        );

        $this->assertDatabaseCount(
            'sold_cards',
            0
        );
    }

    public function test_manual_review_marker_stops_future_automatic_reconciliation(): void
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
            reconciliationAttempts: 5,
            transactionStatus: 'unknown',
            saleStatus: 'unknown_provider_state',
        );

        $transaction->manual_review_required_at = now();
        $transaction->save();

        $job = (new ReconcileSaleJob($sale->id))
            ->withFakeQueueInteractions();

        $job->handle($service);

        $transaction->refresh();
        $wallet->refresh();

        $this->assertSame(
            5,
            $transaction->reconciliation_attempt_count
        );

        $this->assertSame(
            1,
            $transaction->attempt_count
        );

        $this->assertNotNull(
            $transaction->manual_review_required_at
        );

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

        $job->assertNotReleased();

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
