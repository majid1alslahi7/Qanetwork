<?php

namespace App\Services\Networks;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\InventoryCard;
use App\Models\Network;
use App\Models\NetworkProduct;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImportInventoryCardsService
{
    /** @param list<array{username: string, password?: ?string}> $rows */
    public function handle(User $actor, Network $network, NetworkProduct $product, #[\SensitiveParameter] array $rows): int
    {
        if (count($rows) < 1 || count($rows) > 5000) {
            throw ValidationException::withMessages(['file' => 'Import between 1 and 5000 cards per batch.']);
        }
        try {
            return DB::transaction(function () use ($actor, $network, $product, $rows): int {
                $user = User::query()->lockForUpdate()->findOrFail($actor->id);
                if (! $user->canAccessApplication() || ! in_array($user->role, [UserRole::ADMIN, UserRole::NETWORK_OWNER], true)) {
                    throw new AuthorizationException;
                }
                $owned = Network::query()->when($user->role === UserRole::NETWORK_OWNER, fn ($query) => $query->where('network_owner_id', $user->networkOwner()->firstOrFail()->id))->lockForUpdate()->findOrFail($network->id);
                $target = $owned->products()->lockForUpdate()->findOrFail($product->id);
                if ($target->status === 'archived') {
                    throw ValidationException::withMessages(['product_id' => 'Archived products cannot receive inventory.']);
                }
                $seen = [];
                $inserts = [];
                foreach ($rows as $index => $row) {
                    $username = $row['username'] ?? null;
                    $password = $row['password'] ?? null;
                    if (! is_string($username) || $username === '' || strlen($username) > 255 || preg_match('/[\x00-\x1F\x7F]/', $username)
                        || ($password !== null && (! is_string($password) || strlen($password) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $password)))) {
                        throw ValidationException::withMessages(['file' => 'Invalid credentials at row '.($index + 1).'.']);
                    }
                    $fingerprint = hash_hmac('sha256', $username, (string) config('app.key'));
                    if (isset($seen[$fingerprint])) {
                        throw ValidationException::withMessages(['file' => 'Duplicate card at row '.($index + 1).'. No cards were imported.']);
                    }
                    $seen[$fingerprint] = true;
                    $credentials = ['username' => $username];
                    if ($password !== null && $password !== '') {
                        $credentials['password'] = $password;
                    } else {
                        $credentials['login_mode'] = 'username_only';
                    }
                    $card = new InventoryCard(['network_id' => $owned->id, 'network_product_id' => $target->id, 'fingerprint' => $fingerprint]);
                    $card->credentials_encrypted = $credentials;
                    $card->id = (string) Str::ulid();
                    $card->setCreatedAt(now());
                    $card->setUpdatedAt(now());
                    $inserts[] = $card->getAttributes();
                }
                if (InventoryCard::query()->where('network_id', $owned->id)->whereIn('fingerprint', array_keys($seen))->exists()) {
                    throw ValidationException::withMessages(['file' => 'This batch contains existing cards. No cards were imported.']);
                }
                foreach (array_chunk($inserts, 500) as $batch) {
                    InventoryCard::query()->insert($batch);
                }
                AuditEvent::query()->create(['actor_id' => $user->id, 'event_type' => 'inventory.imported', 'subject_type' => 'network_product', 'subject_id' => $target->id,
                    'after' => ['network_id' => $owned->id, 'card_count' => count($rows)]]);

                return count($rows);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['file' => 'Duplicate inventory cards. No cards were imported.']);
        }
    }
}
