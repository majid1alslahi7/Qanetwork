<?php

namespace App\Providers\Registry;

use App\Models\NetworkConnection;
use App\Providers\Contracts\ProviderAdapter;
use InvalidArgumentException;

class ProviderAdapterRegistry
{
    /**
     * @var array<string, ProviderAdapter>
     */
    private array $adapters = [];

    public function register(
        string $driver,
        ProviderAdapter $adapter
    ): void {
        $driver = $this->normalizeDriver($driver);

        if ($driver === '') {
            throw new InvalidArgumentException(
                'Provider driver cannot be empty.'
            );
        }

        $this->adapters[$driver] = $adapter;
    }

    public function has(string $driver): bool
    {
        $driver = $this->normalizeDriver($driver);

        return isset($this->adapters[$driver]);
    }

    public function get(string $driver): ProviderAdapter
    {
        $driver = $this->normalizeDriver($driver);

        if (! isset($this->adapters[$driver])) {
            throw new InvalidArgumentException(
                "Unsupported provider driver [{$driver}]."
            );
        }

        return $this->adapters[$driver];
    }

    public function forConnection(
        NetworkConnection $connection
    ): ProviderAdapter {
        if (! $connection->is_enabled) {
            throw new InvalidArgumentException(
                'Network connection is disabled.'
            );
        }

        return $this->get($connection->driver);
    }

    /**
     * @return array<int, string>
     */
    public function drivers(): array
    {
        return array_keys($this->adapters);
    }

    private function normalizeDriver(string $driver): string
    {
        return strtolower(trim($driver));
    }
}
