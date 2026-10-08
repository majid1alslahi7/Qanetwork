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

class SubmitSellerDepositService
{
    /** @param array<string, mixed> $data */
    public function handle(User $actor, array $data): SellerDeposit
    {
        return DB::transaction(function () use ($actor, $data): SellerDeposit {
            $current = User::query()->lockForUpdate()->findOrFail($actor->id);
            if ($current->role !== UserRole::SELLER || ! $current->canAccessApplication()) {
                throw new AuthorizationException;
            }
            $seller = $current->seller()->lockForUpdate()->firstOrFail();
            $key = 'deposit:'.$seller->id.':'.hash('sha256', $data['idempotency_key']);
            $existing = $seller->deposits()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->seller_wallet_id !== $data['wallet_id'] || bccomp($existing->amount, $data['amount'], 4) !== 0
                    || $existing->payment_method !== $data['payment_method']
                    || $existing->external_reference !== ($data['external_reference'] ?? null)
                    || $existing->seller_notes !== ($data['seller_notes'] ?? null)) {
                    throw new ConflictHttpException('The idempotency key has been used for a different deposit request.');
                }

                return $existing;
            }
            $wallet = $seller->wallets()->lockForUpdate()->findOrFail($data['wallet_id']);
            if ($wallet->status !== 'active') {
                throw ValidationException::withMessages(['wallet_id' => 'The wallet must be active.']);
            }
            $deposit = new SellerDeposit(['seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id,
                'reference_no' => 'DEP-'.Str::ulid(), 'amount' => bcadd($data['amount'], '0', 4),
                'currency_code' => $wallet->currency_code, 'payment_method' => $data['payment_method'],
                'external_reference' => $data['external_reference'] ?? null, 'seller_notes' => $data['seller_notes'] ?? null,
                'submitted_at' => now()]);
            $deposit->idempotency_key = $key;
            $deposit->status = 'pending';
            $deposit->save();
            AuditEvent::query()->create(['actor_id' => $actor->id, 'event_type' => 'deposit.submitted',
                'subject_type' => 'seller_deposit', 'subject_id' => $deposit->id,
                'after' => ['amount' => $deposit->amount, 'currency_code' => $deposit->currency_code, 'status' => 'pending']]);

            return $deposit;
        }, 3);
    }
}
