<?php

namespace Tests\Unit\Providers\MikroTik\Fakes;

use App\Providers\MikroTik\RouterOsException;
use App\Providers\MikroTik\RouterOsFailure;
use App\Providers\MikroTik\RouterOsTransport;

final class BufferedRouterOsTransport implements RouterOsTransport
{
    public array $writes = [];

    public bool $closed = false;

    public function __construct(private string $buffer, private RouterOsFailure $endFailure = RouterOsFailure::DISCONNECTED) {}

    public function read(int $length): string
    {
        if (strlen($this->buffer) < $length) {
            throw new RouterOsException($this->endFailure);
        }
        $bytes = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $bytes;
    }

    public function write(#[\SensitiveParameter] string $bytes): void
    {
        $this->writes[] = $bytes;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
