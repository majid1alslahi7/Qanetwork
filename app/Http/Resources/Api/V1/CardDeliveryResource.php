<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardDeliveryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'sale_id' => $this->sale_id, 'channel' => $this->channel,
            'recipient_hint' => $this->recipient_hint, 'status' => $this->status,
            'attempt_count' => $this->attempt_count, 'failure_code' => $this->failure_code,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String()];
    }
}
