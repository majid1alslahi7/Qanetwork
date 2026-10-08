<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;

class AdminSaleResource extends SaleResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        $data['seller_id'] = $this->seller_id;
        $data['provider'] = $this->whenLoaded('providerTransaction', fn () => [
            'id' => $this->providerTransaction->id, 'status' => $this->providerTransaction->status,
            'connection_id' => $this->providerTransaction->network_connection_id,
            'attempt_count' => $this->providerTransaction->attempt_count,
            'reconciliation_attempt_count' => $this->providerTransaction->reconciliation_attempt_count,
            'manual_review_required_at' => $this->providerTransaction->manual_review_required_at?->toIso8601String(),
        ]);
        $data['accounting'] = $this->whenLoaded('financial', fn () => [
            'provider_amount' => $this->financial->provider_amount,
            'platform_commission' => $this->financial->platform_commission,
            'network_owner_id' => $this->financial->network_owner_id,
        ]);

        return $data;
    }
}
