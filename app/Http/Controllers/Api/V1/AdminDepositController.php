<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AdminDepositResource;
use App\Models\SellerDeposit;
use App\Services\Finance\ReviewSellerDepositService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminDepositController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])]]);

        return AdminDepositResource::collection(SellerDeposit::query()->with(['seller.user', 'wallet'])->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->orderByDesc('id')->paginate(25));
    }

    public function show(SellerDeposit $deposit): AdminDepositResource
    {
        return new AdminDepositResource($deposit->load(['seller.user', 'wallet']));
    }

    public function review(Request $request, SellerDeposit $deposit, ReviewSellerDepositService $service): AdminDepositResource
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'review_notes' => ['nullable', 'string', 'max:2000'],
            'amount' => ['prohibited'], 'currency_code' => ['prohibited'], 'seller_id' => ['prohibited']]);

        return new AdminDepositResource($service->handle($request->user(), $deposit, $data['decision'], $data['review_notes'] ?? null)->load(['seller.user', 'wallet']));
    }
}
