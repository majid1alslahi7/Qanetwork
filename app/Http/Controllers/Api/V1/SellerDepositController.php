<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSellerDepositRequest;
use App\Http\Resources\Api\V1\SellerDepositResource;
use App\Services\Finance\SubmitSellerDepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SellerDepositController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return SellerDepositResource::collection($request->user()->seller()->firstOrFail()->deposits()->orderByDesc('id')->paginate(25));
    }

    public function show(Request $request, string $deposit): SellerDepositResource
    {
        return new SellerDepositResource($request->user()->seller()->firstOrFail()->deposits()->findOrFail($deposit));
    }

    public function store(StoreSellerDepositRequest $request, SubmitSellerDepositService $service): JsonResponse
    {
        return (new SellerDepositResource($service->handle($request->user(), $request->validated())))->response()->setStatusCode(202);
    }
}
