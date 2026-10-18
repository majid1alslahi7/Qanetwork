<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AccountStatementRequest;
use App\Models\User;
use App\Services\Accounting\AccountStatementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class AccountStatementController extends Controller
{
    public function show(AccountStatementRequest $request, AccountStatementService $service, ?User $user = null): JsonResponse
    {
        abort_if($user !== null && $request->user()->role !== UserRole::ADMIN, 403);
        $account = $user ?? $request->user();
        $asOf = $request->filled('as_of') ? CarbonImmutable::parse($request->input('as_of'))->utc() : CarbonImmutable::now('UTC');

        return response()->json($service->handle($account, $request->fromDate(), $request->toDate(), $asOf, (int) $request->input('page', 1)))
            ->header('Cache-Control', 'private, no-store');
    }
}
