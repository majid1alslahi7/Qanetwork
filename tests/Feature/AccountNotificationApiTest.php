<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccountRegistration;
use App\Models\NetworkOwner;
use App\Models\Seller;
use App\Models\User;
use App\Services\Finance\ReviewSellerDepositService;
use App\Services\Finance\SubmitSellerDepositService;
use App\Services\Operations\RecordAccountNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class AccountNotificationApiTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['admin', 'admin'])]
    #[TestWith(['seller', 'seller'])]
    #[TestWith(['network_owner', 'owner'])]
    public function test_each_role_reads_only_its_own_paginated_notifications_and_can_mark_read_or_unread(string $role, string $prefix): void
    {
        $user = $this->account($role);
        $foreign = $this->account($role);
        $recorder = app(RecordAccountNotificationService::class);
        $roleEnum = UserRole::from($role);
        foreach (range(1, 26) as $index) {
            $recorder->record($user, $roleEnum, 'event-'.$index, 'sale', 'target-'.$index, 'completed', 'Title', 'Safe message');
        }
        $recorder->record($foreign, $roleEnum, 'foreign', 'sale', 'secret-target', 'completed', 'Foreign', 'Private');
        Sanctum::actingAs($user, ['account', $role]);
        $path = '/api/v1/'.$prefix.'/notifications';
        $this->getJson($path)->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('summary.unread_count', 26)->assertJsonPath('meta.last_page', 2);
        $this->getJson($path.'?page=2')->assertOk()->assertJsonCount(1, 'data');
        $id = $user->notifications()->firstOrFail()->id;
        $this->patchJson($path.'/'.$id, ['read' => true])->assertOk()->assertJsonMissingPath('data.audience_role');
        $firstRead = $user->notifications()->findOrFail($id)->read_at->toISOString();
        $this->travel(1)->minutes();
        $this->patchJson($path.'/'.$id, ['read' => true])->assertOk()->assertJsonPath('data.read_at', $firstRead);
        $this->getJson($path.'?unread=1')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('summary.unread_count', 25);
        $this->patchJson($path.'/'.$id, ['read' => false])->assertOk()->assertJsonPath('data.read_at', null);
        $this->patchJson($path.'/'.$foreign->notifications()->sole()->id, ['read' => true])->assertNotFound();
        $this->patchJson($path.'/'.$id, ['read' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('read');
        $recorder->record($user, $roleEnum, 'event-1', 'sale', 'target-1', 'completed', 'Title', 'Safe message');
        $this->assertSame(26, $user->notifications()->count());
    }

    public function test_deposit_submission_and_review_create_durable_notifications_once_without_notes_or_payment_secrets(): void
    {
        $admin = $this->account('admin');
        $user = $this->account('seller');
        $wallet = $user->seller->wallets()->create(['currency_code' => 'YER']);
        $payload = ['wallet_id' => $wallet->id, 'amount' => '100', 'payment_method' => 'bank_transfer', 'idempotency_key' => 'deposit-notification', 'seller_notes' => 'private seller notes', 'external_reference' => 'private payment reference'];
        $service = app(SubmitSellerDepositService::class);
        $deposit = $service->handle($user, $payload);
        $service->handle($user, $payload);
        $this->assertSame(1, $admin->notifications()->count());
        $review = app(ReviewSellerDepositService::class);
        $review->handle($admin, $deposit, 'approved', 'private review');
        $review->handle($admin, $deposit, 'approved', 'private review');
        $this->assertSame(2, $user->notifications()->count());
        Sanctum::actingAs($user, ['account', 'seller']);
        $response = $this->getJson('/api/v1/seller/notifications')->assertOk()->assertJsonCount(2, 'data');
        foreach (['private seller notes', 'private payment reference', 'private review', 'password', 'credentials'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_rolled_back_operations_do_not_leave_phantom_notifications(): void
    {
        $admin = $this->account('admin');
        try {
            DB::transaction(function (): void {
                AccountRegistration::factory()->create();
                throw new RuntimeException('Rollback');
            });
        } catch (RuntimeException) {
            $this->assertSame(0, $admin->notifications()->count());
            $this->assertDatabaseCount('account_registrations', 0);
        }
    }

    public function test_notifications_require_active_login_role_and_scope_and_hide_an_old_role_inbox(): void
    {
        $user = $this->account('admin');
        $path = '/api/v1/admin/notifications';
        $this->getJson($path)->assertUnauthorized();
        Sanctum::actingAs($user, ['account']);
        $this->getJson($path)->assertForbidden();
        app(RecordAccountNotificationService::class)->record($user, UserRole::ADMIN, 'old-admin-event', 'registration', 'target', 'pending', 'Sensitive admin title', 'Admin message');
        $user->role = UserRole::SELLER;
        $user->save();
        $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'changed-role']);
        $seller->status = 'active';
        $seller->save();
        Sanctum::actingAs($user, ['account', 'seller']);
        $this->getJson($path)->assertForbidden();
        $this->getJson('/api/v1/seller/notifications')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.unread_count', 0);
        $user->status = 'suspended';
        $user->save();
        $this->getJson('/api/v1/seller/notifications')->assertForbidden();
    }

    public function test_new_registrations_notify_administrators_and_network_recovery_records_each_health_transition(): void
    {
        $this->freezeTime();
        $admin = $this->account('admin');
        AccountRegistration::factory()->create();
        $this->assertSame('registration', $admin->notifications()->sole()->data['kind']);
        $owner = $this->account('network_owner');
        $network = $owner->networkOwner->networks()->create(['code' => 'health-notification', 'name' => 'Network']);
        foreach (['unhealthy', 'healthy', 'unhealthy'] as $status) {
            $this->travel(1)->minutes();
            $network->health_status = $status;
            $network->last_health_check_at = now();
            $network->save();
        }
        $this->assertSame(3, $owner->notifications()->count());
        $this->assertSame(4, $admin->notifications()->count());
        $this->travel(1)->minutes();
        $network->last_health_check_at = now();
        $network->save();
        $this->assertSame(3, $owner->notifications()->count());
    }

    public function test_staged_deployment_keeps_registration_available_before_the_notification_migration(): void
    {
        $admin = $this->account('admin');
        Schema::drop('notifications');
        $registration = AccountRegistration::factory()->create();
        $this->assertSame('pending', $registration->status);
        Sanctum::actingAs($admin, ['account', 'admin']);
        $this->getJson('/api/v1/admin/notifications')->assertServiceUnavailable();
    }

    private function account(string $role): User
    {
        $user = User::factory()->create(['role' => UserRole::from($role)]);
        if ($role === 'seller') {
            $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'SEL-'.Str::ulid()]);
            $seller->status = 'active';
            $seller->save();
        } elseif ($role === 'network_owner') {
            $owner = new NetworkOwner(['code' => 'OWN-'.Str::ulid(), 'name' => 'Owner']);
            $owner->user_id = $user->id;
            $owner->status = 'active';
            $owner->save();
        }

        return $user;
    }
}
