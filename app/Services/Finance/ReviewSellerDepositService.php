<?php

namespace App\Services\Finance;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\SellerDeposit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ReviewSellerDepositService
{
    public function __construct(private readonly ApproveSellerDepositService $approvals, private readonly RejectSellerDepositService $rejections) {}

    public function handle(User $actor, SellerDeposit $deposit, string $decision, ?string $notes = null): SellerDeposit
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages(['decision' => 'Invalid deposit decision.']);
        }

        return DB::transaction(function () use ($actor, $deposit, $decision, $notes): SellerDeposit {
            $currentActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            if ($currentActor->role !== UserRole::ADMIN || ! $currentActor->canAccessApplication()) {
                throw new AuthorizationException;
            }
            $current = SellerDeposit::query()->lockForUpdate()->findOrFail($deposit->id);
            if ($current->status !== 'pending' && $current->status !== $decision) {
                throw new ConflictHttpException('The deposit already has a different final decision.');
            }
            $before = $current->status;
            if ($decision === 'approved') {
                $wallet = $current->wallet()->lockForUpdate()->firstOrFail();
                if ($before === 'pending' && $wallet->status !== 'active') {
                    throw ValidationException::withMessages(['deposit' => 'The deposit wallet is inactive.']);
                }
                if ($before === 'pending' && (bccomp($current->amount, '0', 4) <= 0
                    || bccomp(bcadd($wallet->balance, $current->amount, 4), '9999999999999999.9999', 4) > 0)) {
                    throw ValidationException::withMessages(['deposit' => 'The deposit amount exceeds the permitted wallet balance.']);
                }
                $result = $this->approvals->handle($current, $actor, $notes);
            } else {
                $result = $this->rejections->handle($current, $actor, $notes);
            }
            if ($before !== $result->status) {
                AuditEvent::query()->create(['actor_id' => $actor->id, 'event_type' => 'deposit.reviewed',
                    'subject_type' => 'seller_deposit', 'subject_id' => $current->id,
                    'before' => ['status' => $before], 'after' => ['status' => $result->status]]);
            }

            return $result;
        }, 3);
    }
}
