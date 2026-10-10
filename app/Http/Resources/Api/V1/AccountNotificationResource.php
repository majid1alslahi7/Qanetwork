<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountNotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'kind' => $this->data['kind'], 'target_id' => $this->data['target_id'],
            'status' => $this->data['status'], 'title' => $this->data['title'], 'message' => $this->data['message'],
            'read_at' => $this->read_at?->toISOString(), 'created_at' => $this->created_at?->toISOString()];
    }
}
