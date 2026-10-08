<?php

namespace Tests\Feature\Providers\MikroTik;

use App\Providers\MikroTik\RouterOsClient;
use App\Providers\MikroTik\RouterOsCodec;
use App\Providers\MikroTik\RouterOsConnectionConfig;
use App\Providers\MikroTik\RouterOsConnector;
use App\Providers\MikroTik\RouterOsTransport;
use Tests\TestCase;
use Tests\Unit\Providers\MikroTik\Fakes\BufferedRouterOsTransport;

class RouterOsClientBindingTest extends TestCase
{
    public function test_bound_client_executes_using_injected_connector(): void
    {
        $codec = new RouterOsCodec;
        $transport = new BufferedRouterOsTransport(
            $codec->encodeSentence(['!done']).
            $codec->encodeSentence(['!re', '=version=7.18']).
            $codec->encodeSentence(['!done'])
        );
        $this->app->instance(RouterOsConnector::class, new class($transport) implements RouterOsConnector
        {
            public function __construct(private RouterOsTransport $transport) {}

            public function connect(RouterOsConnectionConfig $config): RouterOsTransport
            {
                return $this->transport;
            }
        });

        $reply = $this->app->make(RouterOsClient::class)->execute(
            new RouterOsConnectionConfig('192.0.2.1', 'operator', 'secret'),
            ['/system/resource/print'],
        );

        $this->assertSame([['version' => '7.18']], $reply->rows);
        $this->assertTrue($transport->closed);
    }
}
