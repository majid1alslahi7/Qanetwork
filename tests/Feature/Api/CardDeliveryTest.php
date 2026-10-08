<?php

namespace Tests\Feature\Api;

use App\Enums\SmsSubmissionStatus;
use App\Enums\UserRole;
use App\Jobs\SendCardDeliveryJob;
use App\Models\AuditEvent;
use App\Models\CardDelivery;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\SoldCard;
use App\Models\User;
use App\Services\Delivery\Contracts\SmsSender;
use App\Services\Delivery\Data\SmsSubmissionResult;
use App\Services\Delivery\SendCardDeliveryService;
use App\Services\Delivery\UnavailableSmsSender;
use App\Services\Sales\FinalizeConfirmedSaleService;
use App\Services\Sales\ReserveSaleBalanceService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CardDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_provider_records_encrypted_waiting_request_without_changing_sale(): void
    {
        $sale = $this->sale();
        Queue::fake();
        $response = $this->postJson($this->url($sale), $this->payload())->assertAccepted()
            ->assertJsonPath('data.status', 'awaiting_configuration')->assertJsonPath('data.recipient_hint', '****1234');
        $this->postJson($this->url($sale), $this->payload())->assertAccepted()->assertJsonPath('data.id', $response->json('data.id'));
        $this->getJson($this->url($sale))->assertOk()->assertJsonPath('data.attempt_count', 0);
        $delivery = $sale->delivery()->firstOrFail();
        $this->assertSame('+967771231234', $delivery->recipient_encrypted);
        $this->assertStringNotContainsString('+967771231234', DB::table('card_deliveries')->value('recipient_encrypted'));
        $this->assertStringNotContainsString('+967771231234', $delivery->toJson());
        $this->assertStringNotContainsString('card-secret', $response->getContent());
        $this->assertStringNotContainsString('+967771231234', AuditEvent::query()->get()->toJson());
        $this->assertDatabaseCount('card_deliveries', 1);
        $this->assertNull($sale->soldCard()->firstOrFail()->first_revealed_at);
        $this->unchangedFinances($sale);
        Queue::assertNothingPushed();
    }

    public function test_provider_acceptance_is_submitted_once_and_not_handset_delivery(): void
    {
        $sale = $this->sale();
        Queue::fake([SendCardDeliveryJob::class]);
        $sender = $this->sender();
        $id = $this->postJson($this->url($sale), $this->payload())->assertAccepted()->json('data.id');
        $sender->shouldReceive('submit')->once()->with('+967771231234', ['username' => 'card-user', 'password' => 'card-secret'], 'card-delivery:'.$id)
            ->andReturn(new SmsSubmissionResult(SmsSubmissionStatus::ACCEPTED, 'sms-reference'));
        $job = new SendCardDeliveryJob($id);
        $job->handle(app(SendCardDeliveryService::class));
        $job->handle(app(SendCardDeliveryService::class));
        $this->getJson($this->url($sale))->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.attempt_count', 1);
        $this->assertNotNull(CardDelivery::query()->findOrFail($id)->submitted_at);
        $this->assertStringNotContainsString('internal-secret', AuditEvent::query()->get()->toJson());
        Queue::assertPushed(SendCardDeliveryJob::class, fn ($job) => $job->deliveryId === $id && $job->queue === 'delivery');
        $this->unchangedFinances($sale);
    }

    #[DataProvider('finalResults')]
    public function test_non_retryable_provider_results_preserve_sale_and_do_not_resend(SmsSubmissionStatus $result, string $status): void
    {
        $sale = $this->sale();
        Queue::fake([SendCardDeliveryJob::class]);
        $sender = $this->sender();
        $id = $this->postJson($this->url($sale), $this->payload())->assertAccepted()->json('data.id');
        $sender->shouldReceive('submit')->once()->andReturn(new SmsSubmissionResult($result));
        $this->assertSame($status, app(SendCardDeliveryService::class)->handle($id)->status);
        app(SendCardDeliveryService::class)->handle($id);
        $this->unchangedFinances($sale);
    }

    public static function finalResults(): array
    {
        return [[SmsSubmissionStatus::REJECTED, 'failed'], [SmsSubmissionStatus::UNKNOWN, 'unknown']];
    }

    public function test_timeout_is_unknown_without_resend_and_without_raw_exception(): void
    {
        $sale = $this->sale();
        Queue::fake([SendCardDeliveryJob::class]);
        $sender = $this->sender();
        $id = $this->postJson($this->url($sale), $this->payload())->assertAccepted()->json('data.id');
        $sender->shouldReceive('submit')->once()->andThrow(new RuntimeException('card-secret +967771231234'));
        app(SendCardDeliveryService::class)->handle($id);
        app(SendCardDeliveryService::class)->handle($id);
        $response = $this->getJson($this->url($sale))->assertOk()->assertJsonPath('data.status', 'unknown');
        $this->assertStringNotContainsString('card-secret', $response->getContent());
        $this->unchangedFinances($sale);
    }

    public function test_confirmed_not_sent_retries_after_delay_and_stops_after_three_attempts(): void
    {
        $this->freezeTime();
        $sale = $this->sale();
        Queue::fake([SendCardDeliveryJob::class]);
        $sender = $this->sender();
        $id = $this->postJson($this->url($sale), $this->payload())->assertAccepted()->json('data.id');
        $sender->shouldReceive('submit')->times(3)->andReturn(new SmsSubmissionResult(SmsSubmissionStatus::RETRYABLE));
        $service = app(SendCardDeliveryService::class);
        $this->assertSame('retryable', $service->handle($id)->status);
        $this->assertSame(1, $service->handle($id)->attempt_count);
        $this->travel(60)->seconds();
        $this->assertSame(2, $service->handle($id)->attempt_count);
        $this->travel(120)->seconds();
        $this->assertSame('failed', $service->handle($id)->status);
        $this->assertSame('retry_limit', $service->handle($id)->failure_code);
        $this->unchangedFinances($sale);
    }

    public function test_revoked_requester_and_corrupt_card_do_not_send(): void
    {
        $sale = $this->sale();
        Queue::fake([SendCardDeliveryJob::class]);
        $sender = $this->sender();
        $sender->shouldNotReceive('submit');
        $id = $this->postJson($this->url($sale), $this->payload())->assertAccepted()->json('data.id');
        DB::table('sold_cards')->where('sale_id', $sale->id)->update(['credentials_encrypted' => 'damaged-secret']);
        $this->assertSame('card_unavailable', app(SendCardDeliveryService::class)->handle($id)->failure_code);
        $delivery = CardDelivery::query()->findOrFail($id);
        $delivery->status = 'queued';
        $delivery->save();
        $sale->seller()->firstOrFail()->user()->update(['status' => 'suspended']);
        $this->assertSame('cancelled', app(SendCardDeliveryService::class)->handle($id)->status);
        $this->unchangedFinances($sale);
    }

    public function test_different_recipient_or_key_cannot_request_second_message(): void
    {
        $sale = $this->sale();
        $this->postJson($this->url($sale), $this->payload())->assertAccepted();
        $this->postJson($this->url($sale), [...$this->payload(), 'recipient' => '+967771231235'])->assertConflict();
        $this->postJson($this->url($sale), [...$this->payload(), 'idempotency_key' => 'second-key'])->assertConflict();
        $this->assertDatabaseCount('card_deliveries', 1);
    }

    public function test_unfinished_or_unpaid_sale_cannot_request_delivery(): void
    {
        $sale = $this->sale();
        $sale->status = 'provider_confirmed';
        $sale->save();
        $this->postJson($this->url($sale), $this->payload())->assertConflict();
        $sale->status = 'completed';
        $sale->save();
        DB::table('seller_ledger_entries')->where('reference_id', $sale->id)->update(['amount' => '1']);
        $this->postJson($this->url($sale), $this->payload())->assertConflict();
        $this->assertDatabaseCount('card_deliveries', 0);
    }

    public function test_foreign_seller_admin_and_missing_scope_are_denied(): void
    {
        $sale = $this->sale();
        $user = User::factory()->create(['role' => UserRole::SELLER]);
        $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'foreign']);
        $seller->status = 'active';
        $seller->save();
        Sanctum::actingAs($user, ['account', 'seller']);
        $this->postJson($this->url($sale), $this->payload())->assertNotFound();
        $this->getJson($this->url($sale))->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account', 'admin']);
        $this->postJson($this->url($sale), $this->payload())->assertForbidden();
        Sanctum::actingAs($sale->seller()->firstOrFail()->user()->firstOrFail(), ['account']);
        $this->postJson($this->url($sale), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('card_deliveries', 0);
    }

    public function test_phone_and_protected_fields_are_validated(): void
    {
        $sale = $this->sale();
        $this->postJson($this->url($sale), [...$this->payload(), 'recipient' => '771231234'])->assertUnprocessable()
            ->assertJsonValidationErrors('recipient')->assertJsonPath('errors.recipient.0', 'Use an international phone number beginning with +.');
        $this->postJson($this->url($sale), [...$this->payload(), 'message' => 'custom'])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->assertDatabaseCount('card_deliveries', 0);
    }

    public function test_interrupted_submission_is_recovered_without_sending_again(): void
    {
        $this->freezeTime();
        $sale = $this->sale();
        Queue::fake([SendCardDeliveryJob::class]);
        $sender = $this->sender();
        $sender->shouldNotReceive('submit');
        $id = $this->postJson($this->url($sale), $this->payload())->assertAccepted()->json('data.id');
        $delivery = CardDelivery::query()->findOrFail($id);
        $delivery->status = 'sending';
        $delivery->started_at = now();
        $delivery->attempt_count = 1;
        $delivery->save();
        $this->assertSame('sending', app(SendCardDeliveryService::class)->handle($id)->status);
        $this->travel(121)->seconds();
        $this->artisan('delivery:recover')->expectsOutput('Queued 1 delivery recovery job(s).')->assertSuccessful();
        $this->assertSame('unknown', app(SendCardDeliveryService::class)->handle($id)->status);
        $this->unchangedFinances($sale);
    }

    public function test_waiting_request_is_recovered_when_provider_becomes_available(): void
    {
        $this->freezeTime();
        $sale = $this->sale();
        Queue::fake([SendCardDeliveryJob::class]);
        $id = $this->postJson($this->url($sale), $this->payload())->assertAccepted()->json('data.id');
        $this->travel(121)->seconds();
        $this->artisan('delivery:recover')->expectsOutput('Queued 0 delivery recovery job(s).')->assertSuccessful();
        $sender = $this->sender();
        $sender->shouldReceive('submit')->once()->andReturn(new SmsSubmissionResult(SmsSubmissionStatus::ACCEPTED));
        $this->artisan('delivery:recover')->expectsOutput('Queued 1 delivery recovery job(s).')->assertSuccessful();
        Queue::assertPushed(SendCardDeliveryJob::class, fn ($job) => $job->deliveryId === $id);
        $this->assertSame('submitted', app(SendCardDeliveryService::class)->handle($id)->status);
        $this->unchangedFinances($sale);
    }

    public function test_provider_removal_before_job_execution_keeps_attempt_count_zero(): void
    {
        $sale = $this->sale();
        Queue::fake([SendCardDeliveryJob::class]);
        $this->sender();
        $id = $this->postJson($this->url($sale), $this->payload())->assertAccepted()->json('data.id');
        $this->app->bind(SmsSender::class, UnavailableSmsSender::class);
        $delivery = app(SendCardDeliveryService::class)->handle($id);
        $this->assertSame('awaiting_configuration', $delivery->status);
        $this->assertSame(0, $delivery->attempt_count);
        $this->unchangedFinances($sale);
    }

    public function test_retryable_job_releases_until_the_persisted_retry_time(): void
    {
        $this->freezeTime();
        $sale = $this->sale();
        Queue::fake([SendCardDeliveryJob::class]);
        $sender = $this->sender();
        $id = $this->postJson($this->url($sale), $this->payload())->assertAccepted()->json('data.id');
        $sender->shouldReceive('submit')->once()->andReturn(new SmsSubmissionResult(SmsSubmissionStatus::RETRYABLE));
        $worker = \Mockery::mock(Job::class);
        $worker->shouldReceive('release')->once()->with(60);
        $job = new SendCardDeliveryJob($id);
        $job->setJob($worker);
        $job->handle(app(SendCardDeliveryService::class));
        $this->assertSame('retryable', CardDelivery::query()->findOrFail($id)->status);
        $this->unchangedFinances($sale);
    }

    public function test_delivery_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/seller/sales/missing/delivery', $this->payload())->assertUnauthorized();
        $this->getJson('/api/v1/seller/sales/missing/delivery')->assertUnauthorized();
    }

    public function test_invalid_recovery_limit_is_rejected(): void
    {
        $this->artisan('delivery:recover', ['--limit' => '1001'])->expectsOutput('The --limit option must be an integer between 1 and 1000.')->assertFailed();
    }

    private function sender(): MockInterface
    {
        $sender = $this->mock(SmsSender::class);
        $sender->shouldReceive('available')->andReturn(true);

        return $sender;
    }

    private function unchangedFinances(Sale $sale): void
    {
        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertSame('150.0000', $sale->wallet()->firstOrFail()->balance);
        $this->assertSame('0.0000', $sale->wallet()->firstOrFail()->reserved_balance);
        $this->assertDatabaseCount('sold_cards', 1);
        $this->assertDatabaseCount('provider_transactions', 1);
        $this->assertDatabaseCount('seller_ledger_entries', 1);
        $this->assertDatabaseCount('sale_accounting_entries', 2);
    }

    private function payload(): array
    {
        return ['recipient' => '+967771231234', 'idempotency_key' => 'delivery-0001'];
    }

    private function url(Sale $sale): string
    {
        return '/api/v1/seller/sales/'.$sale->id.'/delivery';
    }

    private function sale(): Sale
    {
        $user = User::factory()->create(['role' => UserRole::SELLER]);
        $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'seller']);
        $seller->status = 'active';
        $seller->save();
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => 'YER']);
        $wallet->balance = '1000';
        $wallet->save();
        $owner = NetworkOwner::query()->create(['code' => 'owner', 'name' => 'Owner']);
        $network = Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'network', 'name' => 'Network']);
        $product = $network->products()->create(['code' => 'product', 'external_product_id' => 'day', 'name' => 'Day', 'face_value' => '1000', 'currency_code' => 'YER']);
        $sale = Sale::query()->create(['seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id, 'network_id' => $network->id,
            'network_product_id' => $product->id, 'reference_no' => 'SALE-TEST', 'idempotency_key' => 'sale-test', 'currency_code' => 'YER']);
        app(SaleFinancialSnapshotService::class)->create($sale, '1000', '800', '150', '50');
        app(ReserveSaleBalanceService::class)->handle($sale, 'sale:'.$sale->id.':reservation');
        $connection = $network->connections()->create(['name' => 'Test', 'driver' => 'fake']);
        $transaction = $sale->providerTransaction()->make();
        $transaction->network_connection_id = $connection->id;
        $transaction->internal_transaction_id = 'provider-test';
        $transaction->idempotency_key = 'provider:'.$sale->id;
        $transaction->status = 'confirmed';
        $transaction->save();
        $card = new SoldCard(['sale_id' => $sale->id, 'sold_at' => now()]);
        $card->setCredentials(['username' => 'card-user', 'password' => 'card-secret', 'provider_token' => 'internal-secret']);
        $card->save();
        $sale->status = 'provider_confirmed';
        $sale->save();
        app(FinalizeConfirmedSaleService::class)->handle($sale);
        Sanctum::actingAs($user, ['account', 'seller']);

        return $sale->fresh();
    }
}
