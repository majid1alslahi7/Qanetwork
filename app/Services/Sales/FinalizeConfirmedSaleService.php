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
        private readonly CaptureSaleReservationService $captureService,
        private readonly PostSaleAccountingService $accountingService,
    ) {}

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

                $this->accountingService->handle($lockedSale);

                return $soldCard;
            }

            if (! in_array($lockedSale->status, ['provider_confirmed', 'accounting_posted'], true)) {
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

            $expectedReservationStatus = $lockedSale->status === 'accounting_posted' ? 'captured' : 'reserved';
            if ($reservation->status !== $expectedReservationStatus) {
                throw new RuntimeException(
                    'Confirmed sale requires an active reservation.'
                );
            }

            $entry = $this->captureService->handle(
                $reservation,
                'sale:'.$lockedSale->id.':seller-debit'
            );
            $financial = $lockedSale->financial()->firstOrFail();
            if ($entry->direction !== 'debit' || $entry->seller_id !== $lockedSale->seller_id
                || $entry->seller_wallet_id !== $lockedSale->seller_wallet_id
                || $entry->currency_code !== $lockedSale->currency_code
                || bccomp($entry->amount, $financial->seller_net_amount, 4) !== 0 || $entry->reversals()->exists()) {
                throw new RuntimeException('Sale accounting does not match the financial snapshot.');
            }

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
            $this->accountingService->handle($lockedSale);
            $lockedSale->completed_at = now();
            $lockedSale->save();

            return $soldCard;
        }, 3);
    }
}
