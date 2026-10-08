<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email,
            'role' => $this->role->value, 'status' => $this->status,
            $this->mergeWhen($request->user()?->role === UserRole::ADMIN, [
                'seller_id' => $this->whenLoaded('seller', fn (): ?string => $this->seller?->id),
                'network_owner_id' => $this->whenLoaded('networkOwner', fn (): ?string => $this->networkOwner?->id),
            ])];
    }
}
