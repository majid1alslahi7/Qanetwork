<?php

namespace App\Services\Finance;

use App\Models\SellerDeposit;
use App\Models\SellerLedgerEntry;
use App\Models\SellerWallet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ApproveSellerDepositService
{
    public function handle(
        SellerDeposit $deposit,
        ?User $reviewer = null,
        ?string $reviewNotes = null
    ): SellerDeposit {
        return DB::transaction(function () use (
            $deposit,
            $reviewer,
            $reviewNotes
        ) {
            /** @var SellerDeposit $lockedDeposit */
            $lockedDeposit = SellerDeposit::query()
                ->whereKey($deposit->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedDeposit->status === 'approved') {
                $posted = SellerLedgerEntry::query()->where('idempotency_key', 'deposit:'.$lockedDeposit->id.':approved')->first();
                if ($posted === null || $posted->direction !== 'credit' || $posted->reference_type !== 'seller_deposit'
                    || $posted->reference_id !== $lockedDeposit->id || $posted->seller_id !== $lockedDeposit->seller_id
                    || $posted->seller_wallet_id !== $lockedDeposit->seller_wallet_id
                    || $posted->currency_code !== $lockedDeposit->currency_code
                    || bccomp($posted->amount, $lockedDeposit->amount, 4) !== 0) {
                    throw new RuntimeException('Approved deposit has inconsistent accounting.');
                }

                return $lockedDeposit->fresh();
            }

            if ($lockedDeposit->status !== 'pending') {
                throw new RuntimeException(
                    'Only pending deposits can be approved.'
                );
            }

            /** @var SellerWallet $wallet */
            $wallet = SellerWallet::query()
                ->whereKey($lockedDeposit->seller_wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($wallet->seller_id !== $lockedDeposit->seller_id) {
                throw new RuntimeException(
                    'Deposit wallet does not belong to seller.'
                );
            }

            if ($wallet->currency_code !== $lockedDeposit->currency_code) {
                throw new RuntimeException(
                    'Deposit currency does not match wallet currency.'
                );
            }

            if ($wallet->status !== 'active') {
                throw new RuntimeException(
                    'Seller wallet is not active.'
                );
            }

            $idempotencyKey =
                'deposit:'.$lockedDeposit->id.':approved';

            $existingEntry = SellerLedgerEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingEntry !== null) {
                throw new RuntimeException(
                    'Deposit accounting entry already exists.'
                );
            }

            /*
             * bcadd avoids binary floating-point arithmetic.
             * Values remain decimal strings.
             */
            $newBalance = bcadd(
                (string) $wallet->balance,
                (string) $lockedDeposit->amount,
                4
            );
            if (bccomp((string) $lockedDeposit->amount, '0', 4) <= 0
                || bccomp($newBalance, '9999999999999999.9999', 4) > 0) {
                throw new RuntimeException('Deposit amount or resulting balance is invalid.');
            }

            $now = now();

            SellerLedgerEntry::query()->create([
                'seller_id' => $lockedDeposit->seller_id,
                'seller_wallet_id' => $wallet->id,
                'entry_type' => 'deposit',
                'direction' => 'credit',
                'amount' => $lockedDeposit->amount,
                'currency_code' => $lockedDeposit->currency_code,
                'balance_after' => $newBalance,
                'reference_type' => 'seller_deposit',
                'reference_id' => $lockedDeposit->id,
                'idempotency_key' => $idempotencyKey,
                'description' => 'Approved seller deposit',
                'created_by' => $reviewer?->id,
                'posted_at' => $now,
            ]);

            $wallet->balance = $newBalance;
            $wallet->last_transaction_at = $now;
            $wallet->save();

            $lockedDeposit->status = 'approved';
            $lockedDeposit->reviewed_by = $reviewer?->id;
            $lockedDeposit->review_notes = $reviewNotes;
            $lockedDeposit->reviewed_at = $now;
            $lockedDeposit->approved_at = $now;
            $lockedDeposit->rejected_at = null;
            $lockedDeposit->save();

            return $lockedDeposit->fresh();
        }, 3);
    }
}
