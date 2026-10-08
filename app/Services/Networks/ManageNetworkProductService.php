<?php

namespace App\Services\Networks;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Network;
use App\Models\NetworkProduct;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageNetworkProductService
{
    /** @param array<string, mixed> $data */
    public function create(User $actor, Network $network, array $data): NetworkProduct
    {
        return DB::transaction(function () use ($actor, $network, $data): NetworkProduct {
            $currentNetwork = $this->lockNetwork($actor, $network);
            $metadata = $data['metadata'] ?? null;
            if (isset($metadata['hotspot'])) {
                foreach (['limit_bytes_total', 'limit_uptime_seconds'] as $key) {
                    if (isset($metadata['hotspot'][$key])) {
                        $metadata['hotspot'][$key] = (int) $metadata['hotspot'][$key];
                    }
                }
                if (isset($metadata['hotspot']['allow_unlimited'])) {
                    $metadata['hotspot']['allow_unlimited'] = (bool) $metadata['hotspot']['allow_unlimited'];
                }
            }
            $product = new NetworkProduct($data);
            $product->network_id = $currentNetwork->id;
            $product->code = 'PRD-'.Str::ulid();
            $product->currency_code = $currentNetwork->currency_code;
            $product->face_value = bcadd($data['face_value'], '0', 4);
            $product->metadata = $metadata;
            $product->status = 'inactive';
            $product->save();
            $product->refresh();
            $this->audit($actor, $product, 'product.created', null);

            return $product;
        }, 3);
    }

    public function updateStatus(User $actor, Network $network, NetworkProduct $product, string $status): NetworkProduct
    {
        if (! in_array($status, ['active', 'inactive', 'archived'], true)) {
            throw ValidationException::withMessages(['status' => 'Invalid product status.']);
        }

        return DB::transaction(function () use ($actor, $network, $product, $status): NetworkProduct {
            $currentNetwork = $this->lockNetwork($actor, $network);
            $current = $currentNetwork->products()->lockForUpdate()->findOrFail($product->id);
            if ($current->status === $status) {
                return $current;
            }
            $before = ['status' => $current->status];
            $current->status = $status;
            $current->save();
            $this->audit($actor, $current, 'product.status_changed', $before);

            return $current;
        }, 3);
    }

    private function lockNetwork(User $actor, Network $network): Network
    {
        $current = User::query()->lockForUpdate()->findOrFail($actor->id);
        if ($current->role !== UserRole::ADMIN || ! $current->canAccessApplication()) {
            throw new AuthorizationException;
        }

        return Network::query()->lockForUpdate()->findOrFail($network->id);
    }

    /** @param array<string, string>|null $before */
    private function audit(User $actor, NetworkProduct $product, string $event, ?array $before): void
    {
        AuditEvent::query()->create(['actor_id' => $actor->id, 'event_type' => $event,
            'subject_type' => 'network_product', 'subject_id' => $product->id, 'before' => $before,
            'after' => ['network_id' => $product->network_id, 'face_value' => $product->face_value,
                'currency_code' => $product->currency_code, 'status' => $product->status]]);
    }
}
