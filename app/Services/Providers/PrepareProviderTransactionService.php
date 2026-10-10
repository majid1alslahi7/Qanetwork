<?php

namespace App\Services\Providers;

use App\Models\NetworkConnection;
use App\Models\ProviderTransaction;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PrepareProviderTransactionService
{
    public function handle(
        Sale $sale,
        ?NetworkConnection $connection = null
    ): ProviderTransaction {
        return DB::transaction(function () use ($sale, $connection) {
            /*
             * Locking the sale serializes competing attempts
             * to prepare a provider transaction for one sale.
             */
            $lockedSale = Sale::query()
                ->lockForUpdate()
                ->findOrFail($sale->id);

            /*
             * A sale may have only one provider transaction.
             * Returning it makes preparation idempotent.
             */
            $existing = ProviderTransaction::query()
                ->where('sale_id', $lockedSale->id)
                ->first();

            if ($existing) {
                if (
                    $connection !== null &&
                    $existing->network_connection_id !== $connection->id
                ) {
                    throw new RuntimeException(
                        'Sale already has a provider transaction on another connection.'
                    );
                }

                return $existing;
            }

            if ($lockedSale->status !== 'balance_reserved') {
                throw new RuntimeException(
                    'Sale balance must be reserved before preparing provider transaction.'
                );
            }

            $reservation = $lockedSale->reservation()
                ->first();

            if (
                ! $reservation ||
                $reservation->status !== 'reserved'
            ) {
                throw new RuntimeException(
                    'Sale must have an active balance reservation.'
                );
            }

            $selectedConnection = $connection;

            if ($selectedConnection === null) {
                $sourceId = $lockedSale->product()->firstOrFail()->fulfillment_connection_id;
                $selectedConnection = NetworkConnection::query()
                    ->where(
                        'network_id',
                        $lockedSale->network_id
                    )
                    ->where('is_enabled', true)
                    ->when($sourceId !== null, fn ($query) => $query->whereKey($sourceId))
                    ->orderByDesc('is_primary')
                    ->orderBy('created_at')
                    ->first();
            }

            if (! $selectedConnection) {
                throw new RuntimeException(
                    'No enabled provider connection is available for this network.'
                );
            }

            if (
                $selectedConnection->network_id !==
                $lockedSale->network_id
            ) {
                throw new RuntimeException(
                    'Provider connection does not belong to the sale network.'
                );
            }

            if (! $selectedConnection->is_enabled) {
                throw new RuntimeException(
                    'Provider connection is disabled.'
                );
            }

            /*
             * These identifiers are generated ONCE and persisted.
             * Every later provider retry/check must reuse them.
             */
            $internalTransactionId = (string) Str::ulid();

            $providerTransaction = new ProviderTransaction;

            $providerTransaction->sale_id = $lockedSale->id;
            $providerTransaction->network_connection_id =
                $selectedConnection->id;

            $providerTransaction->internal_transaction_id =
                $internalTransactionId;

            $providerTransaction->idempotency_key =
                'provider-purchase:'.$lockedSale->id;

            $providerTransaction->status = 'created';
            $providerTransaction->attempt_count = 0;

            $providerTransaction->save();

            return $providerTransaction;
        }, 3);
    }
}
