<?php

namespace Tests\Feature\Providers;

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
use App\Providers\Data\ProviderProduct;
use App\Providers\Data\PurchaseCardRequest;
use App\Providers\Data\PurchaseCardResult;
use App\Providers\Data\TransactionStatusResult;
use App\Providers\Enums\ProviderTransactionStatus;
use App\Providers\Registry\ProviderAdapterRegistry;
use App\Services\Providers\PrepareProviderTransactionService;
use App\Services\Providers\ReconcileProviderTransactionService;
use App\Services\Sales\ReserveSaleBalanceService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ReconcileProviderTransactionTest extends TestCase
{
    use RefreshDatabase;

    public int $purchaseCalls = 0;
    public int $checkCalls = 0;

    private function scenario(
        string $transactionStatus = 'timeout',
        string $saleStatus = 'timeout'
    ): array {
        $user = User::factory()->create();

        $seller = Seller::query()->create([
            'user_id' => $user->id,
            'code' => 'SELLER-RECON',
            'business_name' => 'Reconciliation Seller',
        ]);

        $wallet = SellerWallet::query()->create([
            'seller_id' => $seller->id,
            'currency_code' => 'YER',
        ]);

        $wallet->balance = '100000.0000';
        $wallet->save();

        $owner = NetworkOwner::query()->create([
            'code' => 'OWNER-RECON',
            'name' => 'Reconciliation Owner',
        ]);

        $network = Network::query()->create([
            'network_owner_id' => $owner->id,
            'code' => 'NET-RECON',
            'name' => 'Reconciliation Network',
            'currency_code' => 'YER',
        ]);

        $connection = NetworkConnection::query()->create([
            'network_id' => $network->id,
            'name' => 'Primary',
            'driver' => 'reconciliation-test',
            'base_url' => 'https://provider.example.test',
            'is_primary' => true,
            'is_enabled' => true,
        ]);

        $product = NetworkProduct::query()->create([
            'network_id' => $network->id,
            'external_product_id' => 'RECON-1000',
            'code' => 'RECON-CARD-1000',
            'name' => 'Reconciliation Card',
            'face_value' => '1000.0000',
            'currency_code' => 'YER',
        ]);

        $sale = Sale::query()->create([
            'seller_id' => $seller->id,
            'seller_wallet_id' => $wallet->id,
            'network_id' => $network->id,
            'network_product_id' => $product->id,
            'reference_no' => 'SALE-RECON-001',
            'idempotency_key' => 'sale-recon-001',
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
            'reserve-recon-001'
        );

        $transaction = app(
            PrepareProviderTransactionService::class
        )->handle($sale, $connection);

        $transaction->status = $transactionStatus;
        $transaction->attempt_count = 1;
        $transaction->request_started_at = now();
        $transaction->save();

        $sale->status = $saleStatus;
        $sale->save();

        return [
            $sale,
            $wallet,
            $connection,
            $transaction,
        ];
    }

    private function serviceWithResult(
        TransactionStatusResult $result
    ): ReconcileProviderTransactionService {
        $test = $this;

        $adapter = new class($test, $result)
            implements ProviderAdapter {
            public function __construct(
                private ReconcileProviderTransactionTest $test,
                private TransactionStatusResult $result
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
                $this->test->purchaseCalls++;

                throw new RuntimeException(
                    'purchaseCard must never be called during reconciliation.'
                );
            }

            public function checkTransaction(
                NetworkConnection $connection,
                string $internalTransactionId,
                ?string $providerTransactionId = null
            ): TransactionStatusResult {
                $this->test->checkCalls++;

                return $this->result;
            }
        };

        $registry = new ProviderAdapterRegistry();
        $registry->register(
            'reconciliation-test',
            $adapter
        );

        return new ReconcileProviderTransactionService(
            $registry
        );
    }

    public function test_timeout_can_be_reconciled_as_confirmed(): void
    {
        [$sale, $wallet, , $transaction] =
            $this->scenario();

        $service = $this->serviceWithResult(
            new TransactionStatusResult(
                status:
                    ProviderTransactionStatus::CONFIRMED,
                providerTransactionId: 'PROVIDER-RECON-1',
                providerCardReference: 'CARD-RECON-1',
                credentials: [
                    'username' => '555111',
                    'password' => '999222',
                ],
                providerStatus: 'success'
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
         * Reconciliation itself does not capture yet.
         */
        $this->assertSame(
            '100000.0000',
            $wallet->balance
        );

        $this->assertSame(
            '850.0000',
            $wallet->reserved_balance
        );

        /*
         * The issued card is already durable even though
         * seller accounting has not been captured yet.
         */
        $soldCard = $sale->soldCard()
            ->firstOrFail();

        $this->assertSame(
            'CARD-RECON-1',
            $soldCard->provider_card_reference
        );

        $this->assertSame(
            [
                'username' => '555111',
                'password' => '999222',
            ],
            $soldCard->credentials()
        );

        $rawCredentials = (string) $soldCard
            ->getRawOriginal('credentials_encrypted');

        $this->assertStringNotContainsString(
            '555111',
            $rawCredentials
        );

        $this->assertStringNotContainsString(
            '999222',
            $rawCredentials
        );

        /*
         * Persisting the card does not debit the seller.
         */
        $this->assertDatabaseCount(
            'seller_ledger_entries',
            0
        );

        $this->assertSame(0, $this->purchaseCalls);
        $this->assertSame(1, $this->checkCalls);
    }

    public function test_timeout_can_be_reconciled_as_failed(): void
    {
        [$sale, $wallet, , $transaction] =
            $this->scenario();

        $service = $this->serviceWithResult(
            new TransactionStatusResult(
                status:
                    ProviderTransactionStatus::FAILED,
                providerTransactionId: 'PROVIDER-RECON-2',
                providerStatus: 'rejected',
                errorCode: 'NOT_ISSUED',
                errorMessage: 'Card was not issued.'
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

        /*
         * Financial release is deliberately separate.
         */
        $this->assertSame(
            '850.0000',
            $wallet->reserved_balance
        );

        $this->assertSame(0, $this->purchaseCalls);
        $this->assertSame(1, $this->checkCalls);
    }

    public function test_unknown_result_keeps_reservation(): void
    {
        [$sale, $wallet, , $transaction] =
            $this->scenario(
                'unknown',
                'unknown_provider_state'
            );

        $service = $this->serviceWithResult(
            new TransactionStatusResult(
                status:
                    ProviderTransactionStatus::UNKNOWN,
                errorCode: 'STILL_UNKNOWN',
                errorMessage: 'Status remains unknown.'
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

        $this->assertSame(0, $this->purchaseCalls);
        $this->assertSame(1, $this->checkCalls);
    }

    public function test_created_transaction_cannot_be_reconciled(): void
    {
        [, , , $transaction] = $this->scenario(
            'created',
            'balance_reserved'
        );

        $service = $this->serviceWithResult(
            new TransactionStatusResult(
                ProviderTransactionStatus::UNKNOWN
            )
        );

        $this->expectException(
            RuntimeException::class
        );

        $service->handle($transaction);
    }

    public function test_final_transaction_cannot_be_reconciled(): void
    {
        [, , , $transaction] = $this->scenario(
            'confirmed',
            'provider_confirmed'
        );

        $service = $this->serviceWithResult(
            new TransactionStatusResult(
                ProviderTransactionStatus::CONFIRMED,
                credentials: [
                    'username' => 'x',
                    'password' => 'y',
                ]
            )
        );

        $this->expectException(
            RuntimeException::class
        );

        $service->handle($transaction);
    }

    public function test_confirmed_without_credentials_stays_in_reconciliation(): void
    {
        [$sale, $wallet, , $transaction] =
            $this->scenario();

        $service = $this->serviceWithResult(
            new TransactionStatusResult(
                status:
                    ProviderTransactionStatus::CONFIRMED,
                providerTransactionId: 'PROVIDER-RECON-3',
                credentials: null,
                providerStatus: 'success'
            )
        );

        $result = $service->handle($transaction);

        $sale->refresh();
        $wallet->refresh();
        $transaction->refresh();

        $this->assertSame(
            ProviderTransactionStatus::RECONCILIATION_REQUIRED,
            $result->status
        );

        $this->assertSame(
            'reconciliation_required',
            $transaction->status
        );

        $this->assertSame(
            'reconciliation_required',
            $sale->status
        );

        $this->assertSame(
            '850.0000',
            $wallet->reserved_balance
        );

        $this->assertSame(0, $this->purchaseCalls);
        $this->assertSame(1, $this->checkCalls);
    }

    public function test_reconciliation_check_exception_never_releases_or_debits_money(): void
    {
        [$sale, $wallet, $connection, $transaction] =
            $this->scenario(
                'timeout',
                'timeout'
            );

        $test = $this;

        $adapter = new class($test)
            implements ProviderAdapter {
            public function __construct(
                private ReconcileProviderTransactionTest $test
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
                $this->test->purchaseCalls++;

                throw new RuntimeException(
                    'purchaseCard must never be called during reconciliation.'
                );
            }

            public function checkTransaction(
                NetworkConnection $connection,
                string $internalTransactionId,
                ?string $providerTransactionId = null
            ): TransactionStatusResult {
                $this->test->checkCalls++;

                throw new RuntimeException(
                    'Temporary provider connection failure.'
                );
            }
        };

        $registry = new ProviderAdapterRegistry();

        $registry->register(
            'reconciliation-test',
            $adapter
        );

        $service =
            new ReconcileProviderTransactionService(
                $registry
            );

        $result = $service->handle($transaction);

        $sale->refresh();
        $wallet->refresh();
        $transaction->refresh();

        $reservation = $sale->reservation()
            ->firstOrFail();

        $this->assertSame(
            ProviderTransactionStatus::RECONCILIATION_REQUIRED,
            $result->status
        );

        $this->assertSame(
            'reconciliation_required',
            $transaction->status
        );

        $this->assertSame(
            'reconciliation_required',
            $sale->status
        );

        /*
         * Seller money remains untouched and reserved.
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
            'reserved',
            $reservation->status
        );

        /*
         * No financial posting occurred.
         */
        $this->assertDatabaseCount(
            'seller_ledger_entries',
            0
        );

        $this->assertDatabaseCount(
            'sold_cards',
            0
        );

        /*
         * Most important invariant:
         *
         * reconciliation queried the old transaction exactly
         * once and NEVER attempted another purchase.
         */
        $this->assertSame(
            0,
            $this->purchaseCalls
        );

        $this->assertSame(
            1,
            $this->checkCalls
        );
    }


    public function test_adapter_exception_secrets_are_never_persisted(): void
    {
        [
            $sale,
            $wallet,
            $reservation,
            $transaction,
        ] = $this->scenario(
            'timeout',
            'timeout',
        );

        $secretToken = 'SECRET-TOKEN-DO-NOT-PERSIST';
        $secretCard = 'SECRET-CARD-998877';

        $adapter = new class(
            $secretToken,
            $secretCard
        ) implements \App\Providers\Contracts\ProviderAdapter {
            public function __construct(
                private readonly string $secretToken,
                private readonly string $secretCard,
            ) {
            }

            public function healthCheck(
                \App\Models\NetworkConnection $connection
            ): bool {
                return true;
            }

            public function getBalance(
                \App\Models\NetworkConnection $connection
            ): ?string {
                return null;
            }

            public function getProducts(
                \App\Models\NetworkConnection $connection
            ): array {
                return [];
            }

            public function checkAvailability(
                \App\Models\NetworkConnection $connection,
                string $externalProductId
            ): bool {
                return true;
            }

            public function purchaseCard(
                \App\Models\NetworkConnection $connection,
                \App\Providers\Data\PurchaseCardRequest $request
            ): \App\Providers\Data\PurchaseCardResult {
                throw new \RuntimeException(
                    'purchaseCard must never be called during reconciliation.'
                );
            }

            public function checkTransaction(
                \App\Models\NetworkConnection $connection,
                string $internalTransactionId,
                ?string $providerTransactionId = null
            ): \App\Providers\Data\TransactionStatusResult {
                throw new \RuntimeException(
                    'Authorization: Bearer '.$this->secretToken.
                    ' card='.$this->secretCard
                );
            }
        };

        $registry = new \App\Providers\Registry\ProviderAdapterRegistry();
        $registry->register('reconciliation-test', $adapter);

        $service = new \App\Services\Providers\ReconcileProviderTransactionService(
            $registry
        );

        $result = $service->handle($transaction);

        $this->assertSame(
            \App\Providers\Enums\ProviderTransactionStatus::RECONCILIATION_REQUIRED,
            $result->status
        );

        $transaction->refresh();
        $sale->refresh();
        $wallet->refresh();

        /*
         * Read the reservation from the sale relation instead
         * of depending on scenario() array position.
         */
        $reservation = $sale->reservation()
            ->firstOrFail();

        $this->assertSame(
            'reconciliation_required',
            $transaction->status
        );

        $this->assertSame(
            'reconciliation_required',
            $sale->status
        );

        $this->assertSame(
            'Provider adapter call failed; reconciliation required.',
            $transaction->error_message
        );

        $this->assertStringNotContainsString(
            $secretToken,
            (string) $transaction->error_message
        );

        $this->assertStringNotContainsString(
            $secretCard,
            (string) $transaction->error_message
        );

        /*
         * Search the raw provider transaction row too, not merely
         * the Eloquent attribute used above.
         */
        $rawRow = (array) \Illuminate\Support\Facades\DB::table(
            'provider_transactions'
        )->where(
            'id',
            $transaction->id
        )->first();

        $serializedRow = json_encode(
            $rawRow,
            JSON_THROW_ON_ERROR
        );

        $this->assertStringNotContainsString(
            $secretToken,
            $serializedRow
        );

        $this->assertStringNotContainsString(
            $secretCard,
            $serializedRow
        );

        /*
         * An adapter exception must not change seller money.
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
            'reserved',
            $reservation->status
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
