<?php

namespace App\Services\Networks;

use App\Models\Network;
use App\Models\NetworkConnection;
use App\Providers\Registry\ProviderAdapterRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckNetworkConnectionHealthService
{
    public function __construct(private readonly ProviderAdapterRegistry $registry) {}

    public function handle(NetworkConnection $connection): NetworkConnection
    {
        $snapshot = NetworkConnection::query()->findOrFail($connection->id);
        if (! $snapshot->is_enabled) {
            return $snapshot;
        }
        $fingerprint = $this->fingerprint($snapshot);
        $startedAt = now();
        try {
            $healthy = $this->registry->forConnection($snapshot)->healthCheck($snapshot);
        } catch (Throwable) {
            $healthy = false;
        }

        return DB::transaction(function () use ($snapshot, $fingerprint, $startedAt, $healthy): NetworkConnection {
            $network = Network::query()->lockForUpdate()->findOrFail($snapshot->network_id);
            $current = $network->connections()->lockForUpdate()->findOrFail($snapshot->id);
            if ($this->fingerprint($current) !== $fingerprint
                || ($current->last_checked_at !== null && $current->last_checked_at->gt($startedAt))) {
                return $current;
            }
            $current->health_status = $healthy ? 'healthy' : 'unhealthy';
            $current->last_checked_at = $startedAt;
            $current->last_error = $healthy ? null : 'Provider health check failed.';
            if ($healthy) {
                $current->last_success_at = $startedAt;
                $current->consecutive_failures = 0;
            } else {
                $current->last_failure_at = $startedAt;
                $current->consecutive_failures = min(4294967295, $current->consecutive_failures + 1);
            }
            $current->save();
            if ($current->is_primary) {
                $unambiguous = $network->connections()->where('is_primary', true)->where('is_enabled', true)->count() === 1;
                $network->health_status = $healthy && $unambiguous ? 'healthy' : 'unhealthy';
                $network->last_health_check_at = $startedAt;
                if ($healthy && $unambiguous) {
                    $network->last_success_at = $startedAt;
                } else {
                    $network->last_failure_at = $startedAt;
                    $network->sales_enabled = false;
                }
                $network->save();
            }

            return $current;
        }, 3);
    }

    private function fingerprint(NetworkConnection $connection): string
    {
        return hash('sha256', json_encode([$connection->network_id, $connection->driver, $connection->config,
            $connection->getRawOriginal('credentials_encrypted'), $connection->is_enabled, $connection->is_primary,
            $connection->connect_timeout, $connection->request_timeout], JSON_THROW_ON_ERROR));
    }
}
