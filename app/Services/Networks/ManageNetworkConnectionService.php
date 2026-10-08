<?php

namespace App\Services\Networks;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Network;
use App\Models\NetworkConnection;
use App\Models\User;
use App\Providers\MikroTik\RouterOsConnectionConfigFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ManageNetworkConnectionService
{
    public function __construct(private readonly RouterOsConnectionConfigFactory $configFactory) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, Network $network, #[\SensitiveParameter] array $data): NetworkConnection
    {
        return DB::transaction(function () use ($actor, $network, $data): NetworkConnection {
            $this->lockNetwork($actor, $network);
            $tls = (bool) ($data['config']['tls'] ?? true);
            $connection = new NetworkConnection([
                'network_id' => $network->id, 'name' => $data['name'], 'driver' => $data['driver'],
                'config' => ['host' => $data['config']['host'], 'tls' => $tls,
                    'port' => (int) ($data['config']['port'] ?? ($tls ? 8729 : 8728))],
                'connect_timeout' => (int) ($data['connect_timeout'] ?? 5),
                'request_timeout' => (int) ($data['request_timeout'] ?? 15),
                'is_primary' => false, 'is_enabled' => false,
            ]);
            $connection->setCredentials($data['credentials']);
            $this->validateConfig($connection);
            $connection->save();
            $connection->refresh();
            $this->audit($actor, $connection, 'connection.created', null);

            return $connection;
        }, 3);
    }

    public function updateStatus(User $actor, Network $network, NetworkConnection $connection, bool $enabled, bool $primary): NetworkConnection
    {
        if ($primary && ! $enabled) {
            throw ValidationException::withMessages(['is_primary' => 'The primary connection must be enabled.']);
        }

        return DB::transaction(function () use ($actor, $network, $connection, $enabled, $primary): NetworkConnection {
            $currentNetwork = $this->lockNetwork($actor, $network);
            $current = $currentNetwork->connections()->lockForUpdate()->findOrFail($connection->id);
            if ($enabled) {
                $this->validateConfig($current);
            }
            if ($current->is_enabled === $enabled && $current->is_primary === $primary) {
                return $current;
            }
            $before = ['is_enabled' => $current->is_enabled, 'is_primary' => $current->is_primary];
            if ($primary) {
                foreach ($currentNetwork->connections()->where('is_primary', true)->whereKeyNot($current->id)->get() as $previous) {
                    $previousBefore = ['is_enabled' => $previous->is_enabled, 'is_primary' => true];
                    $previous->is_primary = false;
                    $previous->save();
                    $this->audit($actor, $previous, 'connection.status_changed', $previousBefore);
                }
            }
            $current->is_enabled = $enabled;
            $current->is_primary = $primary;
            $current->health_status = 'unknown';
            $current->save();
            if ($currentNetwork->sales_enabled) {
                AuditEvent::query()->create([
                    'actor_id' => $actor->id, 'event_type' => 'network.sales_disabled',
                    'subject_type' => 'network', 'subject_id' => $currentNetwork->id,
                    'before' => ['sales_enabled' => true],
                    'after' => ['sales_enabled' => false, 'reason' => 'connection_changed'],
                ]);
            }
            $currentNetwork->sales_enabled = false;
            $currentNetwork->health_status = 'unknown';
            $currentNetwork->save();
            $this->audit($actor, $current, 'connection.status_changed', $before);

            return $current;
        }, 3);
    }

    private function lockNetwork(User $actor, Network $network): Network
    {
        $currentActor = User::query()->lockForUpdate()->findOrFail($actor->id);
        if ($currentActor->role !== UserRole::ADMIN || ! $currentActor->canAccessApplication()) {
            throw new AuthorizationException;
        }

        return Network::query()->lockForUpdate()->findOrFail($network->id);
    }

    private function validateConfig(NetworkConnection $connection): void
    {
        if (! in_array($connection->driver, ['mikrotik_hotspot', 'mikrotik_user_manager'], true)) {
            throw ValidationException::withMessages(['driver' => 'Unsupported provider driver.']);
        }
        $candidate = clone $connection;
        $candidate->is_enabled = true;
        try {
            $this->configFactory->forConnection($candidate);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['config' => 'Invalid RouterOS connection settings or credentials.']);
        }
    }

    /** @param array<string, bool>|null $before */
    private function audit(User $actor, NetworkConnection $connection, string $event, ?array $before): void
    {
        AuditEvent::query()->create([
            'actor_id' => $actor->id, 'event_type' => $event,
            'subject_type' => 'network_connection', 'subject_id' => $connection->id,
            'before' => $before, 'after' => ['network_id' => $connection->network_id,
                'driver' => $connection->driver, 'is_enabled' => $connection->is_enabled,
                'is_primary' => $connection->is_primary],
        ]);
    }
}
