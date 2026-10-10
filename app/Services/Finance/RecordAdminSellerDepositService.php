<?php

namespace App\Services\Finance;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\SellerDeposit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RecordAdminSellerDepositService
{
    public function __construct(private readonly ReviewSellerDepositService $reviews) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, User $user, array $data): SellerDeposit
    {
        return DB::transaction(function () use ($actor, $user, $data): SellerDeposit {
            $accounts = User::query()->whereIn('id', [$actor->id, $user->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $currentActor = $accounts->get($actor->id);
            $target = $accounts->get($user->id);
            if ($currentActor->role !== UserRole::ADMIN || ! $currentActor->canAccessApplication()) {
                throw new AuthorizationException;
            }
            if ($target->role !== UserRole::SELLER || ! $target->canAccessApplication()) {
                throw ValidationException::withMessages(['user' => 'An active seller account is required.']);
            }
            $seller = $target->seller()->lockForUpdate()->firstOrFail();
            $key = 'admin-deposit:'.$seller->id.':'.hash('sha256', $data['idempotency_key']);
            $existing = $seller->deposits()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->seller_wallet_id !== $data['wallet_id'] || bccomp($existing->amount, $data['amount'], 4) !== 0
                    || $existing->payment_method !== $data['payment_method'] || $existing->external_reference !== ($data['external_reference'] ?? null)
                    || $existing->review_notes !== $data['review_notes'] || $existing->reviewed_by !== $currentActor->id) {
                    throw new ConflictHttpException('The idempotency key belongs to a different deposit.');
                }

                return $this->reviews->handle($currentActor, $existing, 'approved', $data['review_notes']);
            }
            $wallet = $seller->wallets()->lockForUpdate()->findOrFail($data['wallet_id']);
            if ($wallet->status !== 'active') {
                throw ValidationException::withMessages(['wallet_id' => 'The wallet must be active.']);
            }
            $deposit = new SellerDeposit(['seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id,
                'reference_no' => 'DEP-'.Str::ulid(), 'amount' => bcadd($data['amount'], '0', 4), 'currency_code' => $wallet->currency_code,
                'payment_method' => $data['payment_method'], 'external_reference' => $data['external_reference'] ?? null, 'submitted_at' => now()]);
            $deposit->idempotency_key = $key;
            $deposit->status = 'pending';
            $deposit->save();
            AuditEvent::query()->create(['actor_id' => $currentActor->id, 'event_type' => 'deposit.recorded_by_admin',
                'subject_type' => 'seller_deposit', 'subject_id' => $deposit->id,
                'after' => ['seller_id' => $seller->id, 'wallet_id' => $wallet->id, 'amount' => $deposit->amount, 'currency_code' => $wallet->currency_code]]);

            return $this->reviews->handle($currentActor, $deposit, 'approved', $data['review_notes']);
        }, 3);
    }
}
