<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SellerDepositResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'reference_no' => $this->reference_no, 'seller_id' => $this->seller_id,
            'wallet_id' => $this->seller_wallet_id, 'amount' => $this->amount, 'currency_code' => $this->currency_code,
            'payment_method' => $this->payment_method, 'external_reference' => $this->external_reference,
            'seller_notes' => $this->seller_notes, 'status' => $this->status,
            'review_notes' => $this->review_notes, 'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String()];
    }
}
