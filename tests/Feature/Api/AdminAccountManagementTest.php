<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Accounts\CreateAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_credits_selected_seller_wallet_exactly_once_and_conflicting_retry_is_rejected(): void
    {
        [$admin, $seller] = $this->accounts();
        Sanctum::actingAs($admin, ['account', 'admin']);
        $wallet = $seller->seller->wallets()->firstOrFail();
        $data = ['wallet_id' => $wallet->id, 'amount' => '1000.1234', 'payment_method' => 'cash', 'review_notes' => 'Cash received', 'idempotency_key' => 'admin-credit-001'];
        $url = '/api/v1/admin/accounts/'.$seller->id.'/deposits';

        $first = $this->postJson($url, $data)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson($url, $data)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson($url, array_replace($data, ['amount' => '2000']))->assertConflict();

        $this->assertSame('1000.1234', $wallet->fresh()->balance);
        $this->assertDatabaseCount('seller_deposits', 1);
        $this->assertDatabaseCount('seller_ledger_entries', 1);
        $this->assertDatabaseHas('seller_ledger_entries', ['seller_wallet_id' => $wallet->id, 'direction' => 'credit', 'amount' => '1000.1234', 'created_by' => $admin->id]);
        $this->getJson('/api/v1/admin/accounts/'.$seller->id)->assertOk()->assertJsonPath('data.wallets.0.balance', '1000.1234');
    }

    public function test_credit_rejects_foreign_wallet_invalid_amount_and_suspended_seller_without_writes(): void
    {
        [$admin, $seller] = $this->accounts();
        $foreign = app(CreateAccountService::class)->handle($admin, ['name' => 'Foreign', 'email' => 'foreign@example.test', 'password' => 'Strong-Pass123!', 'role' => 'seller']);
        Sanctum::actingAs($admin, ['account', 'admin']);
        $data = ['wallet_id' => $foreign->seller->wallets()->firstOrFail()->id, 'amount' => '1', 'payment_method' => 'cash', 'review_notes' => 'Cash', 'idempotency_key' => 'admin-credit-001'];
        $url = '/api/v1/admin/accounts/'.$seller->id.'/deposits';
        $this->postJson($url, $data)->assertNotFound();
        $data['wallet_id'] = $seller->seller->wallets()->firstOrFail()->id;
        $this->postJson($url, array_replace($data, ['amount' => '0']))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $seller->status = 'suspended';
        $seller->save();
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->assertDatabaseCount('seller_deposits', 0);
        $this->assertDatabaseCount('seller_ledger_entries', 0);
    }

    public function test_account_profile_updates_do_not_change_role_or_balance_and_revoke_sessions_on_password_change(): void
    {
        [$admin, $seller] = $this->accounts();
        $seller->createToken('phone', ['account', 'seller']);
        Sanctum::actingAs($admin, ['account', 'admin']);
        $url = '/api/v1/admin/accounts/'.$seller->id;
        $data = ['name' => 'Edited seller', 'email' => $seller->email, 'business_name' => 'Shop', 'phone' => '+967123', 'city' => 'Aden', 'password' => 'Another-Strong123!'];
        $this->patchJson($url, $data)->assertOk()->assertJsonPath('data.profile.business_name', 'Shop')->assertJsonPath('data.name', 'Edited seller');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame('0.0000', $seller->seller->wallets()->firstOrFail()->balance);
        $this->assertStringNotContainsString($data['password'], AuditEvent::query()->where('event_type', 'account.profile_changed')->firstOrFail()->toJson());
        $this->patchJson($url, $data + ['role' => 'admin', 'balance' => '500'])->assertUnprocessable()->assertJsonValidationErrors(['role', 'balance']);
    }

    public function test_accounts_filter_roles_on_server_and_non_admin_cannot_view_or_credit_another_account(): void
    {
        [$admin, $seller] = $this->accounts();
        Sanctum::actingAs($admin, ['account', 'admin']);
        $this->getJson('/api/v1/admin/accounts?role=seller&search=Seller')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $seller->id);
        $this->getJson('/api/v1/admin/accounts?role=unknown')->assertUnprocessable();
        Sanctum::actingAs($seller, ['account', 'seller']);
        $this->getJson('/api/v1/admin/accounts/'.$admin->id)->assertForbidden();
        $this->postJson('/api/v1/admin/accounts/'.$seller->id.'/deposits', [])->assertForbidden();
    }

    private function accounts(): array
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $seller = app(CreateAccountService::class)->handle($admin, ['name' => 'Seller', 'email' => 'seller@example.test', 'password' => 'Strong-Pass123!', 'role' => 'seller']);

        return [$admin, $seller];
    }
}
