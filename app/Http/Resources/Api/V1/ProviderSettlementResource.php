<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderSettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'network_owner_id' => $this->network_owner_id, 'reference_no' => $this->reference_no,
            'amount' => $this->amount, 'currency_code' => $this->currency_code, 'payment_method' => $this->payment_method,
            'external_reference' => $this->external_reference, 'paid_at' => $this->paid_at->toIso8601String(),
            'notes' => $this->when($request->user()->role === UserRole::ADMIN, $this->notes),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($allocation): array => [
                'entry_id' => $allocation->sale_accounting_entry_id, 'amount' => $allocation->amount,
            ]))];
    }
}
