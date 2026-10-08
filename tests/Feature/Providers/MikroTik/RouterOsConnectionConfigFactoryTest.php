<?php

namespace Tests\Feature\Providers\MikroTik;

use App\Models\NetworkConnection;
use App\Providers\MikroTik\RouterOsConnectionConfigFactory;
use InvalidArgumentException;
use Tests\TestCase;

class RouterOsConnectionConfigFactoryTest extends TestCase
{
    public function test_encrypted_credentials_and_connection_timeouts_are_used(): void
    {
        $connection = $this->connection();
        $connection->connect_timeout = 10;
        $connection->request_timeout = 30;

        $config = (new RouterOsConnectionConfigFactory)->forConnection($connection);

        $this->assertSame('tls://192.0.2.1:8729', $config->endpoint());
        $this->assertSame('operator', $config->username);
        $this->assertSame('test-secret', $config->password());
        $this->assertSame(10, $config->connectTimeout);
        $this->assertSame(30, $config->requestTimeout);
        $this->assertStringNotContainsString('test-secret', $connection->getAttributes()['credentials_encrypted']);
    }

    public function test_plaintext_requires_explicit_boolean_opt_in(): void
    {
        $connection = $this->connection();
        $connection->config = ['host' => '192.0.2.1', 'tls' => false];

        $config = (new RouterOsConnectionConfigFactory)->forConnection($connection);

        $this->assertSame('tcp://192.0.2.1:8728', $config->endpoint());
    }

    public function test_string_tls_setting_is_rejected(): void
    {
        $connection = $this->connection();
        $connection->config = ['host' => '192.0.2.1', 'tls' => 'false'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid RouterOS connection settings.');

        (new RouterOsConnectionConfigFactory)->forConnection($connection);
    }

    public function test_disabled_connection_is_rejected(): void
    {
        $connection = $this->connection();
        $connection->is_enabled = false;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RouterOS connection is disabled.');

        (new RouterOsConnectionConfigFactory)->forConnection($connection);
    }

    public function test_corrupted_credentials_produce_sanitized_error(): void
    {
        $connection = $this->connection();
        $connection->setRawAttributes([
            ...$connection->getAttributes(),
            'credentials_encrypted' => 'private-corrupted-payload',
        ]);

        try {
            (new RouterOsConnectionConfigFactory)->forConnection($connection);
            $this->fail('Corrupted credentials were accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('RouterOS credentials could not be decrypted.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_missing_credentials_are_rejected(): void
    {
        $connection = $this->connection();
        $connection->setCredentials([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RouterOS credentials are required.');

        (new RouterOsConnectionConfigFactory)->forConnection($connection);
    }

    private function connection(): NetworkConnection
    {
        $connection = new NetworkConnection([
            'config' => ['host' => '192.0.2.1'],
            'is_enabled' => true,
        ]);
        $connection->setCredentials(['username' => 'operator', 'password' => 'test-secret']);

        return $connection;
    }
}
