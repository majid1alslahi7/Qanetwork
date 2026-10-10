<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccountRegistration;
use App\Models\AuditEvent;
use App\Models\Seller;
use App\Models\User;
use App\Services\Accounts\ReviewAccountRegistrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountRegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_registration_waits_for_admin_and_approval_creates_one_account_with_original_password(string $role): void
    {
        $payload = $this->payload($role);
        $response = $this->postJson('/api/v1/auth/register', $payload)->assertCreated()->assertJsonPath('data.status', 'pending');
        $registration = AccountRegistration::query()->findOrFail($response->json('data.id'));
        $this->assertSame(['id', 'status'], array_keys($response->json('data')));
        $this->assertTrue(Hash::check($payload['password'], $registration->password));
        $this->assertStringNotContainsString($registration->password, $registration->toJson());
        $this->assertDatabaseCount('users', 0);
        $this->postJson('/api/v1/auth/login', ['email' => $payload['email'], 'password' => $payload['password'], 'device_name' => 'test'])->assertUnprocessable();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $token = $admin->createToken('admin', ['account', 'admin'])->plainTextToken;
        $review = ['decision' => 'approved', 'reason' => 'Identity verified with the owner.'];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->withToken($token)->postJson('/api/v1/admin/registrations/'.$registration->id.'/review', $review)->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonMissingPath('data.password');
        }
        $user = User::query()->where('email', $payload['email'])->firstOrFail();
        $this->assertTrue(Hash::check($payload['password'], $user->password));
        $this->assertTrue($user->canAccessApplication());
        $this->assertNull($registration->fresh()->password);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('audit_events', 2);
        if ($role === 'seller') {
            $this->assertSame('0.0000', $user->seller->wallets()->sole()->balance);
            $this->assertDatabaseCount('sellers', 1);
        } else {
            $this->assertDatabaseCount('network_owners', 1);
            $this->assertDatabaseCount('seller_wallets', 0);
        }
        $this->postJson('/api/v1/auth/login', ['email' => $payload['email'], 'password' => $payload['password'], 'device_name' => 'test'])->assertOk()->assertJsonPath('user.role', $role);
        $this->assertStringNotContainsString($payload['password'], AuditEvent::query()->get()->toJson());
    }

    public static function roles(): array
    {
        return [['seller'], ['network_owner']];
    }

    public function test_rejected_request_loses_password_and_cannot_be_approved(): void
    {
        $registration = AccountRegistration::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->withToken($admin->createToken('admin', ['account', 'admin'])->plainTextToken)
            ->postJson('/api/v1/admin/registrations/'.$registration->id.'/review', ['decision' => 'rejected', 'reason' => 'Identity could not be verified.'])->assertOk();
        $this->postJson('/api/v1/admin/registrations/'.$registration->id.'/review', ['decision' => 'approved', 'reason' => 'Try changing the decision'])->assertUnprocessable();
        $this->assertNull($registration->fresh()->password);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('sellers', 0);
    }

    public function test_registration_does_not_accept_privilege_injection_or_duplicate_email(): void
    {
        $this->postJson('/api/v1/auth/register', [...$this->payload(), 'role' => 'admin', 'status' => 'active', 'balance' => '500'])->assertUnprocessable()->assertJsonValidationErrors(['role', 'status', 'balance']);
        $this->assertDatabaseCount('account_registrations', 0);
        AccountRegistration::factory()->create(['email' => 'new@example.test']);
        $this->postJson('/api/v1/auth/register', [...$this->payload(), 'email' => ' NEW@EXAMPLE.TEST '])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('account_registrations', 1);
    }

    public function test_review_routes_require_authentication_role_and_token_ability(): void
    {
        $registration = AccountRegistration::factory()->create();
        $path = '/api/v1/admin/registrations/'.$registration->id.'/review';
        $payload = ['decision' => 'approved', 'reason' => 'Identity checked outside application'];
        $this->getJson('/api/v1/admin/registrations')->assertUnauthorized();
        $this->postJson($path, $payload)->assertUnauthorized();
        $sellerUser = User::factory()->create();
        $seller = Seller::query()->create(['user_id' => $sellerUser->id, 'code' => 'registration-test']);
        $seller->status = 'active';
        $seller->save();
        $this->withToken($sellerUser->createToken('seller', ['account', 'admin'])->plainTextToken)->getJson('/api/v1/admin/registrations')->assertForbidden();
        $this->postJson($path, $payload)->assertForbidden();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->withToken($admin->createToken('limited', ['account'])->plainTextToken)->postJson($path, $payload)->assertForbidden();
        $this->assertSame('pending', $registration->fresh()->status);
    }

    public function test_conflicting_email_rolls_back_and_list_filters_without_hashes(): void
    {
        $registration = AccountRegistration::factory()->create();
        User::factory()->create(['email' => $registration->email]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->withToken($admin->createToken('admin', ['account', 'admin'])->plainTextToken)->postJson('/api/v1/admin/registrations/'.$registration->id.'/review', ['decision' => 'approved', 'reason' => 'Identity checked with owner'])->assertUnprocessable();
        $this->assertSame('pending', $registration->fresh()->status);
        $this->assertDatabaseCount('sellers', 0);
        $this->assertDatabaseCount('audit_events', 0);
        $this->getJson('/api/v1/admin/registrations?status=pending')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.password');
        $this->getJson('/api/v1/admin/registrations?status=approved')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_registration_rate_limit_and_password_confirmation_are_enforced(): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/v1/auth/register', [...$this->payload(), 'password_confirmation' => 'different'])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->postJson('/api/v1/auth/register', $this->payload())->assertTooManyRequests();
        $this->assertDatabaseCount('account_registrations', 0);
    }

    public function test_service_rechecks_suspended_admin_and_rejects_tampered_admin_role(): void
    {
        $actor = User::factory()->create(['role' => UserRole::ADMIN]);
        $registration = AccountRegistration::factory()->create(['role' => 'admin']);
        try {
            app(ReviewAccountRegistrationService::class)->handle($actor, $registration, 'approved', 'Verification completed');
            $this->fail('Tampered administrator role was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('users', 1);
        }
        User::query()->whereKey($actor->id)->update(['status' => 'suspended']);
        $this->expectException(AuthorizationException::class);
        app(ReviewAccountRegistrationService::class)->handle($actor, $registration, 'rejected', 'Verification completed');
    }

    /** @return array<string, string> */
    public function test_overlong_multibyte_password_is_rejected_before_bcrypt_hashing(): void
    {
        $password = 'Aa1!'.str_repeat('م', 40);
        $this->postJson('/api/v1/auth/register', [...$this->payload(), 'password' => $password, 'password_confirmation' => $password])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('account_registrations', 0);
    }

    private function payload(string $role = 'seller'): array
    {
        return ['name' => 'New Account', 'email' => 'new@example.test', 'password' => 'Strong-Password12!', 'password_confirmation' => 'Strong-Password12!', 'role' => $role, 'currency_code' => 'YER'];
    }
}
