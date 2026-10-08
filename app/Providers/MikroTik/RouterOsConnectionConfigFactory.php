<?php

namespace App\Providers\MikroTik;

use App\Models\NetworkConnection;
use InvalidArgumentException;
use Throwable;

class RouterOsConnectionConfigFactory
{
    public function forConnection(NetworkConnection $connection): RouterOsConnectionConfig
    {
        if (! $connection->is_enabled) {
            throw new InvalidArgumentException('RouterOS connection is disabled.');
        }

        $settings = $connection->config ?? [];
        $host = $settings['host'] ?? null;
        $tls = $settings['tls'] ?? true;
        $port = $settings['port'] ?? ($tls === false ? 8728 : 8729);

        if (! is_string($host) || ! is_bool($tls) || ! is_int($port)) {
            throw new InvalidArgumentException('Invalid RouterOS connection settings.');
        }

        try {
            $credentials = $connection->credentials();
        } catch (Throwable) {
            throw new InvalidArgumentException('RouterOS credentials could not be decrypted.');
        }

        $username = $credentials['username'] ?? null;
        $password = $credentials['password'] ?? null;

        if (! is_string($username) || ! is_string($password)) {
            throw new InvalidArgumentException('RouterOS credentials are required.');
        }

        return new RouterOsConnectionConfig(
            host: $host,
            username: $username,
            password: $password,
            tls: $tls,
            port: $port,
            connectTimeout: $connection->connect_timeout ?? 5,
            requestTimeout: $connection->request_timeout ?? 15,
        );
    }
}
