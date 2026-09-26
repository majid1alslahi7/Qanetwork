<?php

namespace App\Services\Sales;

use App\Models\SaleReservation;
use App\Models\SellerLedgerEntry;
use App\Models\SellerWallet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CaptureSaleReservationService
{
    private const SCALE = 4;

    public function handle(
        SaleReservation $reservation,
        string $idempotencyKey
    ): SellerLedgerEntry {
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '') {
            throw new RuntimeException(
                'Capture idempotency key is required.'
            );
        }

        return DB::transaction(function () use (
            $reservation,
            $idempotencyKey
        ) {
            $lockedReservation = SaleReservation::query()
                ->lockForUpdate()
                ->findOrFail($reservation->id);

            /*
             * A completed capture can safely be retried.
             */
            if ($lockedReservation->status === 'captured') {
                $existing = SellerLedgerEntry::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if (
                    $existing &&
                    $existing->reference_type === 'sale' &&
                    $existing->reference_id === $lockedReservation->sale_id
                ) {
                    return $existing;
                }

                throw new RuntimeException(
                    'Reservation has already been captured.'
                );
            }

            if ($lockedReservation->status !== 'reserved') {
                throw new RuntimeException(
                    'Only a reserved reservation can be captured.'
                );
            }

            $existing = SellerLedgerEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                throw new RuntimeException(
                    'Capture idempotency key is already in use.'
                );
            }

            $sale = $lockedReservation->sale()
                ->lockForUpdate()
                ->firstOrFail();

            $wallet = SellerWallet::query()
                ->lockForUpdate()
                ->findOrFail($lockedReservation->seller_wallet_id);

            if ($wallet->seller_id !== $sale->seller_id) {
                throw new RuntimeException(
                    'Wallet does not belong to the sale seller.'
                );
            }

            if (
                $wallet->currency_code !==
                $lockedReservation->currency_code
            ) {
                throw new RuntimeException(
                    'Reservation currency does not match wallet currency.'
                );
            }

            if ($wallet->status !== 'active') {
                throw new RuntimeException(
                    'Seller wallet is not active.'
                );
            }

            $amount = (string) $lockedReservation->amount;

            if (
                bccomp(
                    (string) $wallet->reserved_balance,
                    $amount,
                    self::SCALE
                ) < 0
            ) {
                throw new RuntimeException(
                    'Wallet reserved balance is inconsistent.'
                );
            }

            if (
                bccomp(
                    (string) $wallet->balance,
                    $amount,
                    self::SCALE
                ) < 0
            ) {
                throw new RuntimeException(
                    'Wallet balance is insufficient for capture.'
                );
            }

            $newBalance = bcsub(
                (string) $wallet->balance,
                $amount,
                self::SCALE
            );

            $newReserved = bcsub(
                (string) $wallet->reserved_balance,
                $amount,
                self::SCALE
            );

            $entry = SellerLedgerEntry::query()->create([
                'seller_id' => $sale->seller_id,
                'seller_wallet_id' => $wallet->id,
                'entry_type' => 'sale',
                'direction' => 'debit',
                'amount' => $amount,
                'currency_code' => $wallet->currency_code,
                'balance_after' => $newBalance,
                'reference_type' => 'sale',
                'reference_id' => $sale->id,
                'idempotency_key' => $idempotencyKey,
                'description' => 'Sale debit '.$sale->reference_no,
                'posted_at' => now(),
            ]);

            $wallet->balance = $newBalance;
            $wallet->reserved_balance = $newReserved;
            $wallet->last_transaction_at = now();
            $wallet->save();

            $lockedReservation->status = 'captured';
            $lockedReservation->captured_at = now();
            $lockedReservation->save();

            $sale->status = 'accounting_posted';
            $sale->accounting_posted_at = now();
            $sale->save();

            return $entry;
        }, 3);
    }
}
