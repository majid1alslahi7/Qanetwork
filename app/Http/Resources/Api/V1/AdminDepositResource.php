<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;

class AdminDepositResource extends SellerDepositResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'seller' => $this->whenLoaded('seller', fn () => [
                'id' => $this->seller->id, 'code' => $this->seller->code,
                'name' => $this->seller->business_name ?: $this->seller->user?->name,
            ]), 'wallet' => $this->whenLoaded('wallet', fn () => [
                'id' => $this->wallet->id, 'currency_code' => $this->wallet->currency_code,
                'status' => $this->wallet->status,
            ]),
        ]);
    }
}
