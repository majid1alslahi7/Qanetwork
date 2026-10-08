<?php

namespace App\Services\Accounts;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\NetworkOwner;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateAccountService
{
    /** @param array{name: string, email: string, password: string, role: string, business_name?: ?string, currency_code?: string} $data */
    public function handle(User $actor, #[\SensitiveParameter] array $data): User
    {
        if ($actor->role !== UserRole::ADMIN || ! $actor->canAccessApplication()) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $data): User {
            $currentActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            if ($currentActor->role !== UserRole::ADMIN || ! $currentActor->canAccessApplication()) {
                throw new AuthorizationException;
            }
            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->role = UserRole::from($data['role']);
            $user->status = 'active';
            $user->save();
            if ($user->role === UserRole::SELLER) {
                $seller = new Seller([
                    'user_id' => $user->id,
                    'code' => 'SEL-'.Str::ulid(),
                    'business_name' => $data['business_name'] ?? $data['name'],
                ]);
                $seller->status = 'active';
                $seller->activated_at = now();
                $seller->save();
                SellerWallet::query()->create([
                    'seller_id' => $seller->id, 'currency_code' => $data['currency_code'] ?? 'YER',
                ]);
            } elseif ($user->role === UserRole::NETWORK_OWNER) {
                $owner = new NetworkOwner(['code' => 'OWN-'.Str::ulid(), 'name' => $data['name']]);
                $owner->user_id = $user->id;
                $owner->status = 'active';
                $owner->activated_at = now();
                $owner->save();
            }
            AuditEvent::query()->create([
                'actor_id' => $actor->id, 'event_type' => 'account.created',
                'subject_type' => 'user', 'subject_id' => (string) $user->id,
                'after' => ['role' => $user->role->value, 'status' => $user->status],
            ]);

            return $user;
        }, 3);
    }
}
