<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Services\Networks\ImportNetworkProductsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NetworkProductImportController extends Controller
{
    public function store(Request $request, Network $network, ImportNetworkProductsService $service): JsonResponse
    {
        if ($request->user()->role === UserRole::NETWORK_OWNER) {
            abort_unless($network->network_owner_id === $request->user()->networkOwner()->firstOrFail()->id, 404);
        }
        $validated = $request->validate(['file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv'],
            'fulfillment_connection_id' => ['nullable', 'ulid', Rule::exists('network_connections', 'id')->where('network_id', $network->id)]]);
        $ids = $service->handle($request->user(), $network, $request->file('file'), $validated['fulfillment_connection_id'] ?? null);

        return response()->json(['data' => ['network_id' => $network->id, 'imported_count' => count($ids), 'product_ids' => $ids]], 201);
    }
}
