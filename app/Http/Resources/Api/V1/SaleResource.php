<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'reference_no' => $this->reference_no, 'status' => $this->status,
            'network_id' => $this->network_id, 'product_id' => $this->network_product_id,
            'wallet_id' => $this->seller_wallet_id, 'currency_code' => $this->currency_code,
            'delivery_method' => $this->delivery_method, 'created_at' => $this->created_at?->toIso8601String(),
            'customer' => $this->when($request->user()?->role === UserRole::SELLER && $this->customer_snapshot !== null, fn () => [
                'contact_id' => $this->seller_contact_id, 'name' => $this->customer_snapshot['name'], 'phone' => $this->customer_snapshot['phone'],
            ]),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'financial' => $this->whenLoaded('financial', fn () => [
                'face_value' => $this->financial->face_value,
                'seller_commission' => $this->financial->seller_commission,
                'seller_net_amount' => $this->financial->seller_net_amount,
            ])];
    }
}
