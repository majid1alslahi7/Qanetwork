<?php

namespace App\Providers\MikroTik;

final class StreamRouterOsConnector implements RouterOsConnector
{
    public function connect(RouterOsConnectionConfig $config): RouterOsTransport
    {
        $stream = @stream_socket_client(
            $config->endpoint(), $errorCode, $errorMessage,
            $config->connectTimeout, STREAM_CLIENT_CONNECT,
            stream_context_create($config->streamContextOptions()),
        );
        if ($stream === false) {
            throw new RouterOsException(RouterOsFailure::CONNECTION);
        }

        return new StreamRouterOsTransport($stream, $config->requestTimeout);
    }
}
