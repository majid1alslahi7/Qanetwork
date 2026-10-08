<?php

namespace App\Services\Networks;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateNetworkSalesService
{
    public function handle(User $actor, Network $network, bool $enabled): Network
    {
        return DB::transaction(function () use ($actor, $network, $enabled): Network {
            $currentActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            if ($currentActor->role !== UserRole::ADMIN || ! $currentActor->canAccessApplication()) {
                throw new AuthorizationException;
            }
            $owner = NetworkOwner::query()->lockForUpdate()->findOrFail($network->network_owner_id);
            $ownerUser = $owner->user()->lockForUpdate()->first();
            $current = Network::query()->lockForUpdate()->findOrFail($network->id);
            if ($enabled) {
                $connections = $current->connections()->where('is_enabled', true)->where('is_primary', true)->lockForUpdate()->get();
                $primary = $connections->count() === 1 ? $connections->first() : null;
                if ($owner->status !== 'active' || ($ownerUser !== null && ! $ownerUser->canAccessApplication())
                    || $current->status !== 'active' || $current->health_status !== 'healthy'
                    || $current->last_health_check_at === null || $current->last_health_check_at->lt(now()->subMinutes(5))
                    || $primary === null || $primary->health_status !== 'healthy'
                    || $primary->last_checked_at === null || $primary->last_checked_at->lt(now()->subMinutes(5))) {
                    throw ValidationException::withMessages(['sales_enabled' => 'An active network and owner with a recently verified primary connection are required.']);
                }
            }
            if ($current->sales_enabled === $enabled) {
                return $current;
            }
            $before = $current->sales_enabled;
            $current->sales_enabled = $enabled;
            $current->save();
            AuditEvent::query()->create(['actor_id' => $actor->id, 'event_type' => 'network.sales_changed',
                'subject_type' => 'network', 'subject_id' => $current->id,
                'before' => ['sales_enabled' => $before], 'after' => ['sales_enabled' => $enabled]]);

            return $current;
        }, 3);
    }
}
