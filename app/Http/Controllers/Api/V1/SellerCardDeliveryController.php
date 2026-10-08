<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCardDeliveryRequest;
use App\Http\Resources\Api\V1\CardDeliveryResource;
use App\Services\Delivery\RequestCardDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerCardDeliveryController extends Controller
{
    public function store(StoreCardDeliveryRequest $request, string $sale, RequestCardDeliveryService $service): JsonResponse
    {
        return (new CardDeliveryResource($service->handle($request->user(), $sale, $request->validated())))
            ->response()->setStatusCode(202);
    }

    public function show(Request $request, string $sale): CardDeliveryResource
    {
        $ownedSale = $request->user()->seller()->firstOrFail()->sales()->findOrFail($sale);

        return new CardDeliveryResource($ownedSale->delivery()->firstOrFail());
    }
}
