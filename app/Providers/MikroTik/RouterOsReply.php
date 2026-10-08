<?php

namespace App\Providers\MikroTik;

use JsonSerializable;

final readonly class RouterOsReply implements JsonSerializable
{
    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, string>  $attributes
     */
    public function __construct(public array $rows, public array $attributes) {}

    public function jsonSerialize(): array
    {
        return ['row_count' => count($this->rows)];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
