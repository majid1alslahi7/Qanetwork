<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAccountRegistrationRequest;
use App\Http\Resources\Api\V1\AccountRegistrationResource;
use App\Models\AccountRegistration;
use App\Services\Accounts\ReviewAccountRegistrationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountRegistrationController extends Controller
{
    public function store(StoreAccountRegistrationRequest $request): JsonResponse
    {
        try {
            $registration = AccountRegistration::query()->create($request->safe()->only(['name', 'email', 'password', 'role', 'business_name', 'currency_code']));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'An account or request with this email already exists.']);
        }

        return response()->json(['data' => ['id' => $registration->id, 'status' => 'pending']], 201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected'])]]);

        return AccountRegistrationResource::collection(AccountRegistration::query()
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(25));
    }

    public function show(AccountRegistration $registration): AccountRegistrationResource
    {
        return new AccountRegistrationResource($registration);
    }

    public function review(Request $request, AccountRegistration $registration, ReviewAccountRegistrationService $service): AccountRegistrationResource
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        try {
            return new AccountRegistrationResource($service->handle($request->user(), $registration, $validated['decision'], $validated['reason']));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['decision' => 'An account with this email already exists.']);
        }
    }
}
