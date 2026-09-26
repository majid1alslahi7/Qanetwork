<?php

namespace App\Services\Sales;

use App\Models\ProviderTransaction;
use App\Models\Sale;
use App\Models\SoldCard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinalizeConfirmedSaleService
{
    public function __construct(
        private readonly CaptureSaleReservationService $captureService
    ) {
    }

    public function handle(Sale $sale): SoldCard
    {
        return DB::transaction(function () use ($sale) {
            $lockedSale = Sale::query()
                ->lockForUpdate()
                ->findOrFail($sale->id);

            /*
             * Completed sales are idempotent, but their durable
             * accounting/card invariants must still exist.
             */
            if ($lockedSale->status === 'completed') {
                $soldCard = $lockedSale->soldCard()
                    ->first();

                if (! $soldCard) {
                    throw new RuntimeException(
                        'Completed sale has no sold card.'
                    );
                }

                $reservation = $lockedSale->reservation()
                    ->first();

                if (
                    ! $reservation ||
                    $reservation->status !== 'captured'
                ) {
                    throw new RuntimeException(
                        'Completed sale has no captured reservation.'
                    );
                }

                return $soldCard;
            }

            if (
                $lockedSale->status !==
                'provider_confirmed'
            ) {
                throw new RuntimeException(
                    'Sale must be provider_confirmed before finalization.'
                );
            }

            $providerTransaction =
                ProviderTransaction::query()
                    ->lockForUpdate()
                    ->where(
                        'sale_id',
                        $lockedSale->id
                    )
                    ->firstOrFail();

            if (
                $providerTransaction->status !==
                'confirmed'
            ) {
                throw new RuntimeException(
                    'Provider transaction is not confirmed.'
                );
            }

            /*
             * Provider layer must already have persisted the
             * issued card. Finalization never reconstructs a
             * provider response from memory.
             */
            $soldCard = SoldCard::query()
                ->where(
                    'sale_id',
                    $lockedSale->id
                )
                ->first();

            if (! $soldCard) {
                throw new RuntimeException(
                    'Confirmed provider transaction has no durable sold card.'
                );
            }

            $reservation = $lockedSale->reservation()
                ->lockForUpdate()
                ->firstOrFail();

            if ($reservation->status !== 'reserved') {
                throw new RuntimeException(
                    'Confirmed sale requires an active reservation.'
                );
            }

            $this->captureService->handle(
                $reservation,
                'sale:'.$lockedSale->id.':seller-debit'
            );

            $lockedSale->refresh();

            if (
                $lockedSale->status !==
                'accounting_posted'
            ) {
                throw new RuntimeException(
                    'Sale accounting was not posted.'
                );
            }

            $lockedSale->status = 'completed';
            $lockedSale->completed_at = now();
            $lockedSale->save();

            return $soldCard;
        }, 3);
    }
}
