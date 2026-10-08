<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Jobs\ReviewSaleJob;
use App\Models\ManualSaleReview;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\User;
use App\Providers\Contracts\ProviderAdapter;
use App\Providers\Data\TransactionStatusResult;
use App\Providers\Enums\ProviderTransactionStatus;
use App\Providers\Registry\ProviderAdapterRegistry;
use App\Services\Providers\PrepareProviderTransactionService;
use App\Services\Sales\ReconcileSaleService;
use App\Services\Sales\ReserveSaleBalanceService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualSaleReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_confirmation_is_read_only_and_finalizes_accounting_once(): void
    {
        [, $sale, $wallet] = $this->scenario();
        $payload = $this->payload();
        $id = $this->postJson($this->url($sale), $payload)->assertAccepted()->json('data.id');
        $this->postJson($this->url($sale), $payload)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('manual_sale_reviews', 1);
        Queue::assertPushed(ReviewSaleJob::class, fn ($job) => $job->reviewId === $id && $job->saleId === $sale->id);
        $adapter = $this->adapter();
        $adapter->shouldNotReceive('purchaseCard');
        $adapter->shouldReceive('checkTransaction')->once()->andReturn(new TransactionStatusResult(
            status: ProviderTransactionStatus::CONFIRMED, providerTransactionId: 'review-confirmed',
            credentials: ['username' => 'card-user', 'password' => 'card-secret'],
        ));
        $job = new ReviewSaleJob($id, $sale->id);
        $job->handle(app(ReconcileSaleService::class));
        $job->handle(app(ReconcileSaleService::class));
        $review = ManualSaleReview::query()->findOrFail($id);
        $this->assertSame('completed', $review->status);
        $this->assertSame('completed', $review->sale_status_after);
        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertNull($sale->providerTransaction()->firstOrFail()->manual_review_required_at);
        $this->assertSame('150.0000', $wallet->fresh()->balance);
        $this->assertSame('0.0000', $wallet->fresh()->reserved_balance);
        $this->assertDatabaseCount('seller_ledger_entries', 1);
        $this->assertDatabaseCount('sale_accounting_entries', 2);
        $this->assertDatabaseCount('audit_events', 2);
        $response = $this->getJson('/api/v1/admin/sales/'.$sale->id)->assertOk();
        $this->assertStringNotContainsString('card-secret', $response->getContent());
        $this->getJson($this->url($sale))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_unresolved_review_keeps_finances_and_automatic_escalation_unchanged(): void
    {
        [, $sale, $wallet] = $this->scenario();
        $id = $this->postJson($this->url($sale), $this->payload())->assertAccepted()->json('data.id');
        $adapter = $this->adapter();
        $adapter->shouldNotReceive('purchaseCard');
        $adapter->shouldReceive('checkTransaction')->once()->andReturn(new TransactionStatusResult(status: ProviderTransactionStatus::UNKNOWN));
        (new ReviewSaleJob($id, $sale->id))->handle(app(ReconcileSaleService::class));
        $this->assertSame('unknown_provider_state', $sale->fresh()->status);
        $this->assertSame('850.0000', $wallet->fresh()->reserved_balance);
        $this->assertSame('1000.0000', $wallet->fresh()->balance);
        $this->assertNotNull($sale->providerTransaction()->firstOrFail()->manual_review_required_at);
        $this->assertSame(5, $sale->providerTransaction()->firstOrFail()->reconciliation_attempt_count);
        $this->assertDatabaseCount('seller_ledger_entries', 0);
        $this->assertDatabaseCount('sale_accounting_entries', 0);
    }

    public function test_provider_failure_releases_reservation_without_debit(): void
    {
        [, $sale, $wallet] = $this->scenario();
        $id = $this->postJson($this->url($sale), $this->payload())->assertAccepted()->json('data.id');
        $adapter = $this->adapter();
        $adapter->shouldNotReceive('purchaseCard');
        $adapter->shouldReceive('checkTransaction')->once()->andReturn(new TransactionStatusResult(status: ProviderTransactionStatus::FAILED));
        (new ReviewSaleJob($id, $sale->id))->handle(app(ReconcileSaleService::class));
        $this->assertSame('failed', $sale->fresh()->status);
        $this->assertSame('released', $sale->reservation()->firstOrFail()->status);
        $this->assertSame('0.0000', $wallet->fresh()->reserved_balance);
        $this->assertSame('1000.0000', $wallet->fresh()->balance);
        $this->assertNull($sale->providerTransaction()->firstOrFail()->manual_review_required_at);
        $this->assertDatabaseCount('seller_ledger_entries', 0);
    }

    public function test_revoked_admin_cancels_queued_review_without_provider_call(): void
    {
        [$admin, $sale, $wallet] = $this->scenario();
        $id = $this->postJson($this->url($sale), $this->payload())->assertAccepted()->json('data.id');
        $admin->status = 'suspended';
        $admin->save();
        $adapter = $this->adapter();
        $adapter->shouldNotReceive('checkTransaction');
        $adapter->shouldNotReceive('purchaseCard');
        (new ReviewSaleJob($id, $sale->id))->handle(app(ReconcileSaleService::class));
        $this->assertSame('cancelled', ManualSaleReview::query()->findOrFail($id)->status);
        $this->assertSame('850.0000', $wallet->fresh()->reserved_balance);
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_pending_review_blocks_different_request_and_unstarted_purchase_cannot_be_reviewed(): void
    {
        [, $sale] = $this->scenario();
        $this->postJson($this->url($sale), $this->payload())->assertAccepted();
        $payload = $this->payload();
        $payload['idempotency_key'] = 'review-0002';
        $this->postJson($this->url($sale), $payload)->assertConflict();
        $payload = $this->payload();
        $payload['reason'] = 'Different review reason';
        $this->postJson($this->url($sale), $payload)->assertConflict();
        $this->assertDatabaseCount('manual_sale_reviews', 1);
    }

    public function test_admin_listing_filters_escalated_sales_and_seller_cannot_access_it(): void
    {
        [, $sale] = $this->scenario();
        $this->getJson('/api/v1/admin/sales?manual_review=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider.reconciliation_attempt_count', 5);
        $seller = $sale->seller()->firstOrFail();
        $seller->status = 'active';
        $seller->save();
        Sanctum::actingAs($seller->user()->firstOrFail(), ['account', 'seller', 'admin']);
        $this->getJson('/api/v1/admin/sales')->assertForbidden();
        $this->postJson($this->url($sale), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('manual_sale_reviews', 0);
    }

    public function test_unstarted_sale_and_state_injection_do_not_queue_review(): void
    {
        [, $sale] = $this->scenario();
        $payload = $this->payload();
        $payload['status'] = 'confirmed';
        $this->postJson($this->url($sale), $payload)->assertUnprocessable()->assertJsonValidationErrors('status');
        $sale->providerTransaction()->update(['status' => 'created', 'attempt_count' => 0, 'request_started_at' => null]);
        $this->postJson($this->url($sale), $this->payload())->assertConflict();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('manual_sale_reviews', 0);
    }

    public function test_interrupted_review_is_rediscovered_without_changing_finances(): void
    {
        [, $sale, $wallet] = $this->scenario();
        $id = $this->postJson($this->url($sale), $this->payload())->assertAccepted()->json('data.id');
        Queue::fake();
        $this->artisan('sales:recover-reviews')->assertSuccessful();
        Queue::assertNothingPushed();
        $review = ManualSaleReview::query()->findOrFail($id);
        $review->updated_at = now()->subMinutes(3);
        $review->save();
        $this->artisan('sales:recover-reviews')->assertSuccessful();
        Queue::assertPushed(ReviewSaleJob::class, 1);
        $this->assertSame('850.0000', $wallet->fresh()->reserved_balance);
        $this->assertDatabaseCount('seller_ledger_entries', 0);
    }

    public function test_recent_processing_review_waits_and_terminal_job_failure_retains_reservation(): void
    {
        [, $sale, $wallet] = $this->scenario();
        $sale->providerTransaction()->update(['status' => 'processing', 'request_started_at' => now()]);
        $id = $this->postJson($this->url($sale), $this->payload())->assertAccepted()->json('data.id');
        $adapter = $this->adapter();
        $adapter->shouldNotReceive('checkTransaction');
        $adapter->shouldNotReceive('purchaseCard');
        $job = (new ReviewSaleJob($id, $sale->id))->withFakeQueueInteractions();
        $job->handle(app(ReconcileSaleService::class));
        $job->assertReleased(120);
        $job->failed(new \RuntimeException('Untrusted secret error'));
        $this->assertSame('failed', ManualSaleReview::query()->findOrFail($id)->status);
        $this->assertSame('850.0000', $wallet->fresh()->reserved_balance);
        $this->assertDatabaseCount('seller_ledger_entries', 0);
        $this->assertDatabaseCount('audit_events', 2);
    }

    private function adapter(): mixed
    {
        $adapter = $this->mock(ProviderAdapter::class);
        app(ProviderAdapterRegistry::class)->register('review-test', $adapter);

        return $adapter;
    }

    private function scenario(): array
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        Sanctum::actingAs($admin, ['account', 'admin']);
        $seller = Seller::query()->create(['user_id' => User::factory()->create()->id, 'code' => 'seller']);
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => 'YER']);
        $wallet->balance = '1000';
        $wallet->save();
        $owner = NetworkOwner::query()->create(['code' => 'owner', 'name' => 'Owner']);
        $network = Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'network', 'name' => 'Network']);
        $connection = $network->connections()->create(['name' => 'Primary', 'driver' => 'review-test', 'is_enabled' => true]);
        $product = $network->products()->create(['code' => 'product', 'external_product_id' => 'day', 'name' => 'Day', 'face_value' => '1000', 'currency_code' => 'YER']);
        $sale = Sale::query()->create(['seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id, 'network_id' => $network->id,
            'network_product_id' => $product->id, 'reference_no' => 'sale', 'idempotency_key' => 'sale', 'currency_code' => 'YER']);
        app(SaleFinancialSnapshotService::class)->create($sale, '1000', '800', '150', '50');
        app(ReserveSaleBalanceService::class)->handle($sale, 'sale:'.$sale->id.':reservation');
        $transaction = app(PrepareProviderTransactionService::class)->handle($sale, $connection);
        $transaction->status = 'unknown';
        $transaction->attempt_count = 1;
        $transaction->reconciliation_attempt_count = 5;
        $transaction->manual_review_required_at = now();
        $transaction->request_started_at = now()->subMinutes(3);
        $transaction->save();
        $sale->status = 'unknown_provider_state';
        $sale->save();

        return [$admin, $sale, $wallet];
    }

    private function payload(): array
    {
        return ['idempotency_key' => 'review-0001', 'reason' => 'Verify provider evidence after escalation'];
    }

    private function url(Sale $sale): string
    {
        return '/api/v1/admin/sales/'.$sale->id.'/reviews';
    }
}
