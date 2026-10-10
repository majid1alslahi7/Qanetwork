<?php

namespace App\Services\Operations;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

class RecordAccountNotificationService
{
    public const TYPE = 'qanetwork.operation';

    public function record(?User $user, UserRole $role, string $eventKey, string $kind, string $targetId, string $status, string $title, string $message): void
    {
        if ($user === null || $user->role !== $role || ! Schema::hasTable('notifications')) {
            return;
        }
        $id = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'qanetwork|'.$user->id.'|'.$role->value.'|'.$eventKey);
        $user->notifications()->firstOrCreate(['id' => $id], [
            'type' => self::TYPE,
            'data' => ['audience_role' => $role->value, 'kind' => $kind, 'target_id' => $targetId, 'status' => $status, 'title' => $title, 'message' => $message],
        ]);
    }

    public function administrators(string $eventKey, string $kind, string $targetId, string $status, string $title, string $message): void
    {
        User::query()->where('role', UserRole::ADMIN)->where('status', 'active')->chunkById(100, function ($users) use ($eventKey, $kind, $targetId, $status, $title, $message): void {
            foreach ($users as $user) {
                $this->record($user, UserRole::ADMIN, $eventKey, $kind, $targetId, $status, $title, $message);
            }
        });
    }
}
