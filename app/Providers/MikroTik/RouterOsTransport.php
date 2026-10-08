<?php

namespace App\Providers\MikroTik;

interface RouterOsTransport
{
    public function write(#[\SensitiveParameter] string $bytes): void;

    public function read(int $length): string;

    public function close(): void;
}
