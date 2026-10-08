<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSellerSaleRequest;
use App\Http\Resources\Api\V1\SaleResource;
use App\Services\Sales\CreateSellerSaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SellerSaleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return SaleResource::collection($request->user()->seller()->firstOrFail()->sales()
            ->with('financial')->orderByDesc('id')->paginate(25));
    }

    public function show(Request $request, string $sale): SaleResource
    {
        return new SaleResource($request->user()->seller()->firstOrFail()->sales()->with('financial')->findOrFail($sale));
    }

    public function store(StoreSellerSaleRequest $request, CreateSellerSaleService $service): JsonResponse
    {
        return (new SaleResource($service->handle($request->user(), $request->validated())))->response()->setStatusCode(202);
    }
}
