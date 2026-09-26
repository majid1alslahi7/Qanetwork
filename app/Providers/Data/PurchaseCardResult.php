<?php

namespace App\Providers\Data;

use App\Providers\Enums\ProviderTransactionStatus;

final readonly class PurchaseCardResult
{
    public function __construct(
        public ProviderTransactionStatus $status,

        /*
         * Provider-side transaction identifier.
         * May be unavailable on timeout.
         */
        public ?string $providerTransactionId = null,

        /*
         * Provider's non-secret card reference/serial.
         */
        public ?string $providerCardReference = null,

        /*
         * Card credentials exist only when a card was
         * positively confirmed as issued.
         *
         * Never log or serialize this object blindly.
         */
        public ?array $credentials = null,

        public ?string $providerStatus = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {
    }

    public static function confirmed(
        string $providerTransactionId,
        array $credentials,
        ?string $providerCardReference = null,
        ?string $providerStatus = null,
    ): self {
        return new self(
            status: ProviderTransactionStatus::CONFIRMED,
            providerTransactionId: $providerTransactionId,
            providerCardReference: $providerCardReference,
            credentials: $credentials,
            providerStatus: $providerStatus,
        );
    }

    public static function failed(
        ?string $providerTransactionId = null,
        ?string $providerStatus = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): self {
        return new self(
            status: ProviderTransactionStatus::FAILED,
            providerTransactionId: $providerTransactionId,
            providerStatus: $providerStatus,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }

    public static function timeout(
        ?string $providerTransactionId = null,
        ?string $errorMessage = null,
    ): self {
        return new self(
            status: ProviderTransactionStatus::TIMEOUT,
            providerTransactionId: $providerTransactionId,
            errorCode: 'PROVIDER_TIMEOUT',
            errorMessage: $errorMessage,
        );
    }

    public static function unknown(
        ?string $providerTransactionId = null,
        ?string $providerStatus = null,
        ?string $errorMessage = null,
    ): self {
        return new self(
            status: ProviderTransactionStatus::UNKNOWN,
            providerTransactionId: $providerTransactionId,
            providerStatus: $providerStatus,
            errorCode: 'UNKNOWN_PROVIDER_STATE',
            errorMessage: $errorMessage,
        );
    }
}
