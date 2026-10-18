<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\ProviderSettlement;
use App\Models\Sale;
use App\Models\SaleAccountingEntry;
use App\Models\Seller;
use App\Models\SellerLedgerEntry;
use App\Models\SellerWallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountStatementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_statement_has_exact_opening_movements_closing_and_private_account_scope(): void
    {
        [$user, $wallet] = $this->seller();
        $this->entry($wallet, 'credit', '1000', '1000', '2026-10-13');
        $this->entry($wallet, 'debit', '93', '907', '2026-10-16');
        $this->entry($wallet, 'credit', '0.0001', '907.0001', '2026-10-17');
        [$other, $foreignWallet] = $this->seller();
        $this->entry($foreignWallet, 'credit', '99999', '99999', '2026-10-16');
        Sanctum::actingAs($user, ['account', 'seller']);
        $response = $this->getJson('/api/v1/seller/statement?from=2026-10-15&to=2026-10-18')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.basis', 'wallet_ledger')->assertJsonPath('summary.account.id', $user->id)
            ->assertJsonPath('summary.currencies.0.opening_balance', '1000.0000')->assertJsonPath('summary.currencies.0.credits', '0.0001')
            ->assertJsonPath('summary.currencies.0.debits', '93.0000')->assertJsonPath('summary.currencies.0.closing_balance', '907.0001')
            ->assertJsonPath('data.0.balance_after', '907.0000')->assertJsonPath('data.1.balance_after', '907.0001');
        $this->assertStringNotContainsString('99999', $response->getContent());
        $this->getJson('/api/v1/seller/statement?from=2026-10-15&to=2026-10-18&seller_id='.$foreignWallet->seller_id)->assertUnprocessable();
        $this->getJson('/api/v1/admin/accounts/'.$other->id.'/statement?from=2026-10-15&to=2026-10-18')->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account', 'admin']);
        $this->getJson('/api/v1/admin/accounts/'.$user->id.'/statement?from=2026-10-15&to=2026-10-18')->assertOk()->assertJsonPath('summary.currencies.0.closing_balance', '907.0001');
    }

    public function test_owner_statement_subtracts_settlements_and_admin_statement_only_contains_platform_revenue(): void
    {
        [$seller, $wallet] = $this->seller();
        $user = User::factory()->create(['role' => UserRole::NETWORK_OWNER]);
        $owner = NetworkOwner::query()->create(['user_id' => $user->id, 'code' => 'OWN-'.Str::ulid(), 'name' => 'Owner']);
        $owner->user_id = $user->id;
        $owner->status = 'active';
        $owner->save();
        $network = Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'NET-'.Str::ulid(), 'name' => 'Network']);
        $product = $network->products()->create(['code' => 'PRD-'.Str::ulid(), 'external_product_id' => 'day', 'name' => 'Day', 'face_value' => '100']);
        $sale = Sale::query()->create(['seller_id' => $wallet->seller_id, 'seller_wallet_id' => $wallet->id, 'network_id' => $network->id, 'network_product_id' => $product->id, 'reference_no' => 'SALE-'.Str::ulid(), 'idempotency_key' => (string) Str::ulid(), 'currency_code' => 'YER']);
        foreach ([['provider_payable', '200', '2026-10-13'], ['provider_payable', '100', '2026-10-16'], ['platform_revenue', '8', '2026-10-16']] as [$type, $amount, $date]) {
            $currentSale = $sale->replicate(['reference_no', 'idempotency_key']);
            $currentSale->forceFill(['reference_no' => 'SALE-'.Str::ulid(), 'idempotency_key' => (string) Str::ulid()])->save();
            SaleAccountingEntry::query()->create(['sale_id' => $currentSale->id, 'network_owner_id' => $type === 'provider_payable' ? $owner->id : null, 'entry_type' => $type, 'amount' => $amount, 'currency_code' => 'YER', 'idempotency_key' => (string) Str::ulid(), 'posted_at' => $date.' 12:00:00']);
        }
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        ProviderSettlement::query()->create(['network_owner_id' => $owner->id, 'reference_no' => 'PAY-'.Str::ulid(), 'amount' => '40', 'currency_code' => 'YER', 'payment_method' => 'cash', 'external_reference' => 'receipt-40', 'idempotency_key' => (string) Str::ulid(), 'created_by' => $admin->id, 'paid_at' => '2026-10-17 12:00:00']);
        Sanctum::actingAs($user, ['account', 'network_owner']);
        $this->getJson('/api/v1/owner/statement?from=2026-10-15&to=2026-10-18')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.basis', 'provider_payables')->assertJsonPath('summary.currencies.0.opening_balance', '200.0000')
            ->assertJsonPath('summary.currencies.0.credits', '100.0000')->assertJsonPath('summary.currencies.0.debits', '40.0000')
            ->assertJsonPath('summary.currencies.0.closing_balance', '260.0000')->assertJsonPath('data.1.reference_type', 'provider_settlement');
        Sanctum::actingAs($admin, ['account', 'admin']);
        $this->getJson('/api/v1/admin/statement?from=2026-10-15&to=2026-10-18')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.basis', 'platform_revenue')->assertJsonPath('summary.currencies.0.closing_balance', '8.0000');
    }

    public function test_statement_pagination_keeps_running_balance_and_rejects_unauthorized_and_invalid_periods(): void
    {
        [$user, $wallet] = $this->seller();
        $url = '/api/v1/seller/statement?from=2026-10-15&to=2026-10-18';
        $this->getJson($url)->assertUnauthorized();
        Sanctum::actingAs($user, ['account']);
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($user, ['account', 'seller']);
        for ($number = 1; $number <= 101; $number++) {
            $this->entry($wallet, 'credit', '0.0001', bcmul((string) $number, '0.0001', 4), '2026-10-16');
        }
        $this->getJson($url.'&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 101)
            ->assertJsonPath('data.0.balance_after', '0.0101')->assertJsonPath('summary.currencies.0.closing_balance', '0.0101');
        $this->getJson('/api/v1/seller/statement?from=2026-09-01&to=2026-10-18')->assertUnprocessable();
        $this->getJson($url.'&page=0')->assertUnprocessable();
    }

    private function seller(): array
    {
        $user = User::factory()->create(['role' => UserRole::SELLER]);
        $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'SEL-'.Str::ulid()]);
        $seller->status = 'active';
        $seller->save();

        return [$user, SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => 'YER'])];
    }

    private function entry(SellerWallet $wallet, string $direction, string $amount, string $balance, string $date): void
    {
        SellerLedgerEntry::query()->create(['seller_id' => $wallet->seller_id, 'seller_wallet_id' => $wallet->id, 'entry_type' => $direction === 'credit' ? 'deposit' : 'sale', 'direction' => $direction, 'amount' => $amount, 'currency_code' => $wallet->currency_code, 'balance_after' => $balance, 'reference_type' => 'adjustment', 'reference_id' => (string) Str::ulid(), 'idempotency_key' => (string) Str::ulid(), 'posted_at' => $date.' 12:00:00']);
    }
}
