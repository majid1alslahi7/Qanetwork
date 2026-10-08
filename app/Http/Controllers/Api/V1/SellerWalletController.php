<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SellerWalletResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SellerWalletController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $seller = $request->user()->seller()->where('status', 'active')->firstOrFail();

        return SellerWalletResource::collection($seller->wallets()->orderBy('currency_code')->get());
    }

    public function show(Request $request, string $wallet): SellerWalletResource
    {
        $seller = $request->user()->seller()->where('status', 'active')->firstOrFail();

        return new SellerWalletResource($seller->wallets()->whereKey($wallet)->firstOrFail());
    }
}
