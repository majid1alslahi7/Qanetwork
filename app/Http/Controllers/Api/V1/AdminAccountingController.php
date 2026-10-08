<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AccountingReportRequest;
use App\Http\Resources\Api\V1\SaleAccountingEntryResource;
use App\Models\SaleAccountingEntry;
use App\Services\Accounting\SaleAccountingReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminAccountingController extends Controller
{
    public function index(AccountingReportRequest $request, SaleAccountingReportService $reports): AnonymousResourceCollection
    {
        return SaleAccountingEntryResource::collection($reports->entries($request->fromDate(), $request->toDate())->orderByDesc('id')->paginate(25));
    }

    public function summary(AccountingReportRequest $request, SaleAccountingReportService $reports): JsonResponse
    {
        return response()->json(['data' => $reports->summary($request->fromDate(), $request->toDate())]);
    }

    public function show(SaleAccountingEntry $entry): SaleAccountingEntryResource
    {
        return new SaleAccountingEntryResource($entry);
    }
}
