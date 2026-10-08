<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AccountingReportRequest;
use App\Http\Resources\Api\V1\ProviderSettlementResource;
use App\Http\Resources\Api\V1\SaleAccountingEntryResource;
use App\Services\Accounting\SaleAccountingReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OwnerAccountingController extends Controller
{
    public function index(AccountingReportRequest $request, SaleAccountingReportService $reports): AnonymousResourceCollection
    {
        return SaleAccountingEntryResource::collection($reports->entries($request->fromDate(), $request->toDate(),
            $request->user()->networkOwner()->firstOrFail())->orderByDesc('id')->paginate(25));
    }

    public function summary(AccountingReportRequest $request, SaleAccountingReportService $reports): JsonResponse
    {
        return response()->json(['data' => $reports->summary($request->fromDate(), $request->toDate(), $request->user()->networkOwner()->firstOrFail())]);
    }

    public function show(Request $request, string $entry): SaleAccountingEntryResource
    {
        return new SaleAccountingEntryResource($request->user()->networkOwner()->firstOrFail()->accountingEntries()
            ->where('entry_type', 'provider_payable')->findOrFail($entry));
    }

    public function settlements(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return ProviderSettlementResource::collection($request->user()->networkOwner()->firstOrFail()->settlements()->orderByDesc('id')->paginate(25));
    }

    public function settlement(Request $request, string $settlement): ProviderSettlementResource
    {
        return new ProviderSettlementResource($request->user()->networkOwner()->firstOrFail()->settlements()->with('allocations')->findOrFail($settlement));
    }

    public function balance(Request $request, SaleAccountingReportService $reports): JsonResponse
    {
        return response()->json(['data' => $reports->ownerBalance($request->user()->networkOwner()->firstOrFail())]);
    }
}
