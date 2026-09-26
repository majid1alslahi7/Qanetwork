<?php

namespace App\Providers\Data;

use App\Providers\Enums\ProviderTransactionStatus;

final readonly class TransactionStatusResult
{
    public function __construct(
        public ProviderTransactionStatus $status,
        public ?string $providerTransactionId = null,
        public ?string $providerCardReference = null,
        public ?array $credentials = null,
        public ?string $providerStatus = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {
    }
}
