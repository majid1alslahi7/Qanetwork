<?php

namespace Tests\Unit\Providers\MikroTik;

use App\Providers\MikroTik\RouterOsCodec;
use App\Providers\MikroTik\RouterOsConnectionConfig;
use App\Providers\MikroTik\RouterOsConnector;
use App\Providers\MikroTik\RouterOsException;
use App\Providers\MikroTik\RouterOsFailure;
use App\Providers\MikroTik\RouterOsTransport;
use App\Providers\MikroTik\SocketRouterOsClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Providers\MikroTik\Fakes\BufferedRouterOsTransport;

class SocketRouterOsClientTest extends TestCase
{
    public function test_login_precedes_one_command_and_complete_response_is_returned(): void
    {
        $codec = new RouterOsCodec;
        $transport = new BufferedRouterOsTransport(
            $codec->encodeSentence(['!done']).
            $codec->encodeSentence(['!re', '=.id=*1', '=password=a=b']).
            $codec->encodeSentence(['!done', '=ret=*1'])
        );

        $reply = $this->client($transport)->execute($this->config(), ['/ip/hotspot/user/print']);

        $this->assertSame([['.id' => '*1', 'password' => 'a=b']], $reply->rows);
        $this->assertSame(['ret' => '*1'], $reply->attributes);
        $this->assertSame([
            $codec->encodeSentence(['/login', '=name=user', '=password=secret']),
            $codec->encodeSentence(['/ip/hotspot/user/print']),
        ], $transport->writes);
        $this->assertTrue($transport->closed);
        $this->assertSame('{"row_count":1}', json_encode($reply, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('failures')]
    public function test_failures_are_sanitized_and_never_retried(array $sentences, RouterOsFailure $expected, int $writes): void
    {
        $codec = new RouterOsCodec;
        $buffer = '';
        foreach ($sentences as $sentence) {
            $buffer .= $codec->encodeSentence($sentence);
        }
        $transport = new BufferedRouterOsTransport($buffer, $expected);

        try {
            $this->client($transport)->execute($this->config(), ['/ip/hotspot/user/add', '=password=secret']);
            $this->fail('Failure was accepted.');
        } catch (RouterOsException $exception) {
            $this->assertSame($expected, $exception->failure);
            $this->assertStringNotContainsString('secret', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertCount($writes, $transport->writes);
        $this->assertTrue($transport->closed);
    }

    public static function failures(): array
    {
        return [
            'authentication' => [[['!trap', '=message=secret']], RouterOsFailure::AUTHENTICATION, 1],
            'legacy login' => [[['!done', '=ret=secret']], RouterOsFailure::AUTHENTICATION, 1],
            'rejected command' => [[['!done'], ['!trap', '=message=secret']], RouterOsFailure::COMMAND_REJECTED, 2],
            'fatal' => [[['!done'], ['!fatal', '=message=secret']], RouterOsFailure::DISCONNECTED, 2],
            'malformed' => [[['!done'], ['!re', 'invalid-secret']], RouterOsFailure::PROTOCOL, 2],
            'duplicate attribute' => [[['!done'], ['!re', '=name=a', '=name=b']], RouterOsFailure::PROTOCOL, 2],
            'timeout after send' => [[['!done']], RouterOsFailure::TIMEOUT, 2],
            'disconnect after send' => [[['!done']], RouterOsFailure::DISCONNECTED, 2],
        ];
    }

    public function test_empty_result_reply_is_consumed_until_done(): void
    {
        $codec = new RouterOsCodec;
        $transport = new BufferedRouterOsTransport(
            $codec->encodeSentence(['!done']).$codec->encodeSentence(['!empty']).$codec->encodeSentence(['!done'])
        );

        $reply = $this->client($transport)->execute($this->config(), ['/system/resource/print']);

        $this->assertSame([], $reply->rows);
        $this->assertTrue($transport->closed);
    }

    public function test_invalid_command_is_rejected_before_connecting(): void
    {
        $transport = new BufferedRouterOsTransport('');
        try {
            $this->client($transport)->execute($this->config(), ['/print', '']);
            $this->fail('Invalid command was accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertSame([], $transport->writes);
            $this->assertFalse($transport->closed);
        }
    }

    private function client(BufferedRouterOsTransport $transport): SocketRouterOsClient
    {
        $connector = new class($transport) implements RouterOsConnector
        {
            public function __construct(private RouterOsTransport $transport) {}

            public function connect(RouterOsConnectionConfig $config): RouterOsTransport
            {
                return $this->transport;
            }
        };

        return new SocketRouterOsClient($connector, new RouterOsCodec);
    }

    private function config(): RouterOsConnectionConfig
    {
        return new RouterOsConnectionConfig('192.0.2.1', 'user', 'secret');
    }
}
