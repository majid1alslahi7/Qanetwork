<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NetworkProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'network_id' => $this->network_id, 'code' => $this->code,
            'external_product_id' => $this->external_product_id, 'name' => $this->name,
            'display_name' => $this->display_name, 'face_value' => $this->face_value,
            'currency_code' => $this->currency_code, 'data_limit_bytes' => $this->data_limit_bytes,
            'duration_minutes' => $this->duration_minutes, 'metadata' => $this->metadata,
            'status' => $this->status, 'availability_status' => $this->availability_status];
    }
}
