<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;

class AdminAccountDetailResource extends UserResource
{
    public function toArray(Request $request): array
    {
        $seller = $this->seller;
        $owner = $this->networkOwner;

        return parent::toArray($request) + ['profile' => [
            'business_name' => $seller?->business_name ?? $owner?->commercial_name,
            'phone' => $seller?->phone ?? $owner?->phone, 'city' => $seller?->city ?? $owner?->city, 'address' => $seller?->address ?? $owner?->address],
            'wallets' => $seller === null ? [] : SellerWalletResource::collection($seller->wallets),
            'networks' => $owner === null ? [] : NetworkResource::collection($owner->networks)];
    }
}
