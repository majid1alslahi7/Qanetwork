<?php

namespace App\Http\Middleware;

use App\Models\AccountDevice;
use App\Services\Auth\DeviceLoginService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->canAccessApplication(), 403, 'Account access is unavailable.');
        $user = $request->user();
        $token = $user->currentAccessToken();
        $device = $token instanceof PersonalAccessToken && $token->account_device_id !== null ? AccountDevice::query()->find($token->account_device_id) : null;
        app(DeviceLoginService::class)->assertAllowed($user, $device);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
