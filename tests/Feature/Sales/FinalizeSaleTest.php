<?php

namespace Tests\Feature\Sales;

use App\Models\Network;
use App\Models\NetworkConnection;
use App\Models\NetworkOwner;
use App\Models\NetworkProduct;
use App\Models\ProviderTransaction;
use App\Models\Sale;
use App\Models\SoldCard;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\User;
use App\Providers\Data\PurchaseCardResult;
use App\Services\Sales\FinalizeConfirmedSaleService;
use App\Services\Sales\FinalizeFailedSaleService;
use App\Services\Sales\ReserveSaleBalanceService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FinalizeSaleTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(
        string $providerStatus,
        string $saleStatus
    ): array {
        $user = User::factory()->create();

        $seller = Seller::query()->create([
            'user_id' => $user->id,
            'code' => 'SELLER-FINAL',
            'business_name' => 'Final Seller',
        ]);

        $wallet = SellerWallet::query()->create([
            'seller_id' => $seller->id,
            'currency_code' => 'YER',
        ]);

        $wallet->balance = '100000.0000';
        $wallet->save();

        $owner = NetworkOwner::query()->create([
            'code' => 'OWNER-FINAL',
            'name' => 'Final Owner',
        ]);

        $network = Network::query()->create([
            'network_owner_id' => $owner->id,
            'code' => 'NET-FINAL',
            'name' => 'Final Network',
            'currency_code' => 'YER',
        ]);

        $connection = NetworkConnection::query()->create([
            'network_id' => $network->id,
            'name' => 'Primary',
            'driver' => 'fake',
            'base_url' => 'https://provider.example.test',
            'is_primary' => true,
            'is_enabled' => true,
        ]);

        $product = NetworkProduct::query()->create([
            'network_id' => $network->id,
            'external_product_id' => 'FINAL-1000',
            'code' => 'FINAL-CARD-1000',
            'name' => 'Final Card',
            'face_value' => '1000.0000',
            'currency_code' => 'YER',
        ]);

        $sale = Sale::query()->create([
            'seller_id' => $seller->id,
            'seller_wallet_id' => $wallet->id,
            'network_id' => $network->id,
            'network_product_id' => $product->id,
            'reference_no' => 'SALE-FINAL-001',
            'idempotency_key' => 'sale-final-001',
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

        $reservation = app(
            ReserveSaleBalanceService::class
        )->handle(
            $sale,
            'reserve-final-001'
        );

        $transaction = new ProviderTransaction();
        $transaction->sale_id = $sale->id;
        $transaction->network_connection_id = $connection->id;
        $transaction->internal_transaction_id = 'INT-FINAL-001';
        $transaction->idempotency_key =
            'provider-purchase:'.$sale->id;
        $transaction->provider_transaction_id = 'PROVIDER-TX-1';
        $transaction->status = $providerStatus;
        $transaction->attempt_count = 1;
        $transaction->request_started_at = now();

        if ($providerStatus === 'confirmed') {
            $transaction->confirmed_at = now();
        }

        if ($providerStatus === 'failed') {
            $transaction->failed_at = now();
        }

        $transaction->save();

        /*
         * A provider-confirmed sale must already have
         * its issued card durably encrypted before
         * accounting finalization starts.
         */
        if ($providerStatus === 'confirmed') {
            $soldCard = new SoldCard([
                'sale_id' => $sale->id,
                'provider_card_reference' =>
                    'PROVIDER-CARD-1',
                'sold_at' => now(),
            ]);

            $soldCard->setCredentials([
                'username' => '123456',
                'password' => '654321',
            ]);

            $soldCard->save();
        }

        $sale->status = $saleStatus;

        if ($saleStatus === 'provider_confirmed') {
            $sale->provider_confirmed_at = now();
        }

        if ($saleStatus === 'failed') {
            $sale->failed_at = now();
        }

        $sale->save();

        return [
            $sale,
            $wallet,
            $reservation,
            $transaction,
        ];
    }

    public function test_confirmed_sale_is_completed_and_debited_once(): void
    {
        [$sale, $wallet] = $this->scenario(
            'confirmed',
            'provider_confirmed'
        );

        $result = PurchaseCardResult::confirmed(
            providerTransactionId: 'PROVIDER-TX-1',
            credentials: [
                'username' => '123456',
                'password' => '654321',
            ],
            providerCardReference: 'CARD-FINAL-1'
        );

        $service = app(
            FinalizeConfirmedSaleService::class
        );

        $first = $service->handle($sale);
        $second = $service->handle($sale);

        $sale->refresh();
        $wallet->refresh();

        $this->assertSame($first->id, $second->id);
        $this->assertSame('completed', $sale->status);
        $this->assertNotNull($sale->completed_at);

        $this->assertSame(
            '99150.0000',
            $wallet->balance
        );

        $this->assertSame(
            '0.0000',
            $wallet->reserved_balance
        );

        $this->assertDatabaseCount('sold_cards', 1);
        $this->assertDatabaseCount(
            'seller_ledger_entries',
            1
        );

        $card = $sale->soldCard()->firstOrFail();

        $this->assertSame(
            '123456',
            $card->credentials()['username']
        );

        $this->assertSame(
            '654321',
            $card->credentials()['password']
        );

        /*
         * Verify plaintext secrets are not stored directly.
         */
        $raw = $card->getRawOriginal(
            'credentials_encrypted'
        );

        $this->assertStringNotContainsString(
            '123456',
            $raw
        );

        $this->assertStringNotContainsString(
            '654321',
            $raw
        );
    }

    public function test_failed_sale_releases_reservation_without_debit(): void
    {
        [$sale, $wallet, $reservation] =
            $this->scenario(
                'failed',
                'failed'
            );

        $service = app(
            FinalizeFailedSaleService::class
        );

        $service->handle($sale);
        $service->handle($sale);

        $wallet->refresh();
        $reservation->refresh();

        $this->assertSame(
            '100000.0000',
            $wallet->balance
        );

        $this->assertSame(
            '0.0000',
            $wallet->reserved_balance
        );

        $this->assertSame(
            'released',
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

    public function test_timeout_cannot_release_reservation(): void
    {
        [$sale, $wallet, $reservation] =
            $this->scenario(
                'timeout',
                'timeout'
            );

        try {
            app(FinalizeFailedSaleService::class)
                ->handle($sale);

            $this->fail(
                'Timeout must never release reservation.'
            );
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Reservation can be released only after confirmed provider failure.',
                $e->getMessage()
            );
        }

        $wallet->refresh();
        $reservation->refresh();

        $this->assertSame(
            '850.0000',
            $wallet->reserved_balance
        );

        $this->assertSame(
            'reserved',
            $reservation->status
        );
    }

    public function test_unknown_state_cannot_release_reservation(): void
    {
        [$sale, $wallet, $reservation] =
            $this->scenario(
                'unknown',
                'unknown_provider_state'
            );

        $this->expectException(
            RuntimeException::class
        );

        try {
            app(FinalizeFailedSaleService::class)
                ->handle($sale);
        } finally {
            $wallet->refresh();
            $reservation->refresh();

            $this->assertSame(
                '850.0000',
                $wallet->reserved_balance
            );

            $this->assertSame(
                'reserved',
                $reservation->status
            );
        }
    }


    public function test_confirmed_sale_without_durable_card_cannot_finalize(): void
    {
        [
            $sale,
            $wallet,
        ] = $this->scenario(
            saleStatus: 'provider_confirmed',
            providerStatus: 'confirmed',
        );

        /*
         * Simulate an invalid recovery state:
         * provider confirmation exists, but the durable card
         * is missing.
         */
        $sale->soldCard()->delete();

        $this->expectException(\RuntimeException::class);

        $this->expectExceptionMessage(
            'Confirmed provider transaction has no durable sold card.'
        );

        try {
            app(
                \App\Services\Sales\FinalizeConfirmedSaleService::class
            )->handle($sale);
        } finally {
            $wallet->refresh();
            $sale->refresh();

            $this->assertSame(
                '100000.0000',
                $wallet->balance
            );

            $this->assertSame(
                '850.0000',
                $wallet->reserved_balance
            );

            $this->assertSame(
                'provider_confirmed',
                $sale->status
            );

            $this->assertDatabaseCount(
                'seller_ledger_entries',
                0
            );

            $this->assertDatabaseMissing(
                'sold_cards',
                [
                    'sale_id' => $sale->id,
                ]
            );
        }
    }


    public function test_confirmed_card_survives_accounting_failure_and_retry_completes_sale(): void
    {
        [
            $sale,
            $wallet,
            $reservation,
        ] = $this->scenario(
            saleStatus: 'provider_confirmed',
            providerStatus: 'confirmed',
        );

        /*
         * The provider layer has already committed the issued card.
         */
        $soldCard = $sale->soldCard()->firstOrFail();

        $originalCredentials = $soldCard->credentials();

        $this->assertSame(
            [
                'username' => '123456',
                'password' => '654321',
            ],
            $originalCredentials
        );

        /*
         * Simulate corrupted/local accounting state after provider
         * confirmation. Capture must fail, but the previously
         * committed SoldCard must survive.
         */
        $wallet->reserved_balance = '0.0000';
        $wallet->save();

        try {
            app(
                \App\Services\Sales\FinalizeConfirmedSaleService::class
            )->handle($sale);

            $this->fail(
                'Finalization should fail when reserved balance is insufficient.'
            );
        } catch (\Throwable $e) {
            /*
             * The exact accounting exception type/message is not
             * important here. The durability invariants are.
             */
        }

        $wallet->refresh();
        $sale->refresh();
        $reservation->refresh();

        $this->assertSame(
            '100000.0000',
            $wallet->balance
        );

        $this->assertSame(
            '0.0000',
            $wallet->reserved_balance
        );

        $this->assertSame(
            'provider_confirmed',
            $sale->status
        );

        $this->assertSame(
            'reserved',
            $reservation->status
        );

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            0
        );

        /*
         * Most important invariant:
         * accounting rollback did NOT erase the provider-issued card.
         */
        $persistedCard = $sale->soldCard()->firstOrFail();

        $this->assertSame(
            $soldCard->id,
            $persistedCard->id
        );

        $this->assertSame(
            $originalCredentials,
            $persistedCard->credentials()
        );

        $rawCredentials = (string) $persistedCard
            ->getRawOriginal('credentials_encrypted');

        $this->assertStringNotContainsString(
            '123456',
            $rawCredentials
        );

        $this->assertStringNotContainsString(
            '654321',
            $rawCredentials
        );

        /*
         * Repair the local accounting inconsistency.
         * No provider purchase/reconciliation is required.
         */
        $wallet->reserved_balance = '850.0000';
        $wallet->save();

        $finalCard = app(
            \App\Services\Sales\FinalizeConfirmedSaleService::class
        )->handle($sale);

        $wallet->refresh();
        $sale->refresh();
        $reservation->refresh();

        /*
         * Same issued card; no replacement was created.
         */
        $this->assertSame(
            $persistedCard->id,
            $finalCard->id
        );

        $this->assertDatabaseCount(
            'sold_cards',
            1
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
            'captured',
            $reservation->status
        );

        $this->assertSame(
            'completed',
            $sale->status
        );

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            1
        );

        /*
         * A second finalization is idempotent.
         */
        $retryCard = app(
            \App\Services\Sales\FinalizeConfirmedSaleService::class
        )->handle($sale);

        $wallet->refresh();

        $this->assertSame(
            $finalCard->id,
            $retryCard->id
        );

        $this->assertSame(
            '99150.0000',
            $wallet->balance
        );

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            1
        );

        $this->assertDatabaseCount(
            'sold_cards',
            1
        );
    }

}
