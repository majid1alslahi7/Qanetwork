<?php

namespace App\Services\Accounts;

use App\Enums\UserRole;
use App\Models\AccountRegistration;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewAccountRegistrationService
{
    public function __construct(private readonly CreateAccountService $accounts) {}

    public function handle(User $actor, AccountRegistration $registration, string $decision, string $reason): AccountRegistration
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages(['decision' => 'Invalid decision.']);
        }

        return DB::transaction(function () use ($actor, $registration, $decision, $reason): AccountRegistration {
            $administrator = User::query()->lockForUpdate()->findOrFail($actor->id);
            if ($administrator->role !== UserRole::ADMIN || ! $administrator->canAccessApplication()) {
                throw new AuthorizationException;
            }
            $locked = AccountRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            if ($locked->status !== 'pending') {
                if ($locked->status === $decision) {
                    return $locked;
                }
                throw ValidationException::withMessages(['decision' => 'This request has already been reviewed.']);
            }
            if ($decision === 'approved') {
                if (! in_array($locked->role, ['seller', 'network_owner'], true) || ! is_string($locked->password) || $locked->password === '') {
                    throw ValidationException::withMessages(['decision' => 'The request cannot be approved.']);
                }
                $user = $this->accounts->handle($administrator, [
                    'name' => $locked->name, 'email' => $locked->email, 'password' => $locked->password,
                    'role' => $locked->role, 'business_name' => $locked->business_name, 'currency_code' => $locked->currency_code,
                ]);
                $locked->user_id = $user->id;
            }
            $locked->status = $decision;
            $locked->reviewed_by = $administrator->id;
            $locked->review_reason = $reason;
            $locked->reviewed_at = now();
            $locked->password = null;
            $locked->save();
            AuditEvent::query()->create([
                'actor_id' => $administrator->id, 'event_type' => 'account.registration_reviewed',
                'subject_type' => 'account_registration', 'subject_id' => $locked->id,
                'before' => ['status' => 'pending'], 'after' => ['status' => $decision, 'user_id' => $locked->user_id],
            ]);

            return $locked;
        }, 3);
    }
}
