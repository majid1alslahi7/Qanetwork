<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NetworkConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'network_id' => $this->network_id,
            'name' => $this->name, 'driver' => $this->driver,
            'config' => array_intersect_key($this->config ?? [], array_flip(['host', 'port', 'tls'])),
            'is_enabled' => $this->is_enabled, 'is_primary' => $this->is_primary,
            'health_status' => $this->health_status,
            'connect_timeout' => $this->connect_timeout, 'request_timeout' => $this->request_timeout,
        ];
    }
}
