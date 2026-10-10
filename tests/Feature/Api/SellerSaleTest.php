<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Jobs\ProcessSaleJob;
use App\Jobs\ReconcileSaleJob;
use App\Models\AuditEvent;
use App\Models\InventoryCard;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\NetworkProduct;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\User;
use App\Providers\Contracts\ProviderAdapter;
use App\Providers\Data\PurchaseCardResult;
use App\Providers\Registry\ProviderAdapterRegistry;
use App\Services\Pricing\PublishPricingRuleService;
use App\Services\Sales\ProcessSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SellerSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_reserves_exact_priced_balance_and_replay_does_not_duplicate_records(): void
    {
        [$wallet, $product] = $this->setupSeller();
        $payload = $this->payload($wallet, $product);
        $id = $this->postJson('/api/v1/seller/sales', $payload)->assertAccepted()
            ->assertJsonPath('data.status', 'balance_reserved')->assertJsonPath('data.financial.seller_net_amount', '850.0000')->json('data.id');
        $this->postJson('/api/v1/seller/sales', $payload)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_financials', 1);
        $this->assertDatabaseCount('sale_reservations', 1);
        $this->assertDatabaseCount('provider_transactions', 1);
        $this->assertSame('1000.0000', $wallet->fresh()->balance);
        $this->assertSame('850.0000', $wallet->fresh()->reserved_balance);
        $this->assertDatabaseCount('seller_ledger_entries', 0);
        Queue::assertPushed(ProcessSaleJob::class, fn ($job) => $job->saleId === $id && $job->queue === 'sales');
        $response = $this->getJson('/api/v1/seller/sales/'.$id)->assertOk();
        $this->assertArrayNotHasKey('credentials', $response->json('data'));
        $this->assertArrayNotHasKey('provider_amount', $response->json('data.financial'));
        $this->getJson('/api/v1/seller/sales')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_conflicting_replay_does_not_change_reservation(): void
    {
        [$wallet, $product] = $this->setupSeller();
        $payload = $this->payload($wallet, $product);
        $this->postJson('/api/v1/seller/sales', $payload)->assertAccepted();
        $payload['product_id'] = (string) Str::ulid();
        $this->postJson('/api/v1/seller/sales', $payload)->assertConflict();
        $this->assertSame('850.0000', $wallet->fresh()->reserved_balance);
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_insufficient_available_balance_rolls_back_sale_snapshot_and_transaction(): void
    {
        [$wallet, $product] = $this->setupSeller();
        $wallet->reserved_balance = '200';
        $wallet->save();
        $this->postJson('/api/v1/seller/sales', $this->payload($wallet, $product))->assertUnprocessable()->assertJsonValidationErrors('wallet_id');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_financials', 0);
        $this->assertDatabaseCount('provider_transactions', 0);
        $this->assertSame('200.0000', $wallet->fresh()->reserved_balance);
        Queue::assertNothingPushed();
    }

    #[DataProvider('unavailableCases')]
    public function test_unavailable_network_or_product_cannot_reserve_money(string $field): void
    {
        [$wallet, $product] = $this->setupSeller();
        $network = $product->network()->firstOrFail();
        if ($field === 'product') {
            $product->status = 'inactive';
            $product->save();
        } elseif ($field === 'stale') {
            $network->last_health_check_at = now()->subMinutes(6);
            $network->save();
        } elseif ($field === 'sales') {
            $network->sales_enabled = false;
            $network->save();
        } else {
            $connection = $network->connections()->firstOrFail();
            $connection->health_status = 'unhealthy';
            $connection->save();
        }
        $this->postJson('/api/v1/seller/sales', $this->payload($wallet, $product))->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame('0.0000', $wallet->fresh()->reserved_balance);
        Queue::assertNothingPushed();
    }

    public static function unavailableCases(): array
    {
        return [['product'], ['stale'], ['sales'], ['connection']];
    }

    public function test_seller_cannot_use_foreign_wallet_or_view_foreign_sale(): void
    {
        [$wallet, $product] = $this->setupSeller();
        $other = Seller::query()->create(['user_id' => User::factory()->create()->id, 'code' => 'other']);
        $foreignWallet = SellerWallet::query()->create(['seller_id' => $other->id, 'currency_code' => 'YER']);
        $this->postJson('/api/v1/seller/sales', $this->payload($foreignWallet, $product))->assertNotFound();
        $foreign = Sale::query()->create(['seller_id' => $other->id, 'seller_wallet_id' => $foreignWallet->id,
            'network_id' => $product->network_id, 'network_product_id' => $product->id,
            'reference_no' => 'foreign', 'idempotency_key' => 'foreign', 'currency_code' => 'YER']);
        $this->getJson('/api/v1/seller/sales/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/v1/seller/sales')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('0.0000', $wallet->fresh()->reserved_balance);
    }

    public function test_financial_input_is_rejected_and_missing_scope_cannot_create_sale(): void
    {
        [$wallet, $product] = $this->setupSeller();
        $payload = $this->payload($wallet, $product);
        $payload['seller_commission'] = '999';
        $this->postJson('/api/v1/seller/sales', $payload)->assertUnprocessable()->assertJsonValidationErrors('seller_commission');
        Sanctum::actingAs($wallet->seller()->firstOrFail()->user()->firstOrFail(), ['account']);
        $this->postJson('/api/v1/seller/sales', $this->payload($wallet, $product))->assertForbidden();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_job_sends_processing_transaction_to_reconciliation_without_repurchase(): void
    {
        [$wallet, $product] = $this->setupSeller();
        $id = $this->postJson('/api/v1/seller/sales', $this->payload($wallet, $product))->assertAccepted()->json('data.id');
        $sale = Sale::query()->findOrFail($id);
        $sale->providerTransaction()->update(['status' => 'processing']);
        $service = $this->mock(ProcessSaleService::class);
        $service->shouldNotReceive('handle');
        (new ProcessSaleJob($id))->handle($service);
        Queue::assertPushed(ReconcileSaleJob::class, fn ($job) => $job->saleId === $id);
        $this->assertSame('850.0000', $wallet->fresh()->reserved_balance);
    }

    public function test_job_completes_purchase_once_and_captures_reserved_balance(): void
    {
        [$wallet, $product] = $this->setupSeller();
        $payload = $this->payload($wallet, $product);
        $id = $this->postJson('/api/v1/seller/sales', $payload)->assertAccepted()->json('data.id');
        $adapter = $this->mock(ProviderAdapter::class);
        $adapter->shouldReceive('purchaseCard')->once()->andReturn(PurchaseCardResult::confirmed(
            providerTransactionId: 'confirmed-sale', credentials: ['username' => 'card-user', 'password' => 'card-secret'],
        ));
        app(ProviderAdapterRegistry::class)->register('test-provider', $adapter);
        $job = new ProcessSaleJob($id);
        $job->handle(app(ProcessSaleService::class));
        $job->handle(app(ProcessSaleService::class));
        $this->assertSame('completed', Sale::query()->findOrFail($id)->status);
        $this->assertSame('150.0000', $wallet->fresh()->balance);
        $this->assertSame('0.0000', $wallet->fresh()->reserved_balance);
        $this->assertDatabaseCount('sold_cards', 1);
        $this->assertDatabaseCount('seller_ledger_entries', 1);
        $this->postJson('/api/v1/seller/sales', $payload)->assertAccepted()->assertJsonPath('data.status', 'completed');
        Queue::assertPushed(ProcessSaleJob::class, 1);
        Queue::assertNotPushed(ReconcileSaleJob::class);
    }

    public function test_timeout_keeps_reservation_and_replay_does_not_repurchase(): void
    {
        [$wallet, $product] = $this->setupSeller();
        $payload = $this->payload($wallet, $product);
        $id = $this->postJson('/api/v1/seller/sales', $payload)->assertAccepted()->json('data.id');
        $adapter = $this->mock(ProviderAdapter::class);
        $adapter->shouldReceive('purchaseCard')->once()->andReturn(PurchaseCardResult::timeout());
        app(ProviderAdapterRegistry::class)->register('test-provider', $adapter);
        $job = new ProcessSaleJob($id);
        $job->handle(app(ProcessSaleService::class));
        $job->handle(app(ProcessSaleService::class));
        $this->postJson('/api/v1/seller/sales', $payload)->assertAccepted()->assertJsonPath('data.status', 'timeout');
        $this->assertSame('1000.0000', $wallet->fresh()->balance);
        $this->assertSame('850.0000', $wallet->fresh()->reserved_balance);
        $this->assertDatabaseCount('sold_cards', 0);
        $this->assertDatabaseCount('seller_ledger_entries', 0);
        Queue::assertPushed(ProcessSaleJob::class, 1);
        Queue::assertPushed(ReconcileSaleJob::class);
    }

    public function test_paid_card_reveal_is_audited_and_preserves_first_reveal_time(): void
    {
        $sale = $this->completedSale();
        $url = '/api/v1/seller/sales/'.$sale->id.'/card/reveal';
        $response = $this->postJson($url)->assertOk()->assertJsonPath('data.credentials.password', 'card-secret');
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
        $first = $response->json('data.first_revealed_at');
        $this->travel(30)->seconds();
        $this->postJson($url)->assertOk()->assertJsonPath('data.first_revealed_at', $first);
        $events = AuditEvent::query()->where('event_type', 'card.revealed')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertTrue($events[0]->after['first_reveal']);
        $this->assertFalse($events[1]->after['first_reveal']);
        $this->assertStringNotContainsString('card-secret', $events->toJson());
        $this->assertStringNotContainsString('card-secret', $this->getJson('/api/v1/seller/sales/'.$sale->id)->assertOk()->getContent());
        $this->assertDatabaseCount('seller_ledger_entries', 1);
    }

    public function test_unfinished_sale_cannot_reveal_card(): void
    {
        [$wallet, $product] = $this->setupSeller();
        $id = $this->postJson('/api/v1/seller/sales', $this->payload($wallet, $product))->assertAccepted()->json('data.id');
        $this->postJson('/api/v1/seller/sales/'.$id.'/card/reveal')->assertConflict();
        $this->assertSame(0, AuditEvent::query()->where('event_type', 'card.revealed')->count());
    }

    public function test_foreign_seller_cannot_reveal_card_or_change_reveal_timestamp(): void
    {
        $sale = $this->completedSale();
        $user = User::factory()->create();
        $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'reveal-other']);
        $seller->status = 'active';
        $seller->save();
        Sanctum::actingAs($user, ['account', 'seller']);
        $this->postJson('/api/v1/seller/sales/'.$sale->id.'/card/reveal')->assertNotFound();
        $this->assertNull($sale->soldCard()->firstOrFail()->first_revealed_at);
        $this->assertSame(0, AuditEvent::query()->where('event_type', 'card.revealed')->count());
    }

    public function test_invalid_accounting_prevents_reveal_even_when_sale_is_marked_completed(): void
    {
        $sale = $this->completedSale();
        DB::table('seller_ledger_entries')->where('reference_id', $sale->id)->update(['amount' => '1']);
        $response = $this->postJson('/api/v1/seller/sales/'.$sale->id.'/card/reveal')->assertConflict();
        $this->assertStringNotContainsString('card-secret', $response->getContent());
        $this->assertNull($sale->soldCard()->firstOrFail()->first_revealed_at);
    }

    public function test_corrupt_ciphertext_is_not_returned_or_marked_revealed(): void
    {
        $sale = $this->completedSale();
        DB::table('sold_cards')->where('sale_id', $sale->id)->update(['credentials_encrypted' => 'damaged-secret']);
        $response = $this->postJson('/api/v1/seller/sales/'.$sale->id.'/card/reveal')->assertServiceUnavailable();
        $this->assertStringNotContainsString('damaged-secret', $response->getContent());
        $this->assertNull($sale->soldCard()->firstOrFail()->first_revealed_at);
        $this->assertSame(0, AuditEvent::query()->where('event_type', 'card.revealed')->count());
    }

    public function test_reveal_does_not_expose_unrecognized_provider_fields(): void
    {
        $sale = $this->completedSale();
        $card = $sale->soldCard()->firstOrFail();
        $card->setCredentials(['username' => 'card-user', 'password' => 'card-secret', 'provider_token' => 'internal-provider-secret']);
        $card->save();
        $response = $this->postJson('/api/v1/seller/sales/'.$sale->id.'/card/reveal')->assertOk();
        $this->assertSame(['username', 'password'], array_keys($response->json('data.credentials')));
        $this->assertStringNotContainsString('internal-provider-secret', $response->getContent());
    }

    public function test_explicit_inventory_source_is_used_without_fallback_and_replay_keeps_original_source(): void
    {
        [$wallet, $product] = $this->setupSeller();
        $connection = $product->network->connections()->create(['name' => 'Inventory', 'driver' => 'stored_cards', 'is_enabled' => true, 'is_primary' => false]);
        $connection->health_status = 'healthy';
        $connection->last_checked_at = now();
        $connection->save();
        $product->fulfillment_connection_id = $connection->id;
        $product->save();
        $payload = $this->payload($wallet, $product);
        $this->postJson('/api/v1/seller/sales', $payload)->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->assertDatabaseCount('sales', 0);
        InventoryCard::factory()->create(['network_id' => $product->network_id, 'network_product_id' => $product->id]);
        $id = $this->postJson('/api/v1/seller/sales', $payload)->assertAccepted()->json('data.id');
        $this->assertDatabaseHas('provider_transactions', ['sale_id' => $id, 'network_connection_id' => $connection->id]);
        $product->fulfillment_connection_id = null;
        $product->save();
        $this->postJson('/api/v1/seller/sales', $payload)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->assertDatabaseHas('provider_transactions', ['sale_id' => $id, 'network_connection_id' => $connection->id]);
        $this->assertDatabaseCount('provider_transactions', 1);
    }

    private function completedSale(): Sale
    {
        [$wallet, $product] = $this->setupSeller();
        $id = $this->postJson('/api/v1/seller/sales', $this->payload($wallet, $product))->assertAccepted()->json('data.id');
        $adapter = $this->mock(ProviderAdapter::class);
        $adapter->shouldReceive('purchaseCard')->once()->andReturn(PurchaseCardResult::confirmed(
            providerTransactionId: 'reveal-sale', credentials: ['username' => 'card-user', 'password' => 'card-secret'],
        ));
        app(ProviderAdapterRegistry::class)->register('test-provider', $adapter);
        (new ProcessSaleJob($id))->handle(app(ProcessSaleService::class));

        return Sale::query()->findOrFail($id);
    }

    private function setupSeller(): array
    {
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::SELLER]);
        $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'seller']);
        $seller->status = 'active';
        $seller->save();
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => 'YER']);
        $wallet->balance = '1000';
        $wallet->save();
        $owner = NetworkOwner::query()->create(['code' => 'owner', 'name' => 'Owner']);
        $owner->status = 'active';
        $owner->save();
        $network = Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'network', 'name' => 'Network']);
        $network->status = 'active';
        $network->sales_enabled = true;
        $network->health_status = 'healthy';
        $network->last_health_check_at = now();
        $network->save();
        $connection = $network->connections()->create(['name' => 'Primary', 'driver' => 'test-provider', 'is_primary' => true, 'is_enabled' => true]);
        $connection->health_status = 'healthy';
        $connection->last_checked_at = now();
        $connection->save();
        $product = $network->products()->create(['code' => 'product', 'external_product_id' => 'day', 'name' => 'Day', 'face_value' => '1000', 'currency_code' => 'YER']);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        app(PublishPricingRuleService::class)->handle($admin, $product, '800', '150');
        Sanctum::actingAs($user, ['account', 'seller']);

        return [$wallet, $product];
    }

    private function payload(SellerWallet $wallet, NetworkProduct $product): array
    {
        return ['product_id' => $product->id, 'wallet_id' => $wallet->id, 'idempotency_key' => 'request-0001'];
    }
}
