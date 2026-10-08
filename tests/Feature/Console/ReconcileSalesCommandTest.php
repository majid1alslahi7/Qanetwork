<?php

namespace Tests\Feature\Console;

use App\Jobs\ReconcileSaleJob;
use App\Models\Network;
use App\Models\NetworkConnection;
use App\Models\NetworkOwner;
use App\Models\NetworkProduct;
use App\Models\ProviderTransaction;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Providers\PrepareProviderTransactionService;
use App\Services\Sales\ReserveSaleBalanceService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReconcileSalesCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function transaction(
        string $status,
        int $reconciliationAttempts = 0,
        bool $manualReview = false,
    ): ProviderTransaction {
        $this->sequence++;

        $n = $this->sequence;

        $user = User::factory()->create();

        $seller = Seller::query()->create([
            'user_id' => $user->id,
            'code' => "SELLER-CMD-{$n}",
            'business_name' => "Command Seller {$n}",
        ]);

        $wallet = SellerWallet::query()->create([
            'seller_id' => $seller->id,
            'currency_code' => 'YER',
        ]);

        $wallet->balance = '100000.0000';
        $wallet->save();

        $owner = NetworkOwner::query()->create([
            'code' => "OWNER-CMD-{$n}",
            'name' => "Command Owner {$n}",
        ]);

        $network = Network::query()->create([
            'network_owner_id' => $owner->id,
            'code' => "NET-CMD-{$n}",
            'name' => "Command Network {$n}",
            'currency_code' => 'YER',
        ]);

        $connection = NetworkConnection::query()->create([
            'network_id' => $network->id,
            'driver' => "command-test-{$n}",
            'name' => "Command Connection {$n}",
            'is_enabled' => true,
            'is_primary' => true,
        ]);

        $product = NetworkProduct::query()->create([
            'network_id' => $network->id,
            'code' => "PRODUCT-CMD-{$n}",
            'external_product_id' => "EXT-CMD-{$n}",
            'name' => "Command Product {$n}",
            'face_value' => '1000.0000',
            'currency_code' => 'YER',
            'is_active' => true,
        ]);

        $sale = Sale::query()->create([
            'seller_id' => $seller->id,
            'seller_wallet_id' => $wallet->id,
            'network_id' => $network->id,
            'network_product_id' => $product->id,
            'reference_no' => "SALE-CMD-{$n}",
            'idempotency_key' => "sale-command-{$n}",
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

        $transaction->status = $status;
        $transaction->attempt_count =
            $status === 'created' ? 0 : 1;

        $transaction->reconciliation_attempt_count =
            $reconciliationAttempts;

        if ($status !== 'created') {
            $transaction->request_started_at = now();
        }

        if ($manualReview) {
            $transaction->manual_review_required_at = now();
        }

        $transaction->save();

        $sale->status = match ($status) {
            'processing' => 'processing_provider',
            'timeout' => 'timeout',
            'unknown' => 'unknown_provider_state',
            'reconciliation_required' => 'reconciliation_required',
            'confirmed' => 'provider_confirmed',
            'failed' => 'failed',
            default => 'balance_reserved',
        };

        $sale->save();

        return $transaction->fresh();
    }

    public function test_command_dispatches_only_eligible_uncertain_sales(): void
    {
        Queue::fake();

        $processing = $this->transaction('processing');
        $processing->request_started_at = now()->subMinutes(3);
        $processing->save();
        $liveProcessing = $this->transaction('processing');
        $timeout = $this->transaction('timeout');
        $unknown = $this->transaction('unknown');

        $reconciliationRequired = $this->transaction(
            'reconciliation_required'
        );

        $confirmed = $this->transaction('confirmed');
        $failed = $this->transaction('failed');
        $created = $this->transaction('created');

        $manualReview = $this->transaction(
            'unknown',
            2,
            true
        );

        $maxAttempts = $this->transaction(
            'timeout',
            5
        );

        $this->artisan('sales:reconcile')
            ->expectsOutput(
                'Queued 4 sale(s) for reconciliation.'
            )
            ->assertSuccessful();

        foreach ([
            $processing,
            $timeout,
            $unknown,
            $reconciliationRequired,
        ] as $transaction) {
            Queue::assertPushed(
                ReconcileSaleJob::class,
                fn (ReconcileSaleJob $job): bool => $job->saleId === $transaction->sale_id
            );
        }

        foreach ([
            $confirmed,
            $failed,
            $created,
            $manualReview,
            $maxAttempts,
            $liveProcessing,
        ] as $transaction) {
            Queue::assertNotPushed(
                ReconcileSaleJob::class,
                fn (ReconcileSaleJob $job): bool => $job->saleId === $transaction->sale_id
            );
        }

        Queue::assertPushed(
            ReconcileSaleJob::class,
            4
        );

        /*
         * Discovery must not perform reconciliation itself.
         */
        foreach ([
            $processing,
            $timeout,
            $unknown,
            $reconciliationRequired,
        ] as $transaction) {
            $transaction->refresh();

            $this->assertSame(
                0,
                $transaction->reconciliation_attempt_count
            );

            $this->assertNull(
                $transaction->last_reconciliation_at
            );
        }

        $this->assertDatabaseCount(
            'seller_ledger_entries',
            0
        );

        $this->assertDatabaseCount(
            'sold_cards',
            0
        );
    }

    public function test_limit_caps_number_of_dispatched_jobs(): void
    {
        Queue::fake();

        $this->transaction('timeout');
        $this->transaction('unknown');
        $this->transaction('reconciliation_required');

        $this->artisan('sales:reconcile', [
            '--limit' => 2,
        ])
            ->expectsOutput(
                'Queued 2 sale(s) for reconciliation.'
            )
            ->assertSuccessful();

        Queue::assertPushed(
            ReconcileSaleJob::class,
            2
        );
    }

    public function test_invalid_limit_fails_without_dispatching(): void
    {
        Queue::fake();

        $this->transaction('timeout');

        $this->artisan('sales:reconcile', [
            '--limit' => 0,
        ])
            ->expectsOutput(
                'The --limit option must be an integer between 1 and 1000.'
            )
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_empty_candidate_set_succeeds_without_dispatching(): void
    {
        Queue::fake();

        $this->transaction('confirmed');
        $this->transaction('failed');
        $this->transaction('created');

        $this->artisan('sales:reconcile')
            ->expectsOutput(
                'Queued 0 sale(s) for reconciliation.'
            )
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
