<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NetworkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'network_owner_id' => $this->network_owner_id,
            'code' => $this->code,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'timezone' => $this->timezone,
            'currency_code' => $this->currency_code,
            'status' => $this->status,
            'health_status' => $this->health_status,
            'sales_enabled' => $this->sales_enabled,
        ];
    }
}
