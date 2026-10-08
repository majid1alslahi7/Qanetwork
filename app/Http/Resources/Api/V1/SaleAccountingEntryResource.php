<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleAccountingEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'sale_id' => $this->sale_id, 'network_owner_id' => $this->network_owner_id,
            'entry_type' => $this->entry_type, 'amount' => $this->amount, 'currency_code' => $this->currency_code,
            'posted_at' => $this->posted_at->toIso8601String()];
    }
}
