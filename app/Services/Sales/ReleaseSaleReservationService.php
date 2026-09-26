<?php

namespace App\Services\Sales;

use App\Models\SaleReservation;
use App\Models\SellerWallet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReleaseSaleReservationService
{
    private const SCALE = 4;

    public function handle(
        SaleReservation $reservation
    ): SaleReservation {
        return DB::transaction(function () use ($reservation) {
            $lockedReservation = SaleReservation::query()
                ->lockForUpdate()
                ->findOrFail($reservation->id);

            if ($lockedReservation->status === 'released') {
                return $lockedReservation;
            }

            if ($lockedReservation->status !== 'reserved') {
                throw new RuntimeException(
                    'Only a reserved reservation can be released.'
                );
            }

            $wallet = SellerWallet::query()
                ->lockForUpdate()
                ->findOrFail($lockedReservation->seller_wallet_id);

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

            $wallet->reserved_balance = bcsub(
                (string) $wallet->reserved_balance,
                $amount,
                self::SCALE
            );

            $wallet->save();

            $lockedReservation->status = 'released';
            $lockedReservation->released_at = now();
            $lockedReservation->save();

            return $lockedReservation;
        }, 3);
    }
}
