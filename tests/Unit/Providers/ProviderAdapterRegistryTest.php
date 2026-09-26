<?php

namespace Tests\Unit\Providers;

use App\Models\NetworkConnection;
use App\Providers\Registry\ProviderAdapterRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Providers\Fakes\FakeProviderAdapter;

class ProviderAdapterRegistryTest extends TestCase
{
    public function test_adapter_can_be_registered_and_resolved(): void
    {
        $registry = new ProviderAdapterRegistry();
        $adapter = new FakeProviderAdapter();

        $registry->register('fake', $adapter);

        $this->assertTrue($registry->has('fake'));
        $this->assertSame($adapter, $registry->get('fake'));
    }

    public function test_driver_names_are_normalized(): void
    {
        $registry = new ProviderAdapterRegistry();
        $adapter = new FakeProviderAdapter();

        $registry->register('  MiKrOtIk  ', $adapter);

        $this->assertTrue($registry->has('mikrotik'));
        $this->assertSame(
            $adapter,
            $registry->get('MIKROTIK')
        );
    }

    public function test_unknown_driver_is_rejected(): void
    {
        $registry = new ProviderAdapterRegistry();

        $this->expectException(
            InvalidArgumentException::class
        );

        $registry->get('unknown-provider');
    }

    public function test_enabled_connection_resolves_its_adapter(): void
    {
        $registry = new ProviderAdapterRegistry();
        $adapter = new FakeProviderAdapter();

        $registry->register('fake', $adapter);

        $connection = new NetworkConnection();
        $connection->driver = 'fake';
        $connection->is_enabled = true;

        $this->assertSame(
            $adapter,
            $registry->forConnection($connection)
        );
    }

    public function test_disabled_connection_is_rejected(): void
    {
        $registry = new ProviderAdapterRegistry();

        $registry->register(
            'fake',
            new FakeProviderAdapter()
        );

        $connection = new NetworkConnection();
        $connection->driver = 'fake';
        $connection->is_enabled = false;

        $this->expectException(
            InvalidArgumentException::class
        );

        $registry->forConnection($connection);
    }

    public function test_registry_lists_registered_drivers(): void
    {
        $registry = new ProviderAdapterRegistry();

        $registry->register(
            'fake',
            new FakeProviderAdapter()
        );

        $this->assertSame(
            ['fake'],
            $registry->drivers()
        );
    }
}
