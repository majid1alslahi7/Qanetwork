<?php

namespace App\Services\Sales;

use App\Models\ProviderTransaction;
use App\Models\Sale;
use App\Models\SoldCard;
use App\Providers\Data\PurchaseCardResult;
use App\Providers\Enums\ProviderTransactionStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinalizeConfirmedSaleService
{
    public function __construct(
        private readonly CaptureSaleReservationService $captureService
    ) {
    }

    public function handle(
        Sale $sale,
        PurchaseCardResult $result
    ): SoldCard {
        if (
            $result->status !==
            ProviderTransactionStatus::CONFIRMED
        ) {
            throw new RuntimeException(
                'Only a confirmed provider result can finalize a successful sale.'
            );
        }

        if (empty($result->credentials)) {
            throw new RuntimeException(
                'Confirmed sale requires card credentials.'
            );
        }

        return DB::transaction(function () use ($sale, $result) {
            $lockedSale = Sale::query()
                ->lockForUpdate()
                ->findOrFail($sale->id);

            /*
             * Idempotent retry after a fully completed sale.
             */
            if ($lockedSale->status === 'completed') {
                $existing = $lockedSale->soldCard()->first();

                if (! $existing) {
                    throw new RuntimeException(
                        'Completed sale has no sold card.'
                    );
                }

                return $existing;
            }

            if ($lockedSale->status !== 'provider_confirmed') {
                throw new RuntimeException(
                    'Sale must be provider_confirmed before finalization.'
                );
            }

            $providerTransaction = ProviderTransaction::query()
                ->lockForUpdate()
                ->where('sale_id', $lockedSale->id)
                ->firstOrFail();

            if ($providerTransaction->status !== 'confirmed') {
                throw new RuntimeException(
                    'Provider transaction is not confirmed.'
                );
            }

            /*
             * If both sides supplied a provider transaction ID,
             * they must describe the same provider transaction.
             */
            if (
                $result->providerTransactionId !== null &&
                $providerTransaction->provider_transaction_id !== null &&
                $result->providerTransactionId !==
                    $providerTransaction->provider_transaction_id
            ) {
                throw new RuntimeException(
                    'Provider transaction identifier mismatch.'
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

            $soldCard = $lockedSale->soldCard()->first();

            if (! $soldCard) {
                $soldCard = new SoldCard([
                    'sale_id' => $lockedSale->id,
                    'provider_card_reference' =>
                        $result->providerCardReference,
                    'sold_at' => now(),
                ]);

                /*
                 * credentials_encrypted uses Laravel's encrypted
                 * cast and is never written to logs/audit text.
                 */
                $soldCard->setCredentials(
                    $result->credentials
                );

                $soldCard->save();
            }

            /*
             * Deterministic key: retrying finalization can never
             * create a second seller debit.
             */
            $this->captureService->handle(
                $reservation,
                'sale:'.$lockedSale->id.':seller-debit'
            );

            /*
             * Capture changes sale to accounting_posted.
             * Reload under the same transaction before completion.
             */
            $lockedSale->refresh();

            if ($lockedSale->status !== 'accounting_posted') {
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
