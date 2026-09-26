<?php

namespace App\Providers\Data;

final readonly class ProviderProduct
{
    public function __construct(
        public string $externalProductId,
        public string $name,
        public string $faceValue,
        public string $currencyCode,
        public ?int $availableQuantity = null,
        public array $metadata = [],
    ) {
    }
}
