<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Seller;
use App\Models\SellerDeposit;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Finance\ReviewSellerDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SellerDepositTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_and_approval_are_idempotent_and_only_approval_credits_balance(): void
    {
        [$user, $wallet] = $this->seller();
        $payload = $this->payload($wallet);
        $id = $this->postJson('/api/v1/seller/deposits', $payload)->assertAccepted()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson('/api/v1/seller/deposits', $payload)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->assertSame('0.0000', $wallet->fresh()->balance);
        $this->assertDatabaseCount('seller_deposits', 1);
        $this->assertDatabaseCount('seller_ledger_entries', 0);
        $this->admin();
        $url = '/api/v1/admin/deposits/'.$id.'/review';
        $this->postJson($url, ['decision' => 'approved', 'review_notes' => 'Payment verified'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson($url, ['decision' => 'approved'])->assertOk();
        $this->assertSame('1000.1250', $wallet->fresh()->balance);
        $this->assertSame('0.0000', $wallet->fresh()->reserved_balance);
        $this->assertDatabaseCount('seller_ledger_entries', 1);
        $this->assertDatabaseCount('audit_events', 2);
        $this->postJson($url, ['decision' => 'rejected'])->assertConflict();
        Sanctum::actingAs($user, ['account', 'seller']);
        $this->getJson('/api/v1/seller/deposits/'.$id)->assertOk()->assertJsonPath('data.review_notes', 'Payment verified');
        $this->getJson('/api/v1/seller/deposits')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/seller/deposits', $payload)->assertAccepted()->assertJsonPath('data.status', 'approved');
        $this->assertSame('1000.1250', $wallet->fresh()->balance);
    }

    public function test_rejection_never_credits_balance_and_cannot_be_changed_to_approval(): void
    {
        [, $wallet] = $this->seller();
        $id = $this->postJson('/api/v1/seller/deposits', $this->payload($wallet))->assertAccepted()->json('data.id');
        $this->admin();
        $url = '/api/v1/admin/deposits/'.$id.'/review';
        $this->postJson($url, ['decision' => 'rejected', 'review_notes' => 'Receipt not verified'])->assertOk();
        $this->postJson($url, ['decision' => 'rejected'])->assertOk();
        $this->postJson($url, ['decision' => 'approved'])->assertConflict();
        $this->assertSame('0.0000', $wallet->fresh()->balance);
        $this->assertDatabaseCount('seller_ledger_entries', 0);
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_changed_payload_with_same_key_is_rejected_without_another_request(): void
    {
        [, $wallet] = $this->seller();
        $payload = $this->payload($wallet);
        $this->postJson('/api/v1/seller/deposits', $payload)->assertAccepted();
        $payload['amount'] = '2000';
        $this->postJson('/api/v1/seller/deposits', $payload)->assertConflict();
        $this->assertDatabaseCount('seller_deposits', 1);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_foreign_wallet_and_foreign_deposit_are_hidden_from_seller(): void
    {
        [$firstUser, $firstWallet] = $this->seller();
        $id = $this->postJson('/api/v1/seller/deposits', $this->payload($firstWallet))->assertAccepted()->json('data.id');
        [, $otherWallet] = $this->seller();
        $this->getJson('/api/v1/seller/deposits/'.$id)->assertNotFound();
        $this->getJson('/api/v1/seller/deposits')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($firstUser, ['account', 'seller']);
        $payload = $this->payload($otherWallet);
        $payload['idempotency_key'] = 'foreign-wallet-request';
        $this->postJson('/api/v1/seller/deposits', $payload)->assertNotFound();
        $this->assertDatabaseCount('seller_deposits', 1);
    }

    public function test_seller_with_admin_ability_cannot_review_deposit(): void
    {
        [$user, $wallet] = $this->seller();
        $id = $this->postJson('/api/v1/seller/deposits', $this->payload($wallet))->assertAccepted()->json('data.id');
        Sanctum::actingAs($user, ['account', 'seller', 'admin']);
        $this->postJson('/api/v1/admin/deposits/'.$id.'/review', ['decision' => 'approved'])->assertForbidden();
        $this->assertSame('0.0000', $wallet->fresh()->balance);
        $this->assertSame('pending', SellerDeposit::query()->findOrFail($id)->status);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amount_and_state_injection_are_rejected(mixed $amount): void
    {
        [, $wallet] = $this->seller();
        $payload = $this->payload($wallet);
        $payload['amount'] = $amount;
        $payload['status'] = 'approved';
        $payload['receipt_path'] = '/private/receipt.pdf';
        $this->postJson('/api/v1/seller/deposits', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'status', 'receipt_path']);
        $this->assertDatabaseCount('seller_deposits', 0);
    }

    public static function invalidAmounts(): array
    {
        return [['0'], ['-1'], ['1e3'], ['0.00001'], [1000.25]];
    }

    public function test_transfer_requires_reference_and_inactive_wallet_cannot_receive_request(): void
    {
        [, $wallet] = $this->seller();
        $payload = $this->payload($wallet);
        $payload['payment_method'] = 'bank_transfer';
        $this->postJson('/api/v1/seller/deposits', $payload)->assertUnprocessable()->assertJsonValidationErrors('external_reference');
        $payload['external_reference'] = 'bank-confirmation';
        $wallet->status = 'suspended';
        $wallet->save();
        $this->postJson('/api/v1/seller/deposits', $payload)->assertUnprocessable()->assertJsonValidationErrors('wallet_id');
        $this->assertDatabaseCount('seller_deposits', 0);
    }

    public function test_admin_listing_filters_status_and_review_cannot_change_amount(): void
    {
        [, $wallet] = $this->seller();
        $id = $this->postJson('/api/v1/seller/deposits', $this->payload($wallet))->assertAccepted()->json('data.id');
        $this->admin();
        $this->getJson('/api/v1/admin/deposits?status=pending')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/deposits?status=approved')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/admin/deposits/'.$id.'/review', ['decision' => 'approved', 'amount' => '9999'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame('0.0000', $wallet->fresh()->balance);
    }

    public function test_unauthenticated_and_unscoped_admin_cannot_review(): void
    {
        $this->getJson('/api/v1/seller/deposits')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account']);
        $this->getJson('/api/v1/admin/deposits')->assertForbidden();
    }

    public function test_approval_replay_detects_a_corrupt_posted_credit_without_adding_balance(): void
    {
        [, $wallet] = $this->seller();
        $id = $this->postJson('/api/v1/seller/deposits', $this->payload($wallet))->assertAccepted()->json('data.id');
        $this->admin();
        $this->postJson('/api/v1/admin/deposits/'.$id.'/review', ['decision' => 'approved'])->assertOk();
        DB::table('seller_ledger_entries')->where('reference_id', $id)->update(['amount' => '1']);
        $this->expectException(RuntimeException::class);
        try {
            app(ReviewSellerDepositService::class)->handle(User::query()->where('role', 'admin')->firstOrFail(), SellerDeposit::query()->findOrFail($id), 'approved');
        } finally {
            $this->assertSame('1000.1250', $wallet->fresh()->balance);
            $this->assertDatabaseCount('seller_ledger_entries', 1);
            $this->assertDatabaseCount('audit_events', 2);
        }
    }

    private function seller(): array
    {
        $user = User::factory()->create();
        $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'SEL-'.Str::ulid()]);
        $seller->status = 'active';
        $seller->save();
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => 'YER']);
        Sanctum::actingAs($user, ['account', 'seller']);

        return [$user, $wallet];
    }

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account', 'admin']);
    }

    private function payload(SellerWallet $wallet): array
    {
        return ['wallet_id' => $wallet->id, 'amount' => '1000.125', 'payment_method' => 'cash', 'idempotency_key' => 'deposit-0001'];
    }
}
