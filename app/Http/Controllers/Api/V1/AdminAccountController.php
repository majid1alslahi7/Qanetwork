<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAccountRequest;
use App\Http\Requests\Api\V1\StoreAdminSellerDepositRequest;
use App\Http\Requests\Api\V1\UpdateAccountProfileRequest;
use App\Http\Requests\Api\V1\UpdateAccountStatusRequest;
use App\Http\Resources\Api\V1\AdminAccountDetailResource;
use App\Http\Resources\Api\V1\AdminDepositResource;
use App\Http\Resources\Api\V1\SellerContactResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Accounts\CreateAccountService;
use App\Services\Accounts\UpdateAccountProfileService;
use App\Services\Accounts\UpdateAccountStatusService;
use App\Services\Finance\RecordAdminSellerDepositService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'role' => ['sometimes', Rule::enum(UserRole::class)],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])], 'search' => ['sometimes', 'string', 'max:150']]);

        return UserResource::collection(User::query()->with(['seller:id,user_id', 'networkOwner:id,user_id'])
            ->when(isset($data['role']), fn ($query) => $query->where('role', $data['role']))
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(isset($data['search']), fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$data['search'].'%')->orWhere('email', 'like', '%'.$data['search'].'%')))
            ->orderBy('id')->paginate(25));
    }

    public function show(User $user): AdminAccountDetailResource
    {
        return new AdminAccountDetailResource($user->load(['seller.wallets', 'networkOwner.networks']));
    }

    public function contacts(Request $request, User $user): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return SellerContactResource::collection($user->seller()->firstOrFail()->contacts()->orderBy('name')->paginate(25));
    }

    public function update(UpdateAccountProfileRequest $request, User $user, UpdateAccountProfileService $service): AdminAccountDetailResource
    {
        try {
            $user = $service->handle($request->user(), $user, $request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists.']);
        }

        return new AdminAccountDetailResource($user->load(['seller.wallets', 'networkOwner.networks']));
    }

    public function storeDeposit(StoreAdminSellerDepositRequest $request, User $user, RecordAdminSellerDepositService $service): AdminDepositResource
    {
        return new AdminDepositResource($service->handle($request->user(), $user, $request->validated())->load(['seller.user', 'wallet']));
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
