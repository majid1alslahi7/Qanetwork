<?php

namespace Tests\Unit\Providers;

use App\Providers\Enums\ProviderTransactionStatus;
use PHPUnit\Framework\TestCase;

class ProviderTransactionStatusTest extends TestCase
{
    public function test_only_confirmed_and_failed_are_final(): void
    {
        $this->assertTrue(
            ProviderTransactionStatus::CONFIRMED->isFinal()
        );

        $this->assertTrue(
            ProviderTransactionStatus::FAILED->isFinal()
        );

        $this->assertFalse(
            ProviderTransactionStatus::TIMEOUT->isFinal()
        );

        $this->assertFalse(
            ProviderTransactionStatus::UNKNOWN->isFinal()
        );
    }

    public function test_only_explicit_failure_can_release_money(): void
    {
        $this->assertTrue(
            ProviderTransactionStatus::FAILED
                ->isSafeToReleaseReservation()
        );

        $this->assertFalse(
            ProviderTransactionStatus::TIMEOUT
                ->isSafeToReleaseReservation()
        );

        $this->assertFalse(
            ProviderTransactionStatus::UNKNOWN
                ->isSafeToReleaseReservation()
        );

        $this->assertFalse(
            ProviderTransactionStatus::CONFIRMED
                ->isSafeToReleaseReservation()
        );
    }

    public function test_uncertain_states_require_reconciliation(): void
    {
        $this->assertTrue(
            ProviderTransactionStatus::TIMEOUT
                ->requiresReconciliation()
        );

        $this->assertTrue(
            ProviderTransactionStatus::UNKNOWN
                ->requiresReconciliation()
        );

        $this->assertTrue(
            ProviderTransactionStatus::RECONCILIATION_REQUIRED
                ->requiresReconciliation()
        );

        $this->assertFalse(
            ProviderTransactionStatus::FAILED
                ->requiresReconciliation()
        );

        $this->assertFalse(
            ProviderTransactionStatus::CONFIRMED
                ->requiresReconciliation()
        );
    }
}
