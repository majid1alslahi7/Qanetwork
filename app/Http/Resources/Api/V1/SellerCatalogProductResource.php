<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SellerCatalogProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $price = $this->seller_offer;

        return ['id' => $this->id, 'network_id' => $this->network_id, 'name' => $this->display_name ?: $this->name,
            'face_value' => $this->face_value, 'currency_code' => $this->currency_code,
            'data_limit_bytes' => $this->data_limit_bytes, 'duration_minutes' => $this->duration_minutes,
            'purchasable' => $price !== null, 'unavailable_reason' => $price === null ? 'pricing_unavailable' : null,
            'pricing' => $price === null ? null : [
                'pricing_rule_id' => $price['pricing_rule_id'], 'commission_rule_id' => $price['commission_rule_id'],
                'face_value' => $price['face_value'], 'seller_commission' => $price['seller_commission'],
                'seller_net_amount' => $price['seller_net_amount'], 'currency_code' => $price['currency_code'],
            ], 'price_is_advisory' => true];
    }
}
