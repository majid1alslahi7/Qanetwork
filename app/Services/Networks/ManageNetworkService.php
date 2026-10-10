<?php

namespace App\Services\Networks;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageNetworkService
{
    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): Network
    {
        return DB::transaction(function () use ($actor, $data): Network {
            $currentActor = $this->authorizeActor($actor, true);
            if ($currentActor->role === UserRole::NETWORK_OWNER) {
                $data['network_owner_id'] = $currentActor->networkOwner()->firstOrFail()->id;
            }
            $owner = NetworkOwner::query()->lockForUpdate()->findOrFail($data['network_owner_id']);
            $this->requireActiveOwner($owner);
            $network = new Network($data);
            $network->code = 'NET-'.Str::ulid();
            $network->country_code = $data['country_code'] ?? 'YE';
            $network->status = 'inactive';
            $network->sales_enabled = false;
            $network->save();
            $network->refresh();
            $this->audit($actor, $network, 'network.created', null, [
                'network_owner_id' => $owner->id, 'currency_code' => $network->currency_code,
                'status' => $network->status, 'sales_enabled' => false,
            ]);

            return $network;
        }, 3);
    }

    public function updateStatus(User $actor, Network $network, string $status): Network
    {
        if (! in_array($status, ['active', 'inactive', 'suspended'], true)) {
            throw ValidationException::withMessages(['status' => 'Invalid network status.']);
        }

        return DB::transaction(function () use ($actor, $network, $status): Network {
            $this->authorizeActor($actor);
            $owner = NetworkOwner::query()->lockForUpdate()->findOrFail($network->network_owner_id);
            $current = Network::query()->lockForUpdate()->findOrFail($network->id);
            if ($status === 'active') {
                $this->requireActiveOwner($owner);
            }
            if ($current->status === $status) {
                return $current;
            }
            $before = ['status' => $current->status, 'sales_enabled' => $current->sales_enabled];
            $current->status = $status;
            $current->sales_enabled = false;
            if ($status === 'active') {
                $current->activated_at ??= now();
                $current->suspended_at = null;
            } elseif ($status === 'suspended') {
                $current->suspended_at = now();
            }
            $current->save();
            $this->audit($actor, $current, 'network.status_changed', $before, [
                'status' => $status, 'sales_enabled' => false,
            ]);

            return $current;
        }, 3);
    }

    private function authorizeActor(User $actor, bool $allowOwner = false): User
    {
        $current = User::query()->lockForUpdate()->findOrFail($actor->id);
        if ((! $allowOwner && $current->role !== UserRole::ADMIN) || ($allowOwner && ! in_array($current->role, [UserRole::ADMIN, UserRole::NETWORK_OWNER], true)) || ! $current->canAccessApplication()) {
            throw new AuthorizationException;
        }

        return $current;
    }

    private function requireActiveOwner(NetworkOwner $owner): void
    {
        if ($owner->status !== 'active') {
            throw ValidationException::withMessages(['network_owner_id' => 'The network owner must be active.']);
        }
        $user = $owner->user()->lockForUpdate()->first();
        if ($user !== null && ($user->role !== UserRole::NETWORK_OWNER || ! $user->canAccessApplication())) {
            throw ValidationException::withMessages(['network_owner_id' => 'The network owner account must be active.']);
        }
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    private function audit(User $actor, Network $network, string $event, ?array $before, array $after): void
    {
        AuditEvent::query()->create([
            'actor_id' => $actor->id, 'event_type' => $event,
            'subject_type' => 'network', 'subject_id' => $network->id,
            'before' => $before, 'after' => $after,
        ]);
    }
}
