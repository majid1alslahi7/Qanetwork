<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'device_name' => $this->device_name, 'fingerprint' => $this->fingerprint, 'status' => $this->status,
            'imei' => $request->user()?->role === UserRole::ADMIN ? $this->imei : ($this->imei === null ? null : '***********'.substr($this->imei, -4)),
            'imei_source' => 'user_declared', 'approved_at' => $this->approved_at?->toISOString(), 'last_seen_at' => $this->last_seen_at?->toISOString()];
    }
}
