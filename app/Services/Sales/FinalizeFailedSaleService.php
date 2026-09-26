<?php

namespace App\Services\Sales;

use App\Models\ProviderTransaction;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinalizeFailedSaleService
{
    public function __construct(
        private readonly ReleaseSaleReservationService $releaseService
    ) {
    }

    public function handle(Sale $sale): Sale
    {
        return DB::transaction(function () use ($sale) {
            $lockedSale = Sale::query()
                ->lockForUpdate()
                ->findOrFail($sale->id);

            $providerTransaction = ProviderTransaction::query()
                ->lockForUpdate()
                ->where('sale_id', $lockedSale->id)
                ->firstOrFail();

            /*
             * This is the critical safety rule:
             * only an explicit provider failure may release money.
             */
            if ($providerTransaction->status !== 'failed') {
                throw new RuntimeException(
                    'Reservation can be released only after confirmed provider failure.'
                );
            }

            if ($lockedSale->status !== 'failed') {
                throw new RuntimeException(
                    'Sale is not in failed state.'
                );
            }

            $reservation = $lockedSale->reservation()
                ->lockForUpdate()
                ->firstOrFail();

            if ($reservation->status === 'released') {
                return $lockedSale;
            }

            if ($reservation->status !== 'reserved') {
                throw new RuntimeException(
                    'Failed sale has no releasable reservation.'
                );
            }

            $this->releaseService->handle(
                $reservation
            );

            return $lockedSale->fresh();
        }, 3);
    }
}
