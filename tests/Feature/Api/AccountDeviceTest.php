<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\AccountDevice;
use App\Models\User;
use App\Services\Accounts\CreateAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

class AccountDeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_device_registers_and_approved_device_signs_in_then_blocking_it_revokes_session_without_disabling_account(): void
    {
        [$admin, $user] = $this->accounts();
        $proof = $this->proof($user);
        $this->postJson('/api/v1/auth/login', $proof)->assertForbidden()->assertJsonPath('code', 'device_pending');
        $device = $user->devices()->firstOrFail();
        $this->assertSame('active', $user->fresh()->status);
        $this->assertSame('pending', $device->status);
        $this->assertStringNotContainsString('490154203237518', $device->getRawOriginal('imei'));
        $adminToken = $admin->createToken('admin', ['account', 'admin'])->plainTextToken;
        $this->asToken($adminToken)->patchJson('/api/v1/admin/accounts/'.$user->id.'/devices/'.$device->id, ['status' => 'approved'])->assertOk();
        $login = $this->asToken('')->postJson('/api/v1/auth/login', $this->proof($user))->assertOk();
        $sellerToken = $login->json('access_token');
        $this->asToken($sellerToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->asToken($adminToken)->patchJson('/api/v1/admin/accounts/'.$user->id.'/devices/'.$device->id, ['status' => 'blocked'])->assertOk();
        $this->assertSame('active', $user->fresh()->status);
        $this->asToken($sellerToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->asToken('')->postJson('/api/v1/auth/login', $this->proof($user))->assertForbidden()->assertJsonPath('code', 'device_blocked');
    }

    public function test_challenges_are_single_use_expire_and_cannot_be_signed_by_another_key(): void
    {
        [, $user] = $this->accounts(false);
        $proof = $this->proof($user);
        $this->postJson('/api/v1/auth/login', $proof)->assertOk();
        $this->postJson('/api/v1/auth/login', $proof)->assertUnprocessable()->assertJsonValidationErrors('device_signature');
        $proof = $this->proof($user);
        $this->travel(3)->minutes();
        $this->postJson('/api/v1/auth/login', $proof)->assertUnprocessable()->assertJsonValidationErrors('device_signature');
        $this->travelBack();
        $proof = $this->proof($user);
        $proof['device_signature'] = base64_encode('invalid signature');
        $this->postJson('/api/v1/auth/login', $proof)->assertUnprocessable()->assertJsonValidationErrors('device_signature');
        $this->assertSame(1, $user->devices()->count());
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_device_approval_and_policy_do_not_activate_a_suspended_account_and_legacy_login_cannot_bypass_required_approval(): void
    {
        [$admin, $user] = $this->accounts();
        $this->postJson('/api/v1/auth/login', $this->credentials($user))->assertForbidden()->assertJsonPath('code', 'device_proof_required');
        $device = AccountDevice::factory()->for($user)->create();
        $user->status = 'suspended';
        $user->save();
        $adminToken = $admin->createToken('admin', ['account', 'admin'])->plainTextToken;
        $this->asToken($adminToken)->patchJson('/api/v1/admin/accounts/'.$user->id.'/devices/'.$device->id, ['status' => 'approved'])->assertOk();
        $this->assertSame('suspended', $user->fresh()->status);
        $this->asToken($adminToken)->patchJson('/api/v1/admin/accounts/'.$user->id.'/device-policy', ['require_device_approval' => false])->assertOk();
        $this->assertSame('suspended', $user->fresh()->status);
        $this->asToken('')->postJson('/api/v1/auth/device-challenge', $this->credentials($user) + ['public_key' => $device->public_key])->assertUnprocessable();
    }

    public function test_legacy_sessions_are_revoked_when_policy_is_enabled_and_admin_cannot_lock_self_out_without_approved_device(): void
    {
        [$admin, $user] = $this->accounts(false);
        $legacy = $this->postJson('/api/v1/auth/login', $this->credentials($user))->assertOk()->json('access_token');
        $adminToken = $admin->createToken('admin', ['account', 'admin'])->plainTextToken;
        $this->asToken($adminToken)->patchJson('/api/v1/admin/accounts/'.$user->id.'/device-policy', ['require_device_approval' => true])->assertOk();
        $this->asToken($legacy)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->asToken($adminToken)->patchJson('/api/v1/admin/accounts/'.$admin->id.'/device-policy', ['require_device_approval' => true])->assertUnprocessable();
        $this->assertSame('active', $user->fresh()->status);
    }

    public function test_device_mutation_is_scoped_and_declared_imei_is_required_before_approval(): void
    {
        [$admin, $user] = $this->accounts();
        $device = AccountDevice::factory()->for($user)->create(['imei' => null]);
        $foreign = AccountDevice::factory()->create();
        $adminToken = $admin->createToken('admin', ['account', 'admin'])->plainTextToken;
        $this->asToken($adminToken)->patchJson('/api/v1/admin/accounts/'.$user->id.'/devices/'.$foreign->id, ['status' => 'approved'])->assertNotFound();
        $this->asToken($adminToken)->patchJson('/api/v1/admin/accounts/'.$user->id.'/devices/'.$device->id, ['status' => 'approved'])->assertUnprocessable()->assertJsonValidationErrors('imei');
        $this->asToken($adminToken)->getJson('/api/v1/admin/accounts/'.$user->id.'/devices')->assertOk()->assertJsonCount(1, 'data');
        $this->asToken('')->getJson('/api/v1/admin/accounts/'.$user->id.'/devices')->assertUnauthorized();
    }

    public function test_changing_declared_imei_requires_new_approval_and_revokes_login_and_notification_tokens(): void
    {
        [$admin, $user] = $this->accounts(false);
        $login = $this->postJson('/api/v1/auth/login', $this->proof($user))->assertOk()->json('access_token');
        $device = $user->devices()->firstOrFail();
        $adminToken = $admin->createToken('admin', ['account', 'admin'])->plainTextToken;
        $this->asToken($adminToken)->patchJson('/api/v1/admin/accounts/'.$user->id.'/devices/'.$device->id, ['status' => 'approved'])->assertOk();
        $this->asToken($adminToken)->patchJson('/api/v1/admin/accounts/'.$user->id.'/device-policy', ['require_device_approval' => true])->assertOk();
        $child = $this->asToken($login)->postJson('/api/v1/seller/notification-device', ['device_id' => (string) Str::uuid()])->assertCreated()->json('data.access_token');
        $this->assertSame($device->id, PersonalAccessToken::findToken($child)->account_device_id);
        $this->asToken('')->postJson('/api/v1/auth/login', $this->proof($user, '490154203237519'))->assertForbidden()->assertJsonPath('code', 'device_pending');
        $this->assertSame('active', $user->fresh()->status);
        $this->assertSame('pending', $device->fresh()->status);
        $this->assertSame('490154203237519', $device->fresh()->imei);
        $this->assertNull(PersonalAccessToken::findToken($login));
        $this->assertNull(PersonalAccessToken::findToken($child));
        $this->asToken($child)->getJson('/api/v1/notification-feed')->assertUnauthorized();
    }

    public function test_valid_signature_from_another_private_key_cannot_register_a_device(): void
    {
        [, $user] = $this->accounts(false);
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $challenge = $this->postJson('/api/v1/auth/device-challenge', $this->credentials($user) + ['public_key' => openssl_pkey_get_details($this->key)['key']])->assertOk()->json('data');
        $otherKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_sign($challenge['payload'], $signature, $otherKey, OPENSSL_ALGO_SHA256);
        $this->postJson('/api/v1/auth/login', $this->credentials($user) + ['device_challenge_id' => $challenge['id'], 'device_signature' => base64_encode($signature)])->assertUnprocessable()->assertJsonValidationErrors('device_signature');
        $this->assertSame(0, $user->devices()->count());
        $this->assertSame(0, $user->tokens()->count());
    }

    private ?OpenSSLAsymmetricKey $key = null;

    private function asToken(string $token): self
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    private function proof(User $user, string $imei = '490154203237518'): array
    {
        $this->key ??= openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $challenge = $this->postJson('/api/v1/auth/device-challenge', $this->credentials($user) + ['public_key' => openssl_pkey_get_details($this->key)['key'], 'imei' => $imei])->assertOk()->json('data');
        openssl_sign($challenge['payload'], $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $this->credentials($user) + ['device_challenge_id' => $challenge['id'], 'device_signature' => base64_encode($signature)];
    }

    private function credentials(User $user): array
    {
        return ['email' => $user->email, 'password' => 'Strong-Pass123!', 'device_name' => 'Test phone'];
    }

    private function accounts(bool $required = true): array
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $user = app(CreateAccountService::class)->handle($admin, ['name' => 'Seller', 'email' => 'seller@example.test', 'password' => 'Strong-Pass123!', 'role' => 'seller']);
        $user->require_device_approval = $required;
        $user->save();

        return [$admin, $user];
    }
}
