<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\SaleReservation;
use App\Models\SellerWallet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReserveSaleBalanceService
{
    private const SCALE = 4;

    public function handle(
        Sale $sale,
        string $idempotencyKey
    ): SaleReservation {
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '') {
            throw new RuntimeException(
                'Reservation idempotency key is required.'
            );
        }

        return DB::transaction(function () use (
            $sale,
            $idempotencyKey
        ) {
            /*
             * Lock the sale so competing requests for the same
             * sale serialize on databases that support row locks.
             */
            $lockedSale = Sale::query()
                ->lockForUpdate()
                ->findOrFail($sale->id);

            $existing = SaleReservation::query()
                ->where('sale_id', $lockedSale->id)
                ->first();

            if ($existing) {
                if ($existing->idempotency_key !== $idempotencyKey) {
                    throw new RuntimeException(
                        'Sale already has a reservation with a different idempotency key.'
                    );
                }

                return $existing;
            }

            $financial = $lockedSale->financial()->first();

            if (! $financial) {
                throw new RuntimeException(
                    'Sale financial snapshot is required before reservation.'
                );
            }

            if (
                $financial->currency_code !==
                $lockedSale->currency_code
            ) {
                throw new RuntimeException(
                    'Sale financial currency mismatch.'
                );
            }

            $wallet = SellerWallet::query()
                ->lockForUpdate()
                ->findOrFail($lockedSale->seller_wallet_id);

            if ($wallet->seller_id !== $lockedSale->seller_id) {
                throw new RuntimeException(
                    'Wallet does not belong to the sale seller.'
                );
            }

            if ($wallet->currency_code !== $lockedSale->currency_code) {
                throw new RuntimeException(
                    'Wallet currency does not match sale currency.'
                );
            }

            if ($wallet->status !== 'active') {
                throw new RuntimeException(
                    'Seller wallet is not active.'
                );
            }

            $amount = (string) $financial->seller_net_amount;

            if (bccomp($amount, '0', self::SCALE) <= 0) {
                throw new RuntimeException(
                    'Reservation amount must be greater than zero.'
                );
            }

            $available = bcsub(
                (string) $wallet->balance,
                (string) $wallet->reserved_balance,
                self::SCALE
            );

            if (bccomp($available, $amount, self::SCALE) < 0) {
                throw new RuntimeException(
                    'Insufficient available wallet balance.'
                );
            }

            $newReservedBalance = bcadd(
                (string) $wallet->reserved_balance,
                $amount,
                self::SCALE
            );

            $reservation = SaleReservation::query()->create([
                'sale_id' => $lockedSale->id,
                'seller_wallet_id' => $wallet->id,
                'amount' => $amount,
                'currency_code' => $wallet->currency_code,
                'status' => 'reserved',
                'idempotency_key' => $idempotencyKey,
                'reserved_at' => now(),
            ]);

            /*
             * Reservation does NOT reduce balance.
             * It only reduces available balance.
             */
            $wallet->reserved_balance = $newReservedBalance;
            $wallet->save();

            /*
             * Operational state only. No accounting entry yet.
             */
            $lockedSale->status = 'balance_reserved';
            $lockedSale->save();

            return $reservation;
        }, 3);
    }
}
