<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FailedJobResource;
use App\Services\Operations\OperationsMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class AdminOperationsController extends Controller
{
    public function show(OperationsMonitor $monitor): JsonResponse
    {
        return response()->json(['data' => $monitor->summary()]);
    }

    public function failedJobs(Request $request, OperationsMonitor $monitor): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        try {
            $jobs = $monitor->failedJobs()->select(['id', 'uuid', 'failed_at'])->orderByDesc('id')->paginate(25);
        } catch (Throwable) {
            abort(503, 'Failed-job monitoring is currently unavailable.');
        }

        return FailedJobResource::collection($jobs);
    }
}
