<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SellerEarningsReportRequest;
use App\Http\Resources\Api\V1\SaleResource;
use App\Services\Accounting\SellerEarningsReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SellerEarningsController extends Controller
{
    public function summary(SellerEarningsReportRequest $request, SellerEarningsReportService $reports): JsonResponse
    {
        $seller = $request->user()->seller()->where('status', 'active')->firstOrFail();

        return response()->json(['data' => $reports->summary($seller, $request->fromDate(), $request->toDate())]);
    }

    public function index(SellerEarningsReportRequest $request, SellerEarningsReportService $reports): AnonymousResourceCollection
    {
        $seller = $request->user()->seller()->where('status', 'active')->firstOrFail();

        return SaleResource::collection($reports->sales($seller, $request->fromDate(), $request->toDate())
            ->with('financial')->orderByDesc('completed_at')->orderByDesc('id')->paginate(25));
    }
}
