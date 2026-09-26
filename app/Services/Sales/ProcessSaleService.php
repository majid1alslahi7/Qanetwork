<?php

namespace App\Services\Sales;

use App\Models\NetworkConnection;
use App\Models\Sale;
use App\Providers\Enums\ProviderTransactionStatus;
use App\Services\Providers\ExecuteProviderPurchaseService;
use App\Services\Providers\PrepareProviderTransactionService;
use App\Services\Sales\Data\ProcessSaleResult;
use RuntimeException;

class ProcessSaleService
{
    public function __construct(
        private readonly ReserveSaleBalanceService $reserveService,
        private readonly PrepareProviderTransactionService $prepareService,
        private readonly ExecuteProviderPurchaseService $executeService,
        private readonly FinalizeConfirmedSaleService $confirmedFinalizer,
        private readonly FinalizeFailedSaleService $failedFinalizer,
    ) {
    }

    /**
     * Execute the first provider-purchase attempt for a sale
     * that already has an immutable financial snapshot.
     *
     * IMPORTANT:
     * This method never retries an uncertain provider call.
     * TIMEOUT / UNKNOWN states retain the reservation and must
     * later be handled through provider reconciliation.
     */
    public function handle(
        Sale $sale,
        ?NetworkConnection $connection = null
    ): ProcessSaleResult {
        $sale = Sale::query()->findOrFail($sale->id);

        /*
         * Completed is a safe idempotent terminal state.
         *
         * Do not call the provider again.
         */
        if ($sale->status === 'completed') {
            $transaction = $sale->providerTransaction()
                ->firstOrFail();

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

            return new ProcessSaleResult(
                sale: $sale,
                providerStatus:
                    ProviderTransactionStatus::CONFIRMED,
                completed: true,
                requiresReconciliation: false,
            );
        }

        /*
         * Explicit provider failure is also terminal for the
         * first-attempt workflow. Finalization is idempotent.
         */
        if ($sale->status === 'failed') {
            $transaction = $sale->providerTransaction()
                ->firstOrFail();

            if ($transaction->status !== 'failed') {
                throw new RuntimeException(
                    'Failed sale has no failed provider transaction.'
                );
            }

            $sale = $this->failedFinalizer->handle($sale);

            return new ProcessSaleResult(
                sale: $sale,
                providerStatus:
                    ProviderTransactionStatus::FAILED,
                completed: false,
                requiresReconciliation: false,
            );
        }

        /*
         * An existing uncertain transaction must NEVER be sent
         * to purchaseCard() again.
         */
        $existingTransaction = $sale->providerTransaction()
            ->first();

        if ($existingTransaction) {
            /*
             * PROCESSING is a crash-boundary state.
             *
             * The external request may already have reached the
             * provider, so purchaseCard() must never be called
             * again from the first-attempt workflow.
             */
            if ($existingTransaction->status === 'processing') {
                throw new RuntimeException(
                    'Existing provider transaction cannot be executed again; reconciliation is required.'
                );
            }

            /*
             * CREATED is the one persisted provider state that
             * is still safe to execute. No external provider call
             * has started yet.
             */
            if ($existingTransaction->status !== 'created') {
                $status = $this->providerStatusFromStored(
                    $existingTransaction->status
                );

                if ($status->requiresReconciliation()) {
                    return new ProcessSaleResult(
                        sale: $sale->fresh(),
                        providerStatus: $status,
                        completed: false,
                        requiresReconciliation: true,
                    );
                }

                if (
                    $status ===
                        ProviderTransactionStatus::CONFIRMED
                ) {
                    $this->confirmedFinalizer->handle($sale);

                    return new ProcessSaleResult(
                        sale: $sale->fresh(),
                        providerStatus: $status,
                        completed: true,
                        requiresReconciliation: false,
                    );
                }

                if (
                    $status ===
                        ProviderTransactionStatus::FAILED
                ) {
                    $this->failedFinalizer->handle($sale);

                    return new ProcessSaleResult(
                        sale: $sale->fresh(),
                        providerStatus: $status,
                        completed: false,
                        requiresReconciliation: false,
                    );
                }

                throw new RuntimeException(
                    'Existing provider transaction cannot be executed again; reconciliation is required.'
                );
            }
        }

        /*
         * Reserve is idempotent because the key is derived from
         * the immutable sale id.
         */
        $this->reserveService->handle(
            $sale,
            'sale:'.$sale->id.':reservation'
        );

        /*
         * Prepare is also idempotent and creates exactly one
         * provider transaction for the sale.
         */
        $transaction = $this->prepareService->handle(
            $sale,
            $connection
        );

        /*
         * ExecuteProviderPurchaseService itself accepts CREATED
         * only. Therefore an uncertain result can never be
         * blindly executed a second time.
         */
        $result = $this->executeService->handle(
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
                new ProcessSaleResult(
                    sale: $sale->fresh(),
                    providerStatus: $result->status,
                    completed: false,
                    requiresReconciliation: true,
                ),
        };
    }

    private function completeConfirmed(
        Sale $sale
    ): ProcessSaleResult {
        $this->confirmedFinalizer->handle($sale);

        return new ProcessSaleResult(
            sale: $sale->fresh(),
            providerStatus:
                ProviderTransactionStatus::CONFIRMED,
            completed: true,
            requiresReconciliation: false,
        );
    }

    private function completeFailed(
        Sale $sale
    ): ProcessSaleResult {
        $this->failedFinalizer->handle($sale);

        return new ProcessSaleResult(
            sale: $sale->fresh(),
            providerStatus:
                ProviderTransactionStatus::FAILED,
            completed: false,
            requiresReconciliation: false,
        );
    }

    private function providerStatusFromStored(
        string $status
    ): ProviderTransactionStatus {
        return match ($status) {
            'confirmed' =>
                ProviderTransactionStatus::CONFIRMED,

            'failed' =>
                ProviderTransactionStatus::FAILED,

            'timeout' =>
                ProviderTransactionStatus::TIMEOUT,

            'unknown' =>
                ProviderTransactionStatus::UNKNOWN,

            'reconciliation_required' =>
                ProviderTransactionStatus::RECONCILIATION_REQUIRED,

            'pending' =>
                ProviderTransactionStatus::PENDING,

            /*
             * CREATED is handled by the caller because it is the
             * only existing transaction safe for first execution.
             */
            'created' =>
                ProviderTransactionStatus::PENDING,

            /*
             * PROCESSING is deliberately not mapped to a safe
             * retry state. The caller rejects it.
             */
            default => throw new RuntimeException(
                'Unsupported provider transaction status: '.$status
            ),
        };
    }
}
