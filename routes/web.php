<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): JsonResponse {
    return response()->json(['service' => 'QaNetwork API', 'api_version' => 'v1']);
});
