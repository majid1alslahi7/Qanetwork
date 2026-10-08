<?php

namespace Tests\Feature\Console;

use App\Jobs\ProcessSaleJob;
use App\Jobs\ReconcileSaleJob;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\ProviderTransaction;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\SoldCard;
use App\Models\User;
use App\Services\Providers\PrepareProviderTransactionService;
use App\Services\Sales\CaptureSaleReservationService;
use App\Services\Sales\ReconcileSaleService;
use App\Services\Sales\ReserveSaleBalanceService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecoverPendingSalesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_unstarted_purchase_is_recovered_without_modifying_finances_and_uncertain_states_are_excluded(): void
    {
        Queue::fake();
        $pending = $this->scenario('created');
        $this->scenario('timeout');
        $this->scenario('processing');
        $fresh = $this->scenario('created');
        $fresh->updated_at = now();
        $fresh->save();
        $started = $this->scenario('created');
        $started->request_started_at = now()->subMinutes(3);
        $started->save();
        $this->artisan('sales:recover')->expectsOutput('Queued 1 interrupted sale(s) for recovery.')->assertSuccessful();
        Queue::assertPushed(ProcessSaleJob::class, 1);
        Queue::assertPushed(ProcessSaleJob::class, fn ($job) => $job->saleId === $pending->sale_id);
        Queue::assertNotPushed(ReconcileSaleJob::class);
        $this->assertSame(0, $pending->fresh()->attempt_count);
        $this->assertSame('850.0000', $pending->sale()->firstOrFail()->wallet()->firstOrFail()->reserved_balance);
        $this->assertDatabaseCount('seller_ledger_entries', 0);
    }

    public function test_confirmed_local_capture_is_finalized_without_another_debit_or_provider_call(): void
    {
        Queue::fake();
        $transaction = $this->scenario('confirmed');
        $sale = $transaction->sale()->firstOrFail();
        $card = new SoldCard(['sale_id' => $sale->id, 'sold_at' => now()]);
        $card->setCredentials(['username' => 'card', 'password' => 'secret']);
        $card->save();
        app(CaptureSaleReservationService::class)->handle($sale->reservation()->firstOrFail(), 'sale:'.$sale->id.':seller-debit');
        $this->artisan('sales:recover')->assertSuccessful();
        Queue::assertPushed(ReconcileSaleJob::class, fn ($job) => $job->saleId === $sale->id);
        Queue::assertNotPushed(ProcessSaleJob::class);
        (new ReconcileSaleJob($sale->id))->handle(app(ReconcileSaleService::class));
        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertDatabaseCount('seller_ledger_entries', 1);
        $this->assertSame('150.0000', $sale->wallet()->firstOrFail()->balance);
    }

    public function test_explicit_failed_sale_with_reserved_balance_is_queued_for_local_release(): void
    {
        Queue::fake();
        $transaction = $this->scenario('failed');
        $this->artisan('sales:recover')->assertSuccessful();
        Queue::assertPushed(ReconcileSaleJob::class, 1);
        (new ReconcileSaleJob($transaction->sale_id))->handle(app(ReconcileSaleService::class));
        $sale = $transaction->sale()->firstOrFail();
        $this->assertSame('released', $sale->reservation()->firstOrFail()->status);
        $this->assertSame('0.0000', $sale->wallet()->firstOrFail()->reserved_balance);
        $this->assertDatabaseCount('seller_ledger_entries', 0);
    }

    public function test_invalid_limit_does_not_dispatch_any_jobs(): void
    {
        Queue::fake();
        $this->artisan('sales:recover', ['--limit' => 0])->assertFailed();
        Queue::assertNothingPushed();
    }

    private function scenario(string $status): ProviderTransaction
    {
        $seller = Seller::query()->create(['user_id' => User::factory()->create()->id, 'code' => 'SEL-'.Str::ulid()]);
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => 'YER']);
        $wallet->balance = '1000';
        $wallet->save();
        $owner = NetworkOwner::query()->create(['code' => 'OWN-'.Str::ulid(), 'name' => 'Owner']);
        $network = Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'NET-'.Str::ulid(), 'name' => 'Network']);
        $connection = $network->connections()->create(['name' => 'Primary', 'driver' => 'not-registered', 'is_enabled' => true]);
        $product = $network->products()->create(['code' => 'PRD-'.Str::ulid(), 'external_product_id' => 'day', 'name' => 'Day', 'face_value' => '1000', 'currency_code' => 'YER']);
        $sale = Sale::query()->create(['seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id, 'network_id' => $network->id,
            'network_product_id' => $product->id, 'reference_no' => 'SALE-'.Str::ulid(), 'idempotency_key' => (string) Str::ulid(), 'currency_code' => 'YER']);
        app(SaleFinancialSnapshotService::class)->create($sale, '1000', '800', '150', '50');
        app(ReserveSaleBalanceService::class)->handle($sale, 'sale:'.$sale->id.':reservation');
        $transaction = app(PrepareProviderTransactionService::class)->handle($sale, $connection);
        $transaction->status = $status;
        $transaction->attempt_count = $status === 'created' ? 0 : 1;
        $transaction->request_started_at = $status === 'created' ? null : now()->subMinutes(3);
        $transaction->updated_at = now()->subMinutes(3);
        $transaction->save();
        $sale->status = match ($status) {
            'confirmed' => 'provider_confirmed', 'failed' => 'failed', 'processing' => 'processing_provider',
            'timeout' => 'timeout', default => 'balance_reserved',
        };
        $sale->save();

        return $transaction;
    }
}
