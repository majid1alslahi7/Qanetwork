<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NetworkResource;
use App\Jobs\CheckNetworkConnectionHealthJob;
use App\Models\Network;
use App\Models\NetworkConnection;
use App\Services\Networks\UpdateNetworkSalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminNetworkReadinessController extends Controller
{
    public function check(Request $request, Network $network, NetworkConnection $connection): JsonResponse
    {
        if (! $connection->is_enabled) {
            throw ValidationException::withMessages(['connection' => 'The connection must be enabled before checking health.']);
        }
        CheckNetworkConnectionHealthJob::dispatch($connection->id)->afterCommit();

        return response()->json(['data' => ['connection_id' => $connection->id, 'status' => 'queued']], 202);
    }

    public function updateSales(Request $request, Network $network, UpdateNetworkSalesService $service): NetworkResource
    {
        $request->validate(['sales_enabled' => ['required', 'boolean']]);

        return new NetworkResource($service->handle($request->user(), $network, $request->boolean('sales_enabled')));
    }
}
