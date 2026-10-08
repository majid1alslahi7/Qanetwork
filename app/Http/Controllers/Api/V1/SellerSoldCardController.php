<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Sales\RevealSoldCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerSoldCardController extends Controller
{
    public function reveal(Request $request, string $sale, RevealSoldCardService $service): JsonResponse
    {
        return response()->json(['data' => $service->handle($request->user(), $sale)])
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
