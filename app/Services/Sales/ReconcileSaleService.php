<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Providers\Enums\ProviderTransactionStatus;
use App\Services\Providers\ReconcileProviderTransactionService;
use App\Services\Sales\Data\ReconcileSaleResult;
use RuntimeException;

class ReconcileSaleService
{
    public function __construct(
        private readonly ReconcileProviderTransactionService $reconcileService,
        private readonly FinalizeConfirmedSaleService $confirmedFinalizer,
        private readonly FinalizeFailedSaleService $failedFinalizer,
    ) {
    }

    public function handle(Sale $sale): ReconcileSaleResult
    {
        $sale = Sale::query()->findOrFail($sale->id);

        $transaction = $sale->providerTransaction()
            ->firstOrFail();

        /*
         * Terminal success is idempotent.
         *
         * We never contact the provider again after local
         * completion.
         */
        if ($sale->status === 'completed') {
            if ($transaction->status !== 'confirmed') {
                throw new RuntimeException(
                    'Completed sale has no confirmed provider transaction.'
                );
            }

            if (! $sale->soldCard()->exists()) {
                throw new RuntimeException(
                    'Completed sale has no durable sold card.'
                );
            }

            $reservation = $sale->reservation()->first();

            if (
                ! $reservation ||
                $reservation->status !== 'captured'
            ) {
                throw new RuntimeException(
                    'Completed sale has no captured reservation.'
                );
            }

            return new ReconcileSaleResult(
                sale: $sale,
                providerStatus:
                    ProviderTransactionStatus::CONFIRMED,
                completed: true,
                requiresReconciliation: false,
            );
        }

        /*
         * A provider confirmation may already have been
         * persisted while accounting/finalization has not yet
         * completed. Recover locally without another provider
         * status request.
         */
        if ($transaction->status === 'confirmed') {
            $this->confirmedFinalizer->handle($sale);

            return new ReconcileSaleResult(
                sale: $sale->fresh(),
                providerStatus:
                    ProviderTransactionStatus::CONFIRMED,
                completed: true,
                requiresReconciliation: false,
            );
        }

        /*
         * Explicit provider failure is also locally recoverable.
         * Release is performed only by the failed finalizer.
         */
        if ($transaction->status === 'failed') {
            $sale = $this->failedFinalizer->handle($sale);

            return new ReconcileSaleResult(
                sale: $sale,
                providerStatus:
                    ProviderTransactionStatus::FAILED,
                completed: false,
                requiresReconciliation: false,
            );
        }

        /*
         * CREATED means no provider purchase has been started.
         * It belongs to ProcessSaleService, not reconciliation.
         */
        if ($transaction->status === 'created') {
            throw new RuntimeException(
                'Created provider transaction must be processed before reconciliation.'
            );
        }

        /*
         * Only uncertain/in-flight states may reach the provider
         * reconciliation layer.
         *
         * ReconcileProviderTransactionService uses
         * checkTransaction() only; it never calls purchaseCard().
         */
        if (! in_array(
            $transaction->status,
            [
                'processing',
                'timeout',
                'unknown',
                'reconciliation_required',
            ],
            true
        )) {
            throw new RuntimeException(
                'Provider transaction is not eligible for reconciliation.'
            );
        }

        $result = $this->reconcileService->handle(
            $transaction
        );

        return match ($result->status) {
            ProviderTransactionStatus::CONFIRMED =>
                $this->completeConfirmed($sale),

            ProviderTransactionStatus::FAILED =>
                $this->completeFailed($sale),

            ProviderTransactionStatus::TIMEOUT,
            ProviderTransactionStatus::UNKNOWN,
            ProviderTransactionStatus::RECONCILIATION_REQUIRED,
            ProviderTransactionStatus::PENDING =>
                new ReconcileSaleResult(
                    sale: $sale->fresh(),
                    providerStatus: $result->status,
                    completed: false,
                    requiresReconciliation: true,
                ),
        };
    }

    private function completeConfirmed(
        Sale $sale
    ): ReconcileSaleResult {
        $this->confirmedFinalizer->handle($sale);

        return new ReconcileSaleResult(
            sale: $sale->fresh(),
            providerStatus:
                ProviderTransactionStatus::CONFIRMED,
            completed: true,
            requiresReconciliation: false,
        );
    }

    private function completeFailed(
        Sale $sale
    ): ReconcileSaleResult {
        $sale = $this->failedFinalizer->handle($sale);

        return new ReconcileSaleResult(
            sale: $sale,
            providerStatus:
                ProviderTransactionStatus::FAILED,
            completed: false,
            requiresReconciliation: false,
        );
    }
}
