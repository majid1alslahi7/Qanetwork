<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SellerWalletResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'currency_code' => $this->currency_code,
            'balance' => $this->balance, 'reserved_balance' => $this->reserved_balance,
            'available_balance' => bcsub($this->balance, $this->reserved_balance, 4),
            'status' => $this->status];
    }
}
