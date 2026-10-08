<?php

namespace Tests\Unit\Providers\MikroTik;

use App\Providers\MikroTik\RouterOsConnectionConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RouterOsConnectionConfigTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    public function test_defaults_use_verified_tls_and_bounded_timeouts(): void
    {
        $config = new RouterOsConnectionConfig('router.example.com', 'operator', 'secret');

        $this->assertSame('tls://router.example.com:8729', $config->endpoint());
        $this->assertSame(5, $config->connectTimeout);
        $this->assertSame(15, $config->requestTimeout);
        $this->assertSame([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => 'router.example.com',
            ],
        ], $config->streamContextOptions());
    }

    public function test_ipv6_and_explicit_plaintext_connections_have_valid_endpoints(): void
    {
        $config = new RouterOsConnectionConfig('2001:db8::1', 'operator', 'secret', false, 8728);

        $this->assertSame('tcp://[2001:db8::1]:8728', $config->endpoint());
    }

    public function test_diagnostics_omit_credentials_but_transport_can_access_password(): void
    {
        $config = new RouterOsConnectionConfig('192.0.2.1', 'private-user', 'private-password');

        $this->assertSame('private-password', $config->password());
        $this->assertStringNotContainsString('private-password', json_encode($config, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private-user', json_encode($config, JSON_THROW_ON_ERROR));
        $this->assertSame($config->jsonSerialize(), $config->__debugInfo());
    }

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_is_rejected_without_exposing_inputs(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RouterOsConnectionConfig(...$arguments);
    }

    public static function invalidConfigurations(): array
    {
        return [
            'empty host' => [['', 'user', 'secret']],
            'host URL' => [['https://router.example.com', 'user', 'secret']],
            'host injection' => [["router.example.com\nsecret", 'user', 'secret']],
            'empty username' => [['192.0.2.1', ' ', 'secret']],
            'empty password' => [['192.0.2.1', 'user', '']],
            'zero port' => [['192.0.2.1', 'user', 'secret', true, 0]],
            'oversized port' => [['192.0.2.1', 'user', 'secret', true, 65536]],
            'zero connect timeout' => [['192.0.2.1', 'user', 'secret', true, 8729, 0]],
            'oversized connect timeout' => [['192.0.2.1', 'user', 'secret', true, 8729, 61]],
            'zero request timeout' => [['192.0.2.1', 'user', 'secret', true, 8729, 5, 0]],
            'oversized request timeout' => [['192.0.2.1', 'user', 'secret', true, 8729, 5, 121]],
        ];
    }
}
