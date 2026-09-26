<?php

namespace Tests\Unit\Providers\Fakes;

use App\Models\NetworkConnection;
use App\Providers\Contracts\ProviderAdapter;
use App\Providers\Data\ProviderProduct;
use App\Providers\Data\PurchaseCardRequest;
use App\Providers\Data\PurchaseCardResult;
use App\Providers\Data\TransactionStatusResult;
use App\Providers\Enums\ProviderTransactionStatus;

class FakeProviderAdapter implements ProviderAdapter
{
    public function healthCheck(
        NetworkConnection $connection
    ): bool {
        return true;
    }

    public function getBalance(
        NetworkConnection $connection
    ): ?string {
        return '100000.0000';
    }

    public function getProducts(
        NetworkConnection $connection
    ): array {
        return [
            new ProviderProduct(
                externalProductId: 'TEST-1000',
                name: 'Test Card',
                faceValue: '1000.0000',
                currencyCode: 'YER',
                availableQuantity: 10,
            ),
        ];
    }

    public function checkAvailability(
        NetworkConnection $connection,
        string $externalProductId
    ): bool {
        return $externalProductId === 'TEST-1000';
    }

    public function purchaseCard(
        NetworkConnection $connection,
        PurchaseCardRequest $request
    ): PurchaseCardResult {
        return PurchaseCardResult::confirmed(
            providerTransactionId: 'FAKE-TX-001',
            credentials: [
                'username' => '123456',
                'password' => '654321',
            ],
            providerCardReference: 'FAKE-CARD-001',
        );
    }

    public function checkTransaction(
        NetworkConnection $connection,
        string $internalTransactionId,
        ?string $providerTransactionId = null
    ): TransactionStatusResult {
        return new TransactionStatusResult(
            status: ProviderTransactionStatus::CONFIRMED,
            providerTransactionId:
                $providerTransactionId ?? 'FAKE-TX-001',
            providerCardReference: 'FAKE-CARD-001',
            credentials: [
                'username' => '123456',
                'password' => '654321',
            ],
            providerStatus: 'success',
        );
    }
}
