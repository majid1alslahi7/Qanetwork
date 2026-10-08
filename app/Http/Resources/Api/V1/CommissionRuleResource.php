<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'pricing_rule_id' => $this->pricing_rule_id, 'seller_id' => $this->seller_id,
            'seller_commission' => $this->seller_commission, 'is_active' => $this->is_active];
    }
}
