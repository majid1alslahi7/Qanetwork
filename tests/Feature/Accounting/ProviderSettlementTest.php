<?php

namespace Tests\Feature\Accounting;

use App\Enums\UserRole;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\ProviderSettlement;
use App\Models\Sale;
use App\Models\SaleAccountingEntry;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Accounting\RecordProviderSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ProviderSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_payments_allocate_exactly_once_and_cannot_exceed_remaining_balance(): void
    {
        $owner = $this->owner();
        $ownerUser = User::factory()->create(['role' => UserRole::NETWORK_OWNER]);
        $owner->user_id = $ownerUser->id;
        $owner->save();
        $first = $this->payable($owner, '800');
        $second = $this->payable($owner, '400');
        $this->admin();
        $payload = $this->payload('900');
        $response = $this->postJson($this->url($owner), $payload)->assertCreated();
        $response->assertJsonCount(2, 'data.allocations');
        $id = $response->json('data.id');
        $this->assertSame($id, $ownerUser->notifications()->sole()->data['target_id']);
        $this->assertSame('settlement', $ownerUser->notifications()->sole()->data['kind']);
        $allocations = ProviderSettlement::query()->findOrFail($id)->allocations()->get();
        $this->assertSame('800.0000', $allocations->firstWhere('sale_accounting_entry_id', $first->id)->amount);
        $this->assertSame('100.0000', $allocations->firstWhere('sale_accounting_entry_id', $second->id)->amount);
        $this->postJson($this->url($owner), $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('provider_settlements', 1);
        $this->assertDatabaseCount('provider_settlement_allocations', 2);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertSame(1, $ownerUser->notifications()->count());
        $this->getJson('/api/v1/admin/owners/'.$owner->id.'/balance')->assertOk()
            ->assertJsonPath('data.currencies.0.accrued', '1200.0000')->assertJsonPath('data.currencies.0.settled', '900.0000')
            ->assertJsonPath('data.currencies.0.outstanding', '300.0000');
        $next = $this->payload('300.0001');
        $next['external_reference'] = 'second-transfer';
        $next['idempotency_key'] = 'payment-0002';
        $this->postJson($this->url($owner), $next)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame(1, $ownerUser->notifications()->count());
        $this->assertDatabaseCount('provider_settlements', 1);
        $this->assertDatabaseCount('provider_settlement_allocations', 2);
        $next['amount'] = '300';
        $this->postJson($this->url($owner), $next)->assertCreated()->assertJsonCount(1, 'data.allocations');
        $this->assertDatabaseCount('provider_settlements', 2);
        $this->assertDatabaseCount('provider_settlement_allocations', 3);
        $this->assertSame('800.0000', $first->fresh()->amount);
        $this->getJson('/api/v1/admin/owners/'.$owner->id.'/balance')->assertOk()->assertJsonPath('data.currencies.0.outstanding', '0.0000');
    }

    public function test_transfer_reference_cannot_be_recorded_twice_with_a_new_key(): void
    {
        $owner = $this->owner();
        $this->payable($owner, '1000');
        $this->admin();
        $payload = $this->payload('100');
        $this->postJson($this->url($owner), $payload)->assertCreated();
        $payload['idempotency_key'] = 'different-request';
        $this->postJson($this->url($owner), $payload)->assertUnprocessable()->assertJsonValidationErrors('external_reference');
        $payload['idempotency_key'] = 'payment-0001';
        $payload['amount'] = '200';
        $this->postJson($this->url($owner), $payload)->assertConflict();
        $this->assertDatabaseCount('provider_settlements', 1);
    }

    public function test_other_owner_or_currency_cannot_fund_a_settlement(): void
    {
        $owner = $this->owner();
        $this->payable($this->owner(), '1000');
        $this->payable($owner, '1000', 'SAR');
        $this->admin();
        $this->postJson($this->url($owner), $this->payload('100'))->assertUnprocessable();
        $this->assertDatabaseCount('provider_settlements', 0);
        $this->assertDatabaseCount('provider_settlement_allocations', 0);
    }

    public function test_owner_reads_own_settlements_without_admin_notes_and_cannot_record_payments(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $this->payable($owner, '1000');
        $this->payable($other, '1000');
        $this->admin();
        $id = $this->postJson($this->url($owner), $this->payload('100'))->assertCreated()->json('data.id');
        $foreignId = $this->postJson($this->url($other), $this->payload('100'))->assertCreated()->json('data.id');
        $this->getJson($this->url($other).'/'.$id)->assertNotFound();
        Sanctum::actingAs($owner->user()->firstOrFail(), ['account', 'network_owner']);
        $this->getJson('/api/v1/owner/settlements')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/owner/accounting/balance')->assertOk()->assertJsonPath('data.currencies.0.outstanding', '900.0000');
        $response = $this->getJson('/api/v1/owner/settlements/'.$id)->assertOk();
        $this->assertArrayNotHasKey('notes', $response->json('data'));
        $this->getJson('/api/v1/owner/settlements/'.$foreignId)->assertNotFound();
        $this->postJson($this->url($owner), $this->payload('100'))->assertForbidden();
    }

    public function test_invalid_money_future_payment_and_allocation_injection_are_rejected(): void
    {
        $owner = $this->owner();
        $this->admin();
        $payload = $this->payload('0');
        $payload['paid_at'] = now()->addDay()->format('Y-m-d\TH:i:sP');
        $payload['allocations'] = ['entry_id' => 'arbitrary'];
        $this->postJson($this->url($owner), $payload)->assertUnprocessable()->assertJsonValidationErrors(['amount', 'paid_at', 'allocations']);
        $this->assertDatabaseCount('provider_settlements', 0);
    }

    public function test_recorded_payment_is_immutable(): void
    {
        $owner = $this->owner();
        $this->payable($owner, '1000');
        $this->admin();
        $id = $this->postJson($this->url($owner), $this->payload('100'))->assertCreated()->json('data.id');
        $this->expectException(LogicException::class);
        ProviderSettlement::query()->findOrFail($id)->update(['amount' => '200']);
    }

    public function test_replay_detects_missing_allocation_without_recording_another_payment(): void
    {
        $owner = $this->owner();
        $this->payable($owner, '1000');
        $this->admin();
        $payload = $this->payload('100');
        $id = $this->postJson($this->url($owner), $payload)->assertCreated()->json('data.id');
        DB::table('provider_settlement_allocations')->where('provider_settlement_id', $id)->delete();
        $this->expectException(RuntimeException::class);
        try {
            app(RecordProviderSettlementService::class)->handle(User::query()->where('role', 'admin')->firstOrFail(), $owner, $payload);
        } finally {
            $this->assertDatabaseCount('provider_settlements', 1);
            $this->assertDatabaseCount('audit_events', 1);
        }
    }

    private function owner(): NetworkOwner
    {
        $owner = new NetworkOwner(['code' => 'OWN-'.Str::ulid(), 'name' => 'Owner']);
        $owner->user_id = User::factory()->create(['role' => UserRole::NETWORK_OWNER])->id;
        $owner->status = 'active';
        $owner->save();

        return $owner;
    }

    private function payable(NetworkOwner $owner, string $amount, string $currency = 'YER'): SaleAccountingEntry
    {
        $seller = Seller::query()->create(['user_id' => User::factory()->create()->id, 'code' => 'SEL-'.Str::ulid()]);
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => $currency]);
        $network = Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'NET-'.Str::ulid(), 'name' => 'Network']);
        $product = $network->products()->create(['code' => 'PRD-'.Str::ulid(), 'external_product_id' => 'day', 'name' => 'Day', 'face_value' => '1000', 'currency_code' => $currency]);
        $sale = Sale::query()->create(['seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id, 'network_id' => $network->id,
            'network_product_id' => $product->id, 'reference_no' => 'SALE-'.Str::ulid(), 'idempotency_key' => (string) Str::ulid(), 'currency_code' => $currency]);

        return SaleAccountingEntry::query()->create(['sale_id' => $sale->id, 'network_owner_id' => $owner->id,
            'entry_type' => 'provider_payable', 'amount' => $amount, 'currency_code' => $currency,
            'idempotency_key' => 'payable:'.$sale->id, 'posted_at' => now()]);
    }

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account', 'admin']);
    }

    private function payload(string $amount): array
    {
        return ['amount' => $amount, 'currency_code' => 'YER', 'payment_method' => 'bank_transfer',
            'external_reference' => 'transfer-0001', 'idempotency_key' => 'payment-0001',
            'paid_at' => now()->subHour()->format('Y-m-d\TH:i:sP'), 'notes' => 'Internal payment review'];
    }

    private function url(NetworkOwner $owner): string
    {
        return '/api/v1/admin/owners/'.$owner->id.'/settlements';
    }
}
