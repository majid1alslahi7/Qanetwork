<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeviceChallengeRequest;
use App\Services\Auth\AuthenticateAccountService;
use App\Services\Auth\DeviceLoginService;
use Illuminate\Http\JsonResponse;

class DeviceChallengeController extends Controller
{
    public function __invoke(DeviceChallengeRequest $request, AuthenticateAccountService $authenticate, DeviceLoginService $devices): JsonResponse
    {
        $data = $request->validated();
        $user = $authenticate->handle($data['email'], $data['password']);

        return response()->json(['data' => $devices->challenge($user, $data['public_key'], $data['device_name'], $data['imei'] ?? null)])
            ->header('Cache-Control', 'no-store');
    }
}
