<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\NetworkOwner;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_active_accounts_receive_expiring_tokens_with_only_their_role_abilities(UserRole $role): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = $this->account($role);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'password', 'device_name' => 'test-device',
        ]);

        $response->assertOk()->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.role', $role->value)->assertHeader('Cache-Control', 'no-store, private');
        $token = $user->tokens()->firstOrFail();
        $this->assertSame(['account', $role->value], $token->abilities);
        $this->assertSame(8 * 3600, (int) now()->diffInSeconds($token->expires_at));
        $this->assertNotSame($response->json('access_token'), $token->token);
        $this->assertSame(['id', 'name', 'email', 'role', 'status'], array_keys($response->json('user')));
    }

    public static function roles(): array
    {
        return [
            'admin' => [UserRole::ADMIN],
            'seller' => [UserRole::SELLER],
            'network owner' => [UserRole::NETWORK_OWNER],
        ];
    }

    public function test_wrong_password_and_unknown_account_receive_the_same_error(): void
    {
        $user = $this->account(UserRole::ADMIN);

        $wrong = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong', 'device_name' => 'device']);
        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'wrong', 'device_name' => 'device']);

        $wrong->assertUnprocessable()->assertJsonValidationErrors('email');
        $unknown->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($wrong->json('errors.email'), $unknown->json('errors.email'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_cannot_assign_a_role(): void
    {
        $user = $this->account(UserRole::SELLER);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'password', 'device_name' => 'device', 'role' => 'admin',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->assertSame(UserRole::SELLER, $user->fresh()->role);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_missing_login_fields_are_validated(): void
    {
        $this->postJson('/api/v1/auth/login', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password', 'device_name']);
    }

    public function test_suspended_user_cannot_login_and_existing_token_is_refused(): void
    {
        $user = $this->account(UserRole::ADMIN);
        $token = $user->createToken('device', ['account', 'admin'])->plainTextToken;
        $user->status = 'suspended';
        $user->save();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'device'])
            ->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_seller_without_an_active_seller_record_cannot_login(): void
    {
        $user = $this->account(UserRole::SELLER);
        $seller = $user->seller()->firstOrFail();
        $seller->status = 'pending';
        $seller->save();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'device'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_missing_token_returns_401(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_suspended_network_owner_cannot_use_existing_token(): void
    {
        $user = $this->account(UserRole::NETWORK_OWNER);
        $token = $user->createToken('owner-device', ['account', 'network_owner'])->plainTextToken;
        $owner = $user->networkOwner()->firstOrFail();
        $owner->status = 'suspended';
        $owner->save();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_account_without_seller_record_cannot_login(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'device'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_token_authenticates_and_logout_revokes_only_that_device(): void
    {
        $user = $this->account(UserRole::ADMIN);
        $other = $user->createToken('other', ['account', 'admin']);
        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'device']);
        $token = $response->json('access_token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($other->plainTextToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_expired_token_returns_401(): void
    {
        $user = $this->account(UserRole::ADMIN);
        $token = $user->createToken('expired', ['account', 'admin'], now()->subSecond())->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_token_without_account_ability_returns_403(): void
    {
        $user = $this->account(UserRole::ADMIN);
        $token = $user->createToken('restricted', ['admin'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_sixth_login_attempt_returns_429_without_creating_tokens(): void
    {
        $user = $this->account(UserRole::ADMIN);
        $payload = ['email' => $user->email, 'password' => 'wrong', 'device_name' => 'device'];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', $payload)->assertTooManyRequests();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function account(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();
        if ($role === UserRole::SELLER) {
            $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'auth-seller']);
            $seller->status = 'active';
            $seller->save();
        } elseif ($role === UserRole::NETWORK_OWNER) {
            $owner = NetworkOwner::query()->create(['code' => 'auth-owner', 'name' => 'Owner']);
            $owner->user_id = $user->id;
            $owner->save();
        }

        return $user;
    }
}
