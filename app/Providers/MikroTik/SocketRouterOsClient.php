<?php

namespace App\Providers\MikroTik;

use InvalidArgumentException;

final class SocketRouterOsClient implements RouterOsClient
{
    public function __construct(
        private readonly RouterOsConnector $connector,
        private readonly RouterOsCodec $codec,
    ) {}

    public function execute(RouterOsConnectionConfig $config, #[\SensitiveParameter] array $words): RouterOsReply
    {
        if (! array_is_list($words) || $words === [] || ! is_string($words[0])
            || ! str_starts_with($words[0], '/') || $words[0] === '/login') {
            throw new InvalidArgumentException('Invalid RouterOS command.');
        }
        $command = $this->codec->encodeSentence($words);
        $transport = $this->connector->connect($config);
        try {
            $transport->write($this->codec->encodeSentence([
                '/login', '=name='.$config->username, '=password='.$config->password(),
            ]));
            try {
                $login = $this->readReply($transport);
            } catch (RouterOsException $exception) {
                if ($exception->failure === RouterOsFailure::COMMAND_REJECTED) {
                    throw new RouterOsException(RouterOsFailure::AUTHENTICATION);
                }
                throw $exception;
            }
            if ($login->rows !== [] || $login->attributes !== []) {
                throw new RouterOsException(RouterOsFailure::AUTHENTICATION);
            }
            $transport->write($command);

            return $this->readReply($transport);
        } finally {
            $transport->close();
        }
    }

    private function readReply(RouterOsTransport $transport): RouterOsReply
    {
        $rows = [];
        $totalBytes = 0;
        for ($sentenceCount = 0; $sentenceCount < 10000; $sentenceCount++) {
            $words = $this->codec->readSentence($transport);
            $totalBytes += array_sum(array_map(strlen(...), $words));
            if ($totalBytes > 16 * RouterOsCodec::MAX_WORD_BYTES) {
                throw new RouterOsException(RouterOsFailure::PROTOCOL);
            }
            if ($words === []) {
                continue;
            }
            $type = array_shift($words);
            if ($type === '!trap') {
                throw new RouterOsException(RouterOsFailure::COMMAND_REJECTED);
            }
            if ($type === '!fatal') {
                throw new RouterOsException(RouterOsFailure::DISCONNECTED);
            }
            if (! in_array($type, ['!re', '!done', '!empty'], true)) {
                throw new RouterOsException(RouterOsFailure::PROTOCOL);
            }
            $attributes = [];
            foreach ($words as $word) {
                if (! str_starts_with($word, '=') || ! str_contains(substr($word, 1), '=')) {
                    throw new RouterOsException(RouterOsFailure::PROTOCOL);
                }
                [$key, $value] = explode('=', substr($word, 1), 2);
                if ($key === '' || array_key_exists($key, $attributes)) {
                    throw new RouterOsException(RouterOsFailure::PROTOCOL);
                }
                $attributes[$key] = $value;
            }
            if ($type === '!done') {
                return new RouterOsReply($rows, $attributes);
            }
            if ($type === '!re') {
                $rows[] = $attributes;
            }
        }

        throw new RouterOsException(RouterOsFailure::PROTOCOL);
    }
}
