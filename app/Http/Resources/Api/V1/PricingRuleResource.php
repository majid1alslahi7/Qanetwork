<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PricingRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'network_product_id' => $this->network_product_id,
            'face_value' => $this->face_value, 'provider_amount' => $this->provider_amount,
            'currency_code' => $this->currency_code, 'is_active' => $this->is_active,
            'commissions' => $this->whenLoaded('commissions', fn () => $this->commissions->map(fn ($rule): array => [
                'id' => $rule->id, 'seller_id' => $rule->seller_id, 'seller_commission' => $rule->seller_commission,
                'seller_name' => $rule->relationLoaded('seller') ? $rule->seller?->business_name : null,
                'is_active' => $rule->is_active,
            ]))];
    }
}
