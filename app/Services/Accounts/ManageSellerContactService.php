<?php

namespace App\Services\Accounts;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Seller;
use App\Models\SellerContact;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ManageSellerContactService
{
    /** @param array<string, mixed> $data */
    public function save(User $actor, ?string $id, array $data): SellerContact
    {
        return DB::transaction(function () use ($actor, $id, $data): SellerContact {
            $seller = $this->lockedSeller($actor);
            $contact = $id === null ? new SellerContact : $seller->contacts()->lockForUpdate()->findOrFail($id);
            $contact->seller_id = $seller->id;
            $contact->fill($data);
            $contact->save();
            $this->audit($actor, $contact, $id === null ? 'contact.created' : 'contact.updated');

            return $contact;
        }, 3);
    }

    public function delete(User $actor, string $id): void
    {
        DB::transaction(function () use ($actor, $id): void {
            $contact = $this->lockedSeller($actor)->contacts()->lockForUpdate()->findOrFail($id);
            $this->audit($actor, $contact, 'contact.deleted');
            $contact->delete();
        }, 3);
    }

    private function lockedSeller(User $actor): Seller
    {
        $current = User::query()->lockForUpdate()->findOrFail($actor->id);
        if ($current->role !== UserRole::SELLER || ! $current->canAccessApplication()) {
            throw new AuthorizationException;
        }

        return $current->seller()->lockForUpdate()->firstOrFail();
    }

    private function audit(User $actor, SellerContact $contact, string $event): void
    {
        AuditEvent::query()->create(['actor_id' => $actor->id, 'event_type' => $event, 'subject_type' => 'seller_contact', 'subject_id' => $contact->id,
            'after' => ['seller_id' => $contact->seller_id, 'is_favorite' => $contact->is_favorite]]);
    }
}
