<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Auth\ManageNotificationDeviceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class NotificationDeviceController extends Controller
{
    public function store(Request $request, ManageNotificationDeviceTokenService $devices): JsonResponse
    {
        $data = $request->validate(['device_id' => ['required', 'uuid']]);
        abort_unless(Schema::hasTable('notifications'), 503);
        $token = $devices->issue($request->user(), $data['device_id']);

        return response()->json(['data' => ['access_token' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at->toISOString(), 'account_id' => (string) $request->user()->id, 'role' => $request->user()->role->value]], 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, ManageNotificationDeviceTokenService $devices): Response
    {
        $data = $request->validate(['device_id' => ['required', 'uuid']]);
        $devices->revokeDevice($request->user(), $data['device_id']);

        return response()->noContent();
    }
}
