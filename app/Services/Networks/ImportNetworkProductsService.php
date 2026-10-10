<?php

namespace App\Services\Networks;

use App\Enums\UserRole;
use App\Models\Network;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ImportNetworkProductsService
{
    public function __construct(private readonly ReadInventorySpreadsheetService $reader, private readonly ManageNetworkProductService $products) {}

    /** @return list<string> */
    public function handle(User $actor, Network $network, UploadedFile $file, ?string $connectionId = null): array
    {
        $rows = $this->reader->readProductTable($file);
        $header = array_shift($rows);
        $allowed = ['name', 'display_name', 'external_product_id', 'face_value', 'data_limit_bytes', 'duration_minutes'];
        $mapping = [];
        foreach ($header ?? [] as $column => $label) {
            $key = mb_strtolower(trim(ltrim($label, "\xEF\xBB\xBF")));
            if (! in_array($key, $allowed, true) || in_array($key, $mapping, true)) {
                throw ValidationException::withMessages(['file' => 'Use only the supported product columns without duplicate headers.']);
            }
            $mapping[$column] = $key;
        }
        foreach (['name', 'external_product_id', 'face_value'] as $required) {
            if (! in_array($required, $mapping, true)) {
                throw ValidationException::withMessages(['file' => 'The product file requires name, external_product_id and face_value columns.']);
            }
        }
        $batch = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            if (array_filter($row, fn (string $value): bool => trim($value) !== '') === []) {
                continue;
            }
            $data = [];
            foreach ($row as $column => $value) {
                if (! isset($mapping[$column]) && $value !== '') {
                    throw ValidationException::withMessages(['file' => 'Unexpected product column at row '.($index + 2).'.']);
                }
            }
            foreach ($mapping as $column => $key) {
                $value = trim($row[$column] ?? '');
                if ($value !== '') {
                    $data[$key] = $value;
                }
            }
            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:150'], 'display_name' => ['nullable', 'string', 'max:150'],
                'external_product_id' => ['required', 'string', 'max:191', 'regex:/\A[^\x00-\x1F\x7F]+\z/u'],
                'face_value' => ['bail', 'required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?\z/', function (string $attribute, mixed $value, Closure $fail): void {
                    if (bccomp($value, '0', 4) <= 0) {
                        $fail('The face value must be positive.');
                    }
                }],
                'data_limit_bytes' => ['nullable', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
                'duration_minutes' => ['nullable', 'integer', 'between:1,4294967295'],
            ]);
            if ($validator->fails()) {
                throw ValidationException::withMessages(['file' => 'Invalid product data at row '.($index + 2).': '.implode(', ', array_keys($validator->errors()->messages())).'.']);
            }
            if (isset($seen[$data['external_product_id']])) {
                throw ValidationException::withMessages(['file' => 'Duplicate product identifier at row '.($index + 2).'. No products were imported.']);
            }
            $seen[$data['external_product_id']] = true;
            $batch[] = $validator->validated();
        }
        if (count($batch) < 1 || count($batch) > 500) {
            throw ValidationException::withMessages(['file' => 'Import between 1 and 500 products per file.']);
        }
        try {
            return DB::transaction(function () use ($actor, $network, $connectionId, $batch, $seen): array {
                $user = User::query()->lockForUpdate()->findOrFail($actor->id);
                if (! $user->canAccessApplication() || ! in_array($user->role, [UserRole::ADMIN, UserRole::NETWORK_OWNER], true)) {
                    throw new AuthorizationException;
                }
                $owned = Network::query()->when($user->role === UserRole::NETWORK_OWNER, fn ($query) => $query->where('network_owner_id', $user->networkOwner()->firstOrFail()->id))->lockForUpdate()->findOrFail($network->id);
                if ($connectionId !== null) {
                    $owned->connections()->lockForUpdate()->findOrFail($connectionId);
                }
                if ($owned->products()->whereIn('external_product_id', array_keys($seen))->exists()) {
                    throw ValidationException::withMessages(['file' => 'This file contains existing products. No products were imported.']);
                }
                $ids = [];
                foreach ($batch as $data) {
                    $ids[] = $this->products->create($user, $owned, [...$data, 'fulfillment_connection_id' => $connectionId])->id;
                }

                return $ids;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['file' => 'Duplicate product identifiers. No products were imported.']);
        }
    }
}
