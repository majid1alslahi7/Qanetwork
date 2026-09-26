<?php

namespace App\Providers\Data;

final readonly class PurchaseCardRequest
{
    public function __construct(
        /*
         * QaNetwork's own transaction reference.
         * The same value must be reused during retries.
         */
        public string $internalTransactionId,

        /*
         * Stable idempotency key for this provider purchase.
         */
        public string $idempotencyKey,

        public string $externalProductId,

        /*
         * QaNetwork sale ULID.
         */
        public string $saleId,

        public string $expectedFaceValue,
        public string $currencyCode,
    ) {
    }
}
