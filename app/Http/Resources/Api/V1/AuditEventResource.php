<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditEventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'event_type' => $this->event_type,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'actor' => $this->whenLoaded('actor', fn (): array => [
                'id' => (string) $this->actor->id, 'name' => $this->actor->name,
            ]),
            'before' => $this->safeSnapshot($this->before),
            'after' => $this->safeSnapshot($this->after),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /** @param array<string, mixed>|null $snapshot @return array<string, bool|int|float|string|null>|null */
    private function safeSnapshot(?array $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }
        $safe = [];
        foreach (['role', 'status', 'sales_enabled', 'is_enabled', 'is_primary', 'network_id',
            'network_owner_id', 'product_id', 'pricing_rule_id', 'sale_id', 'first_reveal',
            'channel', 'amount', 'face_value', 'provider_amount', 'seller_commission',
            'currency_code', 'driver', 'seller_id'] as $key) {
            if (array_key_exists($key, $snapshot) && (is_scalar($snapshot[$key]) || $snapshot[$key] === null)) {
                $safe[$key] = $snapshot[$key];
            }
        }

        return $safe;
    }
}
