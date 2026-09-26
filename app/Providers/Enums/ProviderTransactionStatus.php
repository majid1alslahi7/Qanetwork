<?php

namespace App\Providers\Enums;

enum ProviderTransactionStatus: string
{
    /*
     * The provider has not yet given us a final answer.
     */
    case PENDING = 'pending';

    /*
     * The provider confirmed that exactly one card
     * was issued for this transaction.
     */
    case CONFIRMED = 'confirmed';

    /*
     * The provider explicitly confirmed failure.
     * It is safe for the sale workflow to release
     * the seller's reservation.
     */
    case FAILED = 'failed';

    /*
     * Our request timed out.
     *
     * IMPORTANT:
     * Timeout does NOT mean the provider failed.
     * The card may already have been issued.
     */
    case TIMEOUT = 'timeout';

    /*
     * We cannot determine whether the provider
     * completed the transaction.
     */
    case UNKNOWN = 'unknown';

    /*
     * The transaction requires later reconciliation.
     */
    case RECONCILIATION_REQUIRED = 'reconciliation_required';

    public function isFinal(): bool
    {
        return match ($this) {
            self::CONFIRMED,
            self::FAILED => true,

            default => false,
        };
    }

    public function isConfirmed(): bool
    {
        return $this === self::CONFIRMED;
    }

    public function isSafeToReleaseReservation(): bool
    {
        /*
         * Only an explicit provider failure allows
         * money to be released automatically.
         */
        return $this === self::FAILED;
    }

    public function requiresReconciliation(): bool
    {
        return match ($this) {
            self::TIMEOUT,
            self::UNKNOWN,
            self::RECONCILIATION_REQUIRED => true,

            default => false,
        };
    }
}
