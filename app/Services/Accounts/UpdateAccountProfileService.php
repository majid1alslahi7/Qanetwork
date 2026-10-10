<?php

namespace App\Services\Accounts;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class UpdateAccountProfileService
{
    /** @param array<string, mixed> $data */
    public function handle(User $actor, User $user, #[\SensitiveParameter] array $data): User
    {
        return DB::transaction(function () use ($actor, $user, $data): User {
            $accounts = User::query()->whereIn('id', [$actor->id, $user->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $currentActor = $accounts->get($actor->id);
            if ($currentActor->role !== UserRole::ADMIN || ! $currentActor->canAccessApplication()) {
                throw new AuthorizationException;
            }
            $target = $accounts->get($user->id);
            $before = $target->only(['name', 'email']);
            $target->fill(['name' => $data['name'], 'email' => $data['email']]);
            if ($target->isDirty('email')) {
                $target->email_verified_at = null;
            }
            if (isset($data['password'])) {
                $target->password = $data['password'];
            }
            $target->save();
            $profile = match ($target->role) {
                UserRole::SELLER => $target->seller()->lockForUpdate()->firstOrFail(),
                UserRole::NETWORK_OWNER => $target->networkOwner()->lockForUpdate()->firstOrFail(),
                default => null,
            };
            if ($profile !== null) {
                $profile->fill(array_intersect_key($data, array_flip(['phone', 'city', 'address'])));
                if (array_key_exists('business_name', $data)) {
                    $profile->{$target->role === UserRole::SELLER ? 'business_name' : 'commercial_name'} = $data['business_name'];
                }
                if ($target->role === UserRole::NETWORK_OWNER) {
                    $profile->name = $target->name;
                    $profile->email = $target->email;
                }
                $profile->save();
            }
            if ($before['email'] !== $target->email || isset($data['password'])) {
                $target->tokens()->delete();
            }
            AuditEvent::query()->create(['actor_id' => $currentActor->id, 'event_type' => 'account.profile_changed', 'subject_type' => 'user', 'subject_id' => (string) $target->id,
                'before' => $before, 'after' => $target->only(['name', 'email']) + ['password_changed' => isset($data['password'])]]);

            return $target;
        }, 3);
    }
}
