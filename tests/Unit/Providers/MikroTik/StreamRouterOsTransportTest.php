<?php

namespace Tests\Unit\Providers\MikroTik;

use App\Providers\MikroTik\RouterOsException;
use App\Providers\MikroTik\RouterOsFailure;
use App\Providers\MikroTik\StreamRouterOsTransport;
use PHPUnit\Framework\TestCase;

class StreamRouterOsTransportTest extends TestCase
{
    public function test_real_stream_reads_and_writes_exact_bytes_and_closes(): void
    {
        [$client, $peer] = $this->streams();
        $transport = new StreamRouterOsTransport($client, 5);
        try {
            fwrite($peer, 'abcdef');

            $this->assertSame('abc', $transport->read(3));
            $this->assertSame('def', $transport->read(3));
            $transport->write('request');
            $this->assertSame('request', fread($peer, 7));
            $transport->close();
            $transport->close();
            $this->assertFalse(is_resource($client));
        } finally {
            $transport->close();
            fclose($peer);
        }
    }

    public function test_eof_is_reported_as_disconnect(): void
    {
        [$client, $peer] = $this->streams();
        $transport = new StreamRouterOsTransport($client, 5);
        fclose($peer);
        try {
            $transport->read(1);
            $this->fail('Disconnect was accepted.');
        } catch (RouterOsException $exception) {
            $this->assertSame(RouterOsFailure::DISCONNECTED, $exception->failure);
        } finally {
            $transport->close();
        }
    }

    public function test_expired_deadline_is_timeout_before_io(): void
    {
        [$client, $peer] = $this->streams();
        $transport = new StreamRouterOsTransport($client, -1);
        try {
            $transport->write('request');
            $this->fail('Expired deadline was accepted.');
        } catch (RouterOsException $exception) {
            $this->assertSame(RouterOsFailure::TIMEOUT, $exception->failure);
        } finally {
            $transport->close();
            fclose($peer);
        }
    }

    public function test_stalled_socket_times_out(): void
    {
        [$client, $peer] = $this->streams();
        $transport = new StreamRouterOsTransport($client, 1);
        try {
            $transport->read(1);
            $this->fail('Stalled socket was accepted.');
        } catch (RouterOsException $exception) {
            $this->assertSame(RouterOsFailure::TIMEOUT, $exception->failure);
        } finally {
            $transport->close();
            fclose($peer);
        }
    }

    /** @return array{resource, resource} */
    private function streams(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        $client = stream_socket_client('tcp://'.stream_socket_get_name($server, false), $code, $message, 2);
        $peer = stream_socket_accept($server, 2);
        fclose($server);
        stream_set_timeout($peer, 2);

        return [$client, $peer];
    }
}
