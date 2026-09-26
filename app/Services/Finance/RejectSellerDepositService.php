<?php

namespace App\Services\Finance;

use App\Models\SellerDeposit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RejectSellerDepositService
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

            // إعادة نفس النتيجة تجعل الاستدعاء المتكرر آمناً.
            if ($lockedDeposit->status === 'rejected') {
                return $lockedDeposit->fresh();
            }

            if ($lockedDeposit->status !== 'pending') {
                throw new RuntimeException(
                    'Only pending deposits can be rejected.'
                );
            }

            $now = now();

            $lockedDeposit->status = 'rejected';
            $lockedDeposit->reviewed_by = $reviewer?->id;
            $lockedDeposit->review_notes = $reviewNotes;
            $lockedDeposit->reviewed_at = $now;
            $lockedDeposit->rejected_at = $now;
            $lockedDeposit->approved_at = null;

            $lockedDeposit->save();

            return $lockedDeposit->fresh();
        }, 3);
    }
}
