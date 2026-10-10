<?php

namespace App\Services\Networks;

use App\Enums\UserRole;
use App\Models\InventoryCard;
use App\Models\Network;
use App\Models\NetworkConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ImportVoucherBundleService
{
    public function __construct(private readonly ManageNetworkProductService $products, private readonly ImportInventoryCardsService $inventory, private readonly ManageNetworkConnectionService $connections) {}

    /** @param array<string, mixed> $bundle
     * @return array{products: int, imported: int, existing: int}
     */
    public function handle(User $actor, Network $network, ?NetworkConnection $connection, #[\SensitiveParameter] array $bundle): array
    {
        $validated = Validator::make($bundle, [
            'version' => ['required', 'integer', 'in:1'],
            'network_name' => ['required', 'string', 'max:150'],
            'currency_code' => ['required', 'string', 'size:3'],
            'batches' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'batches.*' => ['required', 'array:batch_id,product,cards,source_limits'],
            'batches.*.batch_id' => ['required', 'string', 'max:100', 'distinct:strict', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'batches.*.product' => ['required', 'array:name,display_name,external_product_id,face_value,data_limit_bytes,duration_minutes'],
            'batches.*.product.name' => ['required', 'string', 'max:150'],
            'batches.*.product.display_name' => ['required', 'string', 'max:150'],
            'batches.*.product.external_product_id' => ['required', 'string', 'max:191', 'distinct:strict', 'regex:/\A[^\x00-\x1F\x7F]+\z/u'],
            'batches.*.product.face_value' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,15}(?:\.[0-9]{1,4})?\z/'],
            'batches.*.product.data_limit_bytes' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'batches.*.product.duration_minutes' => ['required', 'integer', 'between:1,5256000'],
            'batches.*.source_limits' => ['required', 'array:validity,uptime_hours'],
            'batches.*.source_limits.validity' => ['required', 'array:starts_when,days'],
            'batches.*.source_limits.validity.starts_when' => ['required', 'in:first_auth'],
            'batches.*.source_limits.validity.days' => ['required', 'integer', 'between:1,3650'],
            'batches.*.source_limits.uptime_hours' => ['present', 'nullable', 'integer', 'between:1,87600'],
            'batches.*.cards' => ['required', 'array', 'list', 'min:1', 'max:5000'],
            'batches.*.cards.*' => ['required', 'array:username,password'],
            'batches.*.cards.*.username' => ['required', 'string', 'max:255'],
            'batches.*.cards.*.password' => ['present', 'nullable', 'string', 'max:1024'],
        ])->validate();
        if (array_sum(array_map(fn (array $batch): int => count($batch['cards']), $validated['batches'])) > 5000) {
            $this->invalid('A bundle may contain at most 5000 cards.');
        }

        return DB::transaction(function () use ($actor, $network, $connection, $validated): array {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            if (! $user->canAccessApplication() || ! in_array($user->role, [UserRole::ADMIN, UserRole::NETWORK_OWNER], true)) {
                throw new AuthorizationException;
            }
            $owned = Network::query()->when($user->role === UserRole::NETWORK_OWNER,
                fn ($query) => $query->where('network_owner_id', $user->networkOwner()->firstOrFail()->id))
                ->lockForUpdate()->findOrFail($network->id);
            if ($connection !== null) {
                $source = $owned->connections()->lockForUpdate()->findOrFail($connection->id);
            } else {
                $sources = $owned->connections()->where('driver', 'stored_cards')->lockForUpdate()->limit(2)->get();
                if ($sources->count() > 1) {
                    $this->invalid('Select the intended stored-cards connection explicitly.');
                }
                $source = $sources->first() ?? $this->connections->create($user, $owned, ['name' => 'مخزون الكروت المستوردة', 'driver' => 'stored_cards']);
            }
            if ($source->driver !== 'stored_cards' || $owned->currency_code !== $validated['currency_code']) {
                $this->invalid('Select a stored-cards connection and the correct network currency.');
            }
            $result = ['products' => 0, 'imported' => 0, 'existing' => 0];
            $seen = [];
            foreach ($validated['batches'] as $batch) {
                $data = $batch['product'];
                if ((int) $data['duration_minutes'] !== (int) $batch['source_limits']['validity']['days'] * 1440) {
                    $this->invalid('Validity days and duration minutes disagree.');
                }
                $product = $owned->products()->where('external_product_id', $data['external_product_id'])->lockForUpdate()->first();
                if ($product !== null) {
                    if ($product->fulfillment_connection_id !== $source->id || $product->status === 'archived'
                        || bccomp($product->face_value, $data['face_value'], 4) !== 0
                        || $product->data_limit_bytes !== (int) $data['data_limit_bytes']
                        || $product->duration_minutes !== (int) $data['duration_minutes']) {
                        $this->invalid('An existing product has different limits, price or fulfillment source.');
                    }
                } else {
                    $product = $this->products->create($user, $owned, [...$data, 'fulfillment_connection_id' => $source->id,
                        'metadata' => ['imported_vouchers' => $batch['source_limits']]]);
                    $result['products']++;
                }
                $rows = [];
                foreach ($batch['cards'] as $card) {
                    $username = $card['username'];
                    $password = $card['password'];
                    if (preg_match('/[\x00-\x1F\x7F]/', $username) || ($password !== null && preg_match('/[\x00-\x1F\x7F]/', $password))) {
                        $this->invalid('Card credentials contain unsupported control characters.');
                    }
                    $fingerprint = hash_hmac('sha256', $username, (string) config('app.key'));
                    if (isset($seen[$fingerprint])) {
                        $this->invalid('A card occurs more than once in this bundle.');
                    }
                    $seen[$fingerprint] = true;
                    $existing = InventoryCard::query()->where('network_id', $owned->id)->where('fingerprint', $fingerprint)->lockForUpdate()->first();
                    if ($existing !== null) {
                        $credentials = $password === null || $password === '' ? ['username' => $username, 'login_mode' => 'username_only'] : ['username' => $username, 'password' => $password];
                        if ($existing->network_product_id !== $product->id || $existing->credentials_encrypted !== $credentials) {
                            $this->invalid('An existing card has different credentials or belongs to another product.');
                        }
                        $result['existing']++;
                    } else {
                        $rows[] = $card;
                    }
                }
                if ($rows !== []) {
                    $result['imported'] += $this->inventory->handle($user, $owned, $product, $rows);
                }
            }

            return $result;
        }, 3);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['bundle' => $message]);
    }
}
