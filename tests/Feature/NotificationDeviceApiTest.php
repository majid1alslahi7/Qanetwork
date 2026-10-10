<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\NetworkOwner;
use App\Models\Seller;
use App\Models\User;
use App\Services\Auth\ManageNotificationDeviceTokenService;
use App\Services\Operations\RecordAccountNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NotificationDeviceApiTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['admin', 'admin'])]
    #[TestWith(['seller', 'seller'])]
    #[TestWith(['network_owner', 'owner'])]
    public function test_device_token_reads_only_a_private_summary_and_cannot_use_application_permissions(string $role, string $prefix): void
    {
        $this->travelTo(now()->setMicrosecond(0));
        [$user, $parent] = $this->account($role);
        app(RecordAccountNotificationService::class)->record($user, UserRole::from($role), 'notification', $role === 'network_owner' ? 'network' : 'sale', 'sensitive-target', 'pending', 'private title', 'private message');
        $this->usingToken($parent);
        $response = $this->postJson('/api/v1/'.$prefix.'/notification-device', ['device_id' => (string) Str::uuid()])->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $child = $response->json('data.access_token');
        $this->assertSame((string) $user->id, $response->json('data.account_id'));
        $this->assertSame($role, $response->json('data.role'));
        $this->assertSame(now()->addHour()->toISOString(), $response->json('data.expires_at'));
        $this->usingToken($child);
        $feed = $this->getJson('/api/v1/notification-feed')->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->assertSame(['unread_count', 'revision'], array_keys($feed->json('data')));
        foreach (['private title', 'private message', 'sensitive-target', 'access_token', 'account_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $feed->getContent());
        }
        $protected = match ($role) {
            'admin' => 'admin/accounts', 'seller' => 'seller/wallets', default => 'owner/accounting/balance'
        };
        foreach (['/api/v1/auth/me', '/api/v1/'.$prefix.'/notifications', '/api/v1/'.$protected] as $path) {
            $this->usingToken($child);
            $this->getJson($path)->assertForbidden();
        }
        $this->usingToken($child);
        $this->postJson('/api/v1/'.$prefix.'/notification-device', ['device_id' => (string) Str::uuid()])->assertForbidden();
        $this->usingToken($child);
        $this->postJson('/api/v1/auth/logout')->assertForbidden();
        $this->usingToken($parent);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertSame(0, $user->tokens()->count());
        $this->usingToken($child);
        $this->getJson('/api/v1/notification-feed')->assertUnauthorized();
    }

    #[TestWith(['deleted'])]
    #[TestWith(['expired'])]
    #[TestWith(['scope'])]
    #[TestWith(['role'])]
    public function test_feed_stops_if_the_parent_session_is_missing_expired_or_changed(string $case): void
    {
        [$user, $parent] = $this->account();
        $this->usingToken($parent);
        $child = $this->postJson('/api/v1/admin/notification-device', ['device_id' => (string) Str::uuid()])->assertCreated()->json('data.access_token');
        $session = PersonalAccessToken::findToken($parent);
        if ($case === 'deleted') {
            $session->delete();
        } elseif ($case === 'expired') {
            $session->expires_at = now()->subMinute();
            $session->save();
        } elseif ($case === 'scope') {
            $session->abilities = ['account'];
            $session->save();
        } else {
            $user->role = UserRole::SELLER;
            $user->save();
            $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'changed-role']);
            $seller->status = 'active';
            $seller->save();
        }
        $this->usingToken($child);
        $this->getJson('/api/v1/notification-feed')->assertForbidden();
    }

    public function test_device_rotation_revokes_previous_token_and_deletion_does_not_revoke_login_or_other_devices(): void
    {
        [$user, $parent] = $this->account();
        $id = (string) Str::uuid();
        $this->usingToken($parent);
        $first = $this->postJson('/api/v1/admin/notification-device', ['device_id' => $id])->assertCreated()->json('data.access_token');
        $this->usingToken($parent);
        $latest = $this->postJson('/api/v1/admin/notification-device', ['device_id' => mb_strtoupper($id)])->assertCreated()->json('data.access_token');
        $this->assertNull(PersonalAccessToken::findToken($first));
        $this->usingToken($parent);
        $other = $this->postJson('/api/v1/admin/notification-device', ['device_id' => (string) Str::uuid()])->assertCreated()->json('data.access_token');
        $this->usingToken($parent);
        $this->deleteJson('/api/v1/admin/notification-device', ['device_id' => $id])->assertNoContent();
        $this->assertNull(PersonalAccessToken::findToken($latest));
        $this->assertNotNull(PersonalAccessToken::findToken($other));
        $this->assertNotNull(PersonalAccessToken::findToken($parent));
        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_device_registration_requires_login_correct_scope_valid_device_and_respects_the_device_limit(): void
    {
        $this->postJson('/api/v1/admin/notification-device')->assertUnauthorized();
        [$user, $parent] = $this->account();
        $this->usingToken($parent);
        $this->postJson('/api/v1/admin/notification-device', ['device_id' => 'not-a-uuid'])->assertUnprocessable()->assertJsonValidationErrors('device_id');
        $user->withAccessToken(PersonalAccessToken::findToken($parent));
        $service = app(ManageNotificationDeviceTokenService::class);
        foreach (range(1, 10) as $index) {
            $service->issue($user, (string) Str::uuid());
        }
        $this->usingToken($parent);
        $this->postJson('/api/v1/admin/notification-device', ['device_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('device_id');
        $this->assertSame(11, $user->tokens()->count());
    }

    private function usingToken(string $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($token);
    }

    /** @return array{User, string} */
    private function account(string $role = 'admin'): array
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

        return [$user, $user->createToken('test session', ['account', $role], now()->addHour())->plainTextToken];
    }
}
