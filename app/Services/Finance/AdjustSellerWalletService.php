<?php

namespace App\Services\Finance;

use App\Models\SellerLedgerEntry;
use App\Models\SellerWallet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class AdjustSellerWalletService
{
    public function handle(
        SellerWallet $wallet,
        string $direction,
        string $amount,
        string $idempotencyKey,
        string $description,
        ?User $createdBy = null
    ): SellerLedgerEntry {
        if (! in_array($direction, ['credit', 'debit'], true)) {
            throw new InvalidArgumentException(
                'Direction must be credit or debit.'
            );
        }

        if (bccomp($amount, '0', 4) !== 1) {
            throw new InvalidArgumentException(
                'Adjustment amount must be greater than zero.'
            );
        }

        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException(
                'Idempotency key is required.'
            );
        }

        if (trim($description) === '') {
            throw new InvalidArgumentException(
                'Adjustment description is required.'
            );
        }

        return DB::transaction(function () use (
            $wallet,
            $direction,
            $amount,
            $idempotencyKey,
            $description,
            $createdBy
        ) {
            /*
             * إذا أُعيد نفس الطلب، نرجع نفس القيد
             * ولا نعدل الرصيد مرة أخرى.
             */
            $existing = SellerLedgerEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if (
                    $existing->seller_wallet_id !== $wallet->id ||
                    $existing->entry_type !== 'adjustment'
                ) {
                    throw new RuntimeException(
                        'Idempotency key is already used by another transaction.'
                    );
                }

                return $existing;
            }

            /** @var SellerWallet $lockedWallet */
            $lockedWallet = SellerWallet::query()
                ->whereKey($wallet->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedWallet->status !== 'active') {
                throw new RuntimeException(
                    'Seller wallet is not active.'
                );
            }

            $currentBalance = (string) $lockedWallet->balance;
            $reservedBalance = (string) $lockedWallet->reserved_balance;

            if ($direction === 'credit') {
                $newBalance = bcadd(
                    $currentBalance,
                    $amount,
                    4
                );
            } else {
                /*
                 * الرصيد المتاح = الرصيد - المحجوز.
                 * لا يجوز للخصم الإداري استهلاك مبلغ محجوز
                 * لعملية بيع قيد التنفيذ.
                 */
                $availableBalance = bcsub(
                    $currentBalance,
                    $reservedBalance,
                    4
                );

                if (bccomp($availableBalance, $amount, 4) === -1) {
                    throw new RuntimeException(
                        'Insufficient available seller balance.'
                    );
                }

                $newBalance = bcsub(
                    $currentBalance,
                    $amount,
                    4
                );
            }

            $now = now();

            $entry = SellerLedgerEntry::query()->create([
                'seller_id' => $lockedWallet->seller_id,
                'seller_wallet_id' => $lockedWallet->id,
                'entry_type' => 'adjustment',
                'direction' => $direction,
                'amount' => $amount,
                'currency_code' => $lockedWallet->currency_code,
                'balance_after' => $newBalance,
                'reference_type' => 'adjustment',
                'reference_id' => $idempotencyKey,
                'idempotency_key' => $idempotencyKey,
                'description' => $description,
                'created_by' => $createdBy?->id,
                'posted_at' => $now,
            ]);

            $lockedWallet->balance = $newBalance;
            $lockedWallet->last_transaction_at = $now;
            $lockedWallet->save();

            return $entry;
        }, 3);
    }
}
