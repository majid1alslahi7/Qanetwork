<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManualSaleReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'sale_id' => $this->sale_id, 'requested_by' => $this->requested_by,
            'reason' => $this->reason, 'status' => $this->status, 'sale_status_before' => $this->sale_status_before,
            'sale_status_after' => $this->sale_status_after, 'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String()];
    }
}
