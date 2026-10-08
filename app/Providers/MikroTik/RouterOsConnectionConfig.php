<?php

namespace App\Providers\MikroTik;

use InvalidArgumentException;
use JsonSerializable;
use SensitiveParameter;

final readonly class RouterOsConnectionConfig implements JsonSerializable
{
    public function __construct(
        public string $host,
        public string $username,
        #[SensitiveParameter] private string $password,
        public bool $tls = true,
        public int $port = 8729,
        public int $connectTimeout = 5,
        public int $requestTimeout = 15,
    ) {
        $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false
            || (strlen($host) <= 253 && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false);

        if ($host === '' || ! $validHost || preg_match('/[\s\x00-\x1F\x7F]/', $host)) {
            throw new InvalidArgumentException('Invalid RouterOS host.');
        }

        if (trim($username) === '' || $password === '') {
            throw new InvalidArgumentException('RouterOS credentials are required.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Invalid RouterOS port.');
        }

        if ($connectTimeout < 1 || $connectTimeout > 60 || $requestTimeout < 1 || $requestTimeout > 120) {
            throw new InvalidArgumentException('RouterOS timeouts are outside the allowed range.');
        }
    }

    public function endpoint(): string
    {
        $host = str_contains($this->host, ':') ? '['.$this->host.']' : $this->host;

        return ($this->tls ? 'tls' : 'tcp').'://'.$host.':'.$this->port;
    }

    /** @return array<string, array<string, bool|string>> */
    public function streamContextOptions(): array
    {
        return [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $this->host,
            ],
        ];
    }

    public function password(): string
    {
        return $this->password;
    }

    /** @return array<string, int|bool|string> */
    public function jsonSerialize(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'tls' => $this->tls,
            'connect_timeout' => $this->connectTimeout,
            'request_timeout' => $this->requestTimeout,
        ];
    }

    /** @return array<string, int|bool|string> */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
