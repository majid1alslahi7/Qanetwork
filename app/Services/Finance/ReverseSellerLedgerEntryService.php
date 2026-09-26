<?php

namespace App\Services\Finance;

use App\Models\SellerLedgerEntry;
use App\Models\SellerWallet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReverseSellerLedgerEntryService
{
    public function handle(
        SellerLedgerEntry $originalEntry,
        string $idempotencyKey,
        string $description,
        ?User $createdBy = null
    ): SellerLedgerEntry {
        if (trim($idempotencyKey) === '') {
            throw new RuntimeException(
                'Idempotency key is required.'
            );
        }

        if (trim($description) === '') {
            throw new RuntimeException(
                'Reversal description is required.'
            );
        }

        return DB::transaction(function () use (
            $originalEntry,
            $idempotencyKey,
            $description,
            $createdBy
        ) {
            /*
             * نفس طلب العكس إذا أعيد بسبب timeout أو retry
             * يجب أن يرجع نفس القيد ولا ينفذ أثراً جديداً.
             */
            $existingByKey = SellerLedgerEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingByKey !== null) {
                if (
                    $existingByKey->entry_type !== 'reversal' ||
                    $existingByKey->reversal_of_entry_id !== $originalEntry->id
                ) {
                    throw new RuntimeException(
                        'Idempotency key is already used by another transaction.'
                    );
                }

                return $existingByKey;
            }

            /** @var SellerLedgerEntry $lockedOriginal */
            $lockedOriginal = SellerLedgerEntry::query()
                ->whereKey($originalEntry->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * لا نعكس قيد Reversal مرة أخرى.
             * إذا احتجنا تصحيحاً إضافياً لاحقاً يتم بقيد
             * Adjustment مستقل وموثق.
             */
            if ($lockedOriginal->entry_type === 'reversal') {
                throw new RuntimeException(
                    'A reversal entry cannot be reversed.'
                );
            }

            /*
             * منع عكس نفس القيد الأصلي مرتين حتى لو استُخدم
             * idempotency key مختلف.
             */
            $alreadyReversed = SellerLedgerEntry::query()
                ->where('reversal_of_entry_id', $lockedOriginal->id)
                ->exists();

            if ($alreadyReversed) {
                throw new RuntimeException(
                    'Ledger entry has already been reversed.'
                );
            }

            /** @var SellerWallet $wallet */
            $wallet = SellerWallet::query()
                ->whereKey($lockedOriginal->seller_wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($wallet->seller_id !== $lockedOriginal->seller_id) {
                throw new RuntimeException(
                    'Ledger entry wallet does not belong to seller.'
                );
            }

            if ($wallet->currency_code !== $lockedOriginal->currency_code) {
                throw new RuntimeException(
                    'Ledger entry currency does not match wallet currency.'
                );
            }

            if ($wallet->status !== 'active') {
                throw new RuntimeException(
                    'Seller wallet is not active.'
                );
            }

            /*
             * عكس Credit يصبح Debit.
             * عكس Debit يصبح Credit.
             */
            if ($lockedOriginal->direction === 'credit') {
                $reversalDirection = 'debit';

                $availableBalance = bcsub(
                    (string) $wallet->balance,
                    (string) $wallet->reserved_balance,
                    4
                );

                /*
                 * إذا كان عكس Credit سيخفض الرصيد، فلا يجوز
                 * استهلاك الأموال المحجوزة أو جعل المتاح سالباً.
                 */
                if (
                    bccomp(
                        $availableBalance,
                        (string) $lockedOriginal->amount,
                        4
                    ) === -1
                ) {
                    throw new RuntimeException(
                        'Insufficient available seller balance for reversal.'
                    );
                }

                $newBalance = bcsub(
                    (string) $wallet->balance,
                    (string) $lockedOriginal->amount,
                    4
                );
            } elseif ($lockedOriginal->direction === 'debit') {
                $reversalDirection = 'credit';

                $newBalance = bcadd(
                    (string) $wallet->balance,
                    (string) $lockedOriginal->amount,
                    4
                );
            } else {
                throw new RuntimeException(
                    'Original ledger direction is invalid.'
                );
            }

            $now = now();

            $reversal = SellerLedgerEntry::query()->create([
                'seller_id' => $lockedOriginal->seller_id,
                'seller_wallet_id' => $wallet->id,
                'entry_type' => 'reversal',
                'direction' => $reversalDirection,
                'amount' => $lockedOriginal->amount,
                'currency_code' => $lockedOriginal->currency_code,
                'balance_after' => $newBalance,
                'reference_type' => 'ledger_entry',
                'reference_id' => $lockedOriginal->id,
                'idempotency_key' => $idempotencyKey,
                'description' => $description,
                'created_by' => $createdBy?->id,
                'reversal_of_entry_id' => $lockedOriginal->id,
                'posted_at' => $now,
            ]);

            $wallet->balance = $newBalance;
            $wallet->last_transaction_at = $now;
            $wallet->save();

            return $reversal;
        }, 3);
    }
}
