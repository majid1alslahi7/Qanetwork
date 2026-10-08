<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAccountRequest;
use App\Http\Requests\Api\V1\UpdateAccountStatusRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Accounts\CreateAccountService;
use App\Services\Accounts\UpdateAccountStatusService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class AdminAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return UserResource::collection(User::query()->with(['seller:id,user_id', 'networkOwner:id,user_id'])->orderBy('id')->paginate(25));
    }

    public function store(StoreAccountRequest $request, CreateAccountService $service): UserResource
    {
        try {
            $user = $service->handle($request->user(), $request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists.']);
        }

        return new UserResource($user->load(['seller:id,user_id', 'networkOwner:id,user_id']));
    }

    public function updateStatus(UpdateAccountStatusRequest $request, User $user, UpdateAccountStatusService $service): UserResource
    {
        return new UserResource($service->handle($request->user(), $user, $request->validated('status'))
            ->load(['seller:id,user_id', 'networkOwner:id,user_id']));
    }
}
