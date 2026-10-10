<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SellerContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'phone' => $this->phone, 'email' => $this->email,
            'address' => $this->address, 'notes' => $this->notes, 'is_favorite' => $this->is_favorite, 'updated_at' => $this->updated_at?->toISOString()];
    }
}
