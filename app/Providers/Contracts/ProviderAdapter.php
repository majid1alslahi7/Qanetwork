<?php

namespace App\Providers\Contracts;

use App\Models\NetworkConnection;
use App\Providers\Data\ProviderProduct;
use App\Providers\Data\PurchaseCardRequest;
use App\Providers\Data\PurchaseCardResult;
use App\Providers\Data\TransactionStatusResult;

interface ProviderAdapter
{
    /**
     * Verify that the provider connection is reachable.
     */
    public function healthCheck(
        NetworkConnection $connection
    ): bool;

    /**
     * Return the provider account balance when supported.
     *
     * null means that this provider does not expose
     * account balance through its API.
     */
    public function getBalance(
        NetworkConnection $connection
    ): ?string;

    /**
     * Retrieve the products currently exposed by
     * this provider.
     *
     * @return array<ProviderProduct>
     */
    public function getProducts(
        NetworkConnection $connection
    ): array;

    /**
     * Check whether a specific product is available.
     */
    public function checkAvailability(
        NetworkConnection $connection,
        string $externalProductId
    ): bool;

    /**
     * Request exactly one card.
     *
     * Implementations MUST reuse the supplied
     * internalTransactionId/idempotencyKey during
     * retries and must not silently generate another
     * purchase transaction.
     */
    public function purchaseCard(
        NetworkConnection $connection,
        PurchaseCardRequest $request
    ): PurchaseCardResult;

    /**
     * Determine the state of an earlier transaction.
     *
     * This is critical after timeout or lost response.
     */
    public function checkTransaction(
        NetworkConnection $connection,
        string $internalTransactionId,
        ?string $providerTransactionId = null
    ): TransactionStatusResult;
}
