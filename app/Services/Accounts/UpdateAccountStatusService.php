<?php

namespace App\Services\Accounts;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateAccountStatusService
{
    public function handle(User $actor, User $user, string $status): User
    {
        if ($actor->role !== UserRole::ADMIN || ! $actor->canAccessApplication()) {
            throw new AuthorizationException;
        }
        if (! in_array($status, ['active', 'suspended'], true)) {
            throw ValidationException::withMessages(['status' => 'Invalid account status.']);
        }
        if ($actor->id === $user->id && $status === 'suspended') {
            throw ValidationException::withMessages(['status' => 'You cannot suspend your own administrator account.']);
        }

        return DB::transaction(function () use ($actor, $user, $status): User {
            $accounts = User::query()->whereIn('id', [$actor->id, $user->id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $currentActor = $accounts->get($actor->id);
            if (! $currentActor || $currentActor->role !== UserRole::ADMIN || ! $currentActor->canAccessApplication()) {
                throw new AuthorizationException;
            }
            $locked = $accounts->get($user->id);
            if ($locked->status === $status) {
                return $locked;
            }
            $previous = $locked->status;
            $locked->status = $status;
            $locked->save();
            if ($status === 'suspended') {
                $locked->tokens()->delete();
            }
            AuditEvent::query()->create([
                'actor_id' => $actor->id, 'event_type' => 'account.status_changed',
                'subject_type' => 'user', 'subject_id' => (string) $locked->id,
                'before' => ['status' => $previous], 'after' => ['status' => $status],
            ]);

            return $locked;
        }, 3);
    }
}
