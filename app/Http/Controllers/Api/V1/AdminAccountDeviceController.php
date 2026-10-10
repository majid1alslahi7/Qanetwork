<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AccountDeviceResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Accounts\ManageAccountDeviceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminAccountDeviceController extends Controller
{
    public function index(Request $request, User $user): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return AccountDeviceResource::collection($user->devices()->orderByDesc('id')->paginate(25));
    }

    public function update(Request $request, User $user, string $device, ManageAccountDeviceService $service): AccountDeviceResource
    {
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'blocked'])], 'user_id' => ['prohibited'], 'imei' => ['prohibited'], 'public_key' => ['prohibited']]);

        return new AccountDeviceResource($service->update($request->user(), $user, $device, $data['status']));
    }

    public function policy(Request $request, User $user, ManageAccountDeviceService $service): UserResource
    {
        $data = $request->validate(['require_device_approval' => ['required', 'boolean'], 'status' => ['prohibited']]);

        return new UserResource($service->policy($request->user(), $user, $data['require_device_approval']));
    }
}
