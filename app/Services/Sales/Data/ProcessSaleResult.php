<?php

namespace App\Services\Sales\Data;

use App\Models\Sale;
use App\Providers\Enums\ProviderTransactionStatus;

final readonly class ProcessSaleResult
{
    public function __construct(
        public Sale $sale,
        public ProviderTransactionStatus $providerStatus,
        public bool $completed,
        public bool $requiresReconciliation,
    ) {
    }
}
