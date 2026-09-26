<?php

namespace Tests\Unit\Providers;

use App\Providers\Data\PurchaseCardResult;
use App\Providers\Enums\ProviderTransactionStatus;
use PHPUnit\Framework\TestCase;

class PurchaseCardResultTest extends TestCase
{
    public function test_confirmed_result_contains_credentials(): void
    {
        $result = PurchaseCardResult::confirmed(
            providerTransactionId: 'TX-123',
            credentials: [
                'username' => '123456',
                'password' => '654321',
            ],
            providerCardReference: 'CARD-99',
        );

        $this->assertSame(
            ProviderTransactionStatus::CONFIRMED,
            $result->status
        );

        $this->assertSame('TX-123', $result->providerTransactionId);
        $this->assertSame('CARD-99', $result->providerCardReference);
        $this->assertSame('123456', $result->credentials['username']);
    }

    public function test_timeout_is_not_treated_as_failure(): void
    {
        $result = PurchaseCardResult::timeout(
            errorMessage: 'Connection timed out.'
        );

        $this->assertSame(
            ProviderTransactionStatus::TIMEOUT,
            $result->status
        );

        $this->assertFalse(
            $result->status->isSafeToReleaseReservation()
        );

        $this->assertTrue(
            $result->status->requiresReconciliation()
        );
    }
}
