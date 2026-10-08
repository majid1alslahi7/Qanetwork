<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreManualSaleReviewRequest;
use App\Http\Resources\Api\V1\AdminSaleResource;
use App\Http\Resources\Api\V1\ManualSaleReviewResource;
use App\Models\Sale;
use App\Services\Sales\RequestManualSaleReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminSaleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'manual_review' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['created', 'balance_reserved', 'processing_provider', 'provider_confirmed', 'accounting_posted',
                'completed', 'failed', 'timeout', 'unknown_provider_state', 'reconciliation_required', 'reversed'])]]);
        $query = Sale::query()->with(['financial', 'providerTransaction']);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if ($request->boolean('manual_review')) {
            $query->where('status', '!=', 'completed')->whereHas('providerTransaction', fn ($provider) => $provider->whereNotNull('manual_review_required_at'));
        }

        return AdminSaleResource::collection($query->orderByDesc('id')->paginate(25));
    }

    public function show(Sale $sale): AdminSaleResource
    {
        return new AdminSaleResource($sale->load(['financial', 'providerTransaction']));
    }

    public function reviews(Request $request, Sale $sale): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return ManualSaleReviewResource::collection($sale->manualReviews()->orderByDesc('id')->paginate(25));
    }

    public function requestReview(StoreManualSaleReviewRequest $request, Sale $sale, RequestManualSaleReviewService $service): JsonResponse
    {
        return (new ManualSaleReviewResource($service->handle($request->user(), $sale,
            $request->validated('idempotency_key'), $request->validated('reason'))))->response()->setStatusCode(202);
    }
}
