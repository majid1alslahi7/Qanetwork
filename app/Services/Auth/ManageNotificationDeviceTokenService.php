<?php

namespace App\Services\Auth;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class ManageNotificationDeviceTokenService
{
    public function issue(User $actor, string $deviceId): NewAccessToken
    {
        $token = $actor->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 403);

        return DB::transaction(function () use ($actor, $token, $deviceId): NewAccessToken {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($user->canAccessApplication(), 403);
            $parent = $user->tokens()->lockForUpdate()->findOrFail($token->id);
            $expires = $this->parentExpiry($parent);
            abort_unless($parent->can('account') && $parent->can($user->role->value) && $expires->isFuture(), 403);
            $devices = $user->tokens()->whereJsonContains('abilities', 'notification-feed');
            (clone $devices)->where('name', $this->deviceName($deviceId))->delete();
            if ($devices->count() >= 10) {
                throw ValidationException::withMessages(['device_id' => 'The account already has ten notification devices.']);
            }

            $issued = $user->createToken($this->deviceName($deviceId), ['notification-feed', 'notification-parent:'.$parent->id, 'notification-role:'.$user->role->value], $expires->min(now()->addHours(8)));
            $issued->accessToken->account_device_id = $parent->account_device_id;
            $issued->accessToken->save();

            return $issued;
        }, 3);
    }

    public function revokeDevice(User $actor, string $deviceId): void
    {
        DB::transaction(function () use ($actor, $deviceId): void {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($user->canAccessApplication(), 403);
            $user->tokens()->where('name', $this->deviceName($deviceId))->whereJsonContains('abilities', 'notification-feed')->delete();
        }, 3);
    }

    public function assertParentActive(User $user): void
    {
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->can('notification-role:'.$user->role->value), 403);
        $markers = array_values(array_filter($token->abilities, fn (string $ability): bool => str_starts_with($ability, 'notification-parent:')));
        abort_unless(count($markers) === 1 && preg_match('/\Anotification-parent:([1-9][0-9]*)\z/', $markers[0], $match), 403);
        $parent = $user->tokens()->find($match[1]);
        abort_unless($parent !== null && $parent->can('account') && $parent->can($user->role->value) && $this->parentExpiry($parent)->isFuture(), 403);
    }

    public function revokeSession(User $actor, PersonalAccessToken $parent): void
    {
        DB::transaction(function () use ($actor, $parent): void {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            $user->tokens()->whereJsonContains('abilities', 'notification-parent:'.$parent->id)->delete();
            $user->tokens()->whereKey($parent->id)->delete();
        }, 3);
    }

    private function deviceName(string $deviceId): string
    {
        return 'notification-device:'.mb_strtolower($deviceId);
    }

    private function parentExpiry(PersonalAccessToken $parent): Carbon
    {
        $minutes = (int) config('sanctum.expiration');
        abort_unless($minutes > 0 && $parent->created_at !== null, 403);
        $expires = $parent->created_at->copy()->addMinutes($minutes);

        return $parent->expires_at !== null ? $expires->min($parent->expires_at) : $expires;
    }
}
