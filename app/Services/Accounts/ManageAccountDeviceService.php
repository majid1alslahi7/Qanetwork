<?php

namespace App\Services\Accounts;

use App\Enums\UserRole;
use App\Models\AccountDevice;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageAccountDeviceService
{
    public function update(User $actor, User $user, string $deviceId, string $status): AccountDevice
    {
        if (! in_array($status, ['approved', 'blocked'], true)) {
            throw ValidationException::withMessages(['status' => 'Invalid device status.']);
        }

        return DB::transaction(function () use ($actor, $user, $deviceId, $status): AccountDevice {
            $target = $this->lockedUser($actor, $user);
            $device = $target->devices()->lockForUpdate()->findOrFail($deviceId);
            if ($status === 'approved' && $device->imei === null) {
                throw ValidationException::withMessages(['imei' => 'Record the declared IMEI from the device before approving it.']);
            }
            if ($device->status === $status) {
                return $device;
            }
            $before = $device->status;
            $device->status = $status;
            $device->approved_by = $status === 'approved' ? $actor->id : null;
            $device->approved_at = $status === 'approved' ? now() : null;
            $device->save();
            if ($status === 'blocked') {
                $target->tokens()->where('account_device_id', $device->id)->delete();
            }
            AuditEvent::query()->create(['actor_id' => $actor->id, 'event_type' => 'device.status_changed', 'subject_type' => 'account_device', 'subject_id' => $device->id,
                'before' => ['status' => $before], 'after' => ['status' => $status, 'user_id' => $target->id]]);

            return $device;
        }, 3);
    }

    public function policy(User $actor, User $user, bool $required): User
    {
        return DB::transaction(function () use ($actor, $user, $required): User {
            $target = $this->lockedUser($actor, $user);
            if ($required && $target->id === $actor->id && ! $target->devices()->where('status', 'approved')->exists()) {
                throw ValidationException::withMessages(['require_device_approval' => 'Approve a device for your own administrator account first.']);
            }
            if ($target->require_device_approval === $required) {
                return $target;
            }
            $before = $target->require_device_approval;
            $target->require_device_approval = $required;
            $target->save();
            if ($required) {
                $target->tokens()->whereNull('account_device_id')->delete();
            }
            AuditEvent::query()->create(['actor_id' => $actor->id, 'event_type' => 'account.device_policy_changed', 'subject_type' => 'user', 'subject_id' => (string) $target->id,
                'before' => ['require_device_approval' => $before], 'after' => ['require_device_approval' => $required]]);

            return $target;
        }, 3);
    }

    private function lockedUser(User $actor, User $user): User
    {
        $users = User::query()->whereIn('id', [$actor->id, $user->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $current = $users->get($actor->id);
        if ($current->role !== UserRole::ADMIN || ! $current->canAccessApplication()) {
            throw new AuthorizationException;
        }

        return $users->get($user->id);
    }
}
