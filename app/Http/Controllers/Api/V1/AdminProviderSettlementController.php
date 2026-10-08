<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProviderSettlementRequest;
use App\Http\Resources\Api\V1\ProviderSettlementResource;
use App\Models\NetworkOwner;
use App\Models\ProviderSettlement;
use App\Services\Accounting\RecordProviderSettlementService;
use App\Services\Accounting\SaleAccountingReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminProviderSettlementController extends Controller
{
    public function index(Request $request, NetworkOwner $owner): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return ProviderSettlementResource::collection($owner->settlements()->orderByDesc('id')->paginate(25));
    }

    public function show(NetworkOwner $owner, ProviderSettlement $settlement): ProviderSettlementResource
    {
        return new ProviderSettlementResource($settlement->load('allocations'));
    }

    public function store(StoreProviderSettlementRequest $request, NetworkOwner $owner, RecordProviderSettlementService $service): ProviderSettlementResource
    {
        return new ProviderSettlementResource($service->handle($request->user(), $owner, $request->validated())->load('allocations'));
    }

    public function balance(NetworkOwner $owner, SaleAccountingReportService $reports): JsonResponse
    {
        return response()->json(['data' => $reports->ownerBalance($owner)]);
    }
}
