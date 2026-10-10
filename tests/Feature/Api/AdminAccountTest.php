<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Seller;
use App\Models\User;
use App\Services\Accounts\UpdateAccountStatusService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAccountTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_admin_provisions_account_and_role_records_without_password_disclosure(string $role): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('admin-device', ['account', 'admin'])->plainTextToken;
        $payload = $this->payload($role);

        $response = $this->withToken($token)->postJson('/api/v1/admin/accounts', $payload);

        $response->assertCreated()->assertJsonPath('data.role', $role)->assertJsonPath('data.require_device_approval', false);
        $this->assertSame(['id', 'name', 'email', 'role', 'status', 'require_device_approval', 'seller_id', 'network_owner_id'], array_keys($response->json('data')));
        $created = User::query()->where('email', $payload['email'])->firstOrFail();
        $this->assertTrue(Hash::check($payload['password'], $created->password));
        $this->assertTrue($created->canAccessApplication());
        if ($role === 'seller') {
            $seller = $created->seller()->firstOrFail();
            $response->assertJsonPath('data.seller_id', $seller->id)->assertJsonPath('data.network_owner_id', null);
            $wallet = $seller->wallets()->firstOrFail();
            $this->assertSame('active', $seller->status);
            $this->assertSame('YER', $wallet->currency_code);
            $this->assertSame('0.0000', $wallet->balance);
            $this->assertSame('0.0000', $wallet->reserved_balance);
            $this->assertDatabaseCount('seller_ledger_entries', 0);
        } elseif ($role === 'network_owner') {
            $response->assertJsonPath('data.network_owner_id', $created->networkOwner()->firstOrFail()->id)
                ->assertJsonPath('data.seller_id', null);
            $this->assertSame('active', $created->networkOwner()->firstOrFail()->status);
            $this->assertDatabaseCount('seller_wallets', 0);
        } else {
            $response->assertJsonPath('data.seller_id', null)->assertJsonPath('data.network_owner_id', null);
            $this->assertDatabaseCount('sellers', 0);
            $this->assertDatabaseCount('network_owners', 0);
        }
        $event = AuditEvent::query()->firstOrFail();
        $this->assertSame('account.created', $event->event_type);
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame((string) $created->id, $event->subject_id);
        $this->assertSame(['role' => $role, 'status' => 'active'], $event->after);
        $this->assertStringNotContainsString($payload['password'], $event->toJson());
        $this->assertStringNotContainsString($created->password, $event->toJson());
    }

    public static function roles(): array
    {
        return ['admin' => ['admin'], 'seller' => ['seller'], 'owner' => ['network_owner']];
    }

    public function test_seller_cannot_create_admin_even_with_admin_token_ability(): void
    {
        $user = User::factory()->create();
        $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'seller']);
        $seller->status = 'active';
        $seller->save();

        $this->withToken($user->createToken('malicious', ['account', 'admin'])->plainTextToken)
            ->postJson('/api/v1/admin/accounts', $this->payload('admin'))->assertForbidden();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_unscoped_admin_token_cannot_access_accounts(): void
    {
        $admin = $this->admin();

        $this->withToken($admin->createToken('limited', ['account'])->plainTextToken)
            ->getJson('/api/v1/admin/accounts')->assertForbidden();
    }

    public function test_duplicate_email_does_not_create_partial_role_records(): void
    {
        $admin = $this->admin();
        $existing = User::factory()->create(['email' => 'seller@example.test']);
        $payload = $this->payload('seller');
        $payload['email'] = $existing->email;

        $this->withToken($admin->createToken('device', ['account', 'admin'])->plainTextToken)
            ->postJson('/api/v1/admin/accounts', $payload)->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('sellers', 0);
        $this->assertDatabaseCount('seller_wallets', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_initial_balance_and_weak_password_are_rejected(): void
    {
        $admin = $this->admin();
        $payload = [...$this->payload('seller'), 'password' => 'weak', 'balance' => '100000'];

        $this->withToken($admin->createToken('device', ['account', 'admin'])->plainTextToken)
            ->postJson('/api/v1/admin/accounts', $payload)->assertUnprocessable()->assertJsonValidationErrors(['password', 'balance']);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('seller_wallets', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_suspend_revokes_all_devices_and_reactivation_does_not_restore_tokens(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $token = $target->createToken('first', ['account', 'seller'])->plainTextToken;
        $target->createToken('second', ['account', 'seller']);
        $adminToken = $admin->createToken('admin-device', ['account', 'admin'])->plainTextToken;

        $this->withToken($adminToken)->patchJson('/api/v1/admin/accounts/'.$target->id.'/status', ['status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertSame(0, $target->tokens()->count());
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($adminToken)->patchJson('/api/v1/admin/accounts/'.$target->id.'/status', ['status' => 'active'])
            ->assertOk();

        $this->assertSame('active', $target->fresh()->status);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertDatabaseCount('audit_events', 2);
        $this->assertSame(['status' => 'active'], AuditEvent::query()->firstOrFail()->before);
        $this->assertSame(['status' => 'suspended'], AuditEvent::query()->firstOrFail()->after);
    }

    public function test_self_suspension_is_rejected(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('device', ['account', 'admin'])->plainTextToken;

        $this->withToken($token)->patchJson('/api/v1/admin/accounts/'.$admin->id.'/status', ['status' => 'suspended'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertSame('active', $admin->fresh()->status);
        $this->assertSame(1, $admin->tokens()->count());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_repeated_status_update_does_not_duplicate_audit_events(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $token = $admin->createToken('device', ['account', 'admin'])->plainTextToken;

        $this->withToken($token)->patchJson('/api/v1/admin/accounts/'.$target->id.'/status', ['status' => 'suspended'])->assertOk();
        $this->withToken($token)->patchJson('/api/v1/admin/accounts/'.$target->id.'/status', ['status' => 'suspended'])->assertOk();

        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_role_changes_are_rejected_by_status_endpoint(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        $this->withToken($admin->createToken('device', ['account', 'admin'])->plainTextToken)
            ->patchJson('/api/v1/admin/accounts/'.$target->id.'/status', ['status' => 'active', 'role' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->assertSame(UserRole::SELLER, $target->fresh()->role);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_account_listing_is_paginated_and_excludes_secrets(): void
    {
        $admin = $this->admin();
        User::factory()->count(30)->create();

        $response = $this->withToken($admin->createToken('device', ['account', 'admin'])->plainTextToken)
            ->getJson('/api/v1/admin/accounts');

        $response->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 31)->assertJsonPath('data.0.require_device_approval', false);
        $this->assertSame(['id', 'name', 'email', 'role', 'status', 'require_device_approval', 'seller_id', 'network_owner_id'], array_keys($response->json('data.0')));
    }

    public function test_admin_account_listing_exposes_profile_ids_for_account_pickers(): void
    {
        $admin = $this->admin();
        $sellerUser = User::factory()->create();
        $seller = Seller::query()->create(['user_id' => $sellerUser->id, 'code' => 'picker-seller']);

        $response = $this->withToken($admin->createToken('device', ['account', 'admin'])->plainTextToken)
            ->getJson('/api/v1/admin/accounts');

        $response->assertOk()->assertJsonFragment(['id' => $sellerUser->id, 'seller_id' => $seller->id,
            'network_owner_id' => null]);
    }

    public function test_suspended_actor_cannot_use_a_stale_admin_model_to_suspend_another_admin(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        $service = $this->app->make(UpdateAccountStatusService::class);
        $service->handle($first, $second, 'suspended');
        try {
            $service->handle($second, $first, 'suspended');
            $this->fail('Suspended administrator was accepted.');
        } catch (AuthorizationException) {
            $this->assertSame('active', $first->fresh()->status);
            $this->assertSame('suspended', $second->fresh()->status);
            $this->assertDatabaseCount('audit_events', 1);
        }
    }

    public function test_audit_event_cannot_be_modified(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $this->app->make(UpdateAccountStatusService::class)->handle($admin, $target, 'suspended');
        $event = AuditEvent::query()->firstOrFail();
        $event->after = ['status' => 'active'];
        $this->expectException(\LogicException::class);

        $event->save();
    }

    public function test_unauthenticated_admin_request_returns_401(): void
    {
        $this->getJson('/api/v1/admin/accounts')->assertUnauthorized();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->role = UserRole::ADMIN;
        $user->save();

        return $user;
    }

    private function payload(string $role): array
    {
        return ['name' => 'New Account', 'email' => $role.'@example.test',
            'password' => 'Strong-new-pass!123', 'role' => $role];
    }
}
