<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AuditEventRequest;
use App\Http\Resources\Api\V1\AuditEventResource;
use App\Models\AuditEvent;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminAuditEventController extends Controller
{
    public function index(AuditEventRequest $request): AnonymousResourceCollection
    {
        $query = AuditEvent::query()->with('actor:id,name');
        foreach ($request->safe()->only(['event_type', 'subject_type', 'subject_id', 'actor_id']) as $field => $value) {
            if ($value !== null) {
                $query->where($field, $value);
            }
        }

        return AuditEventResource::collection($query->orderByDesc('id')->paginate(25)->withQueryString());
    }
}
