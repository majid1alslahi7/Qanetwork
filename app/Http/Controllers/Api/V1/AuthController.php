<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\AccountDevice;
use App\Models\User;
use App\Services\Auth\AuthenticateAccountService;
use App\Services\Auth\DeviceLoginService;
use App\Services\Auth\ManageNotificationDeviceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AuthenticateAccountService $authenticate, DeviceLoginService $devices): JsonResponse
    {
        $data = $request->validated();
        $user = $authenticate->handle($data['email'], $data['password']);
        $device = isset($data['device_challenge_id'], $data['device_signature']) ? $devices->verify($user, $data['device_challenge_id'], $data['device_signature']) : null;

        $expiresAt = now()->addHours(8);
        $token = DB::transaction(function () use ($user, $device, $devices, $data, $expiresAt): NewAccessToken {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($current->canAccessApplication(), 403);
            $registered = $device === null ? null : AccountDevice::query()->lockForUpdate()->findOrFail($device->id);
            $devices->assertAllowed($current, $registered);
            $token = $current->createToken($data['device_name'], ['account', $current->role->value], $expiresAt);
            if ($registered !== null) {
                $token->accessToken->account_device_id = $registered->id;
                $token->accessToken->save();
            }

            return $token;
        }, 3);

        return response()->json([
            'token_type' => 'Bearer', 'access_token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => (new UserResource($user))->resolve($request),
        ])->header('Cache-Control', 'no-store');
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request, ManageNotificationDeviceTokenService $devices): Response
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $devices->revokeSession($request->user(), $token);
        }
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
