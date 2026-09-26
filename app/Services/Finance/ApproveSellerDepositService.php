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
                'deposit:' . $lockedDeposit->id . ':approved';

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
