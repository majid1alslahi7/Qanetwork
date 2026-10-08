<?php

namespace App\Jobs;

use App\Models\Sale;
use App\Services\Sales\ProcessSaleService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProcessSaleJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public array $backoff = [30, 60];

    public function __construct(public readonly string $saleId)
    {
        $this->onQueue('sales');
    }

    public function uniqueId(): string
    {
        return 'process-sale:'.$this->saleId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('sale-provider:'.$this->saleId))->shared()->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(ProcessSaleService $service): void
    {
        $sale = Sale::query()->findOrFail($this->saleId);
        if ($sale->status === 'completed') {
            return;
        }
        if ($sale->providerTransaction()->firstOrFail()->status === 'processing') {
            ReconcileSaleJob::dispatch($sale->id)->delay(now()->addMinutes(2));

            return;
        }
        $result = $service->handle($sale);
        if ($result->requiresReconciliation) {
            ReconcileSaleJob::dispatch($sale->id)->delay(now()->addMinute());
        }
    }
}
