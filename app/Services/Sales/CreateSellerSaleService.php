<?php

namespace App\Services\Sales;

use App\Enums\UserRole;
use App\Jobs\ProcessSaleJob;
use App\Models\Network;
use App\Models\NetworkProduct;
use App\Models\Sale;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Networks\SaleableNetworkService;
use App\Services\Pricing\CreatePricedSaleSnapshotService;
use App\Services\Providers\PrepareProviderTransactionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CreateSellerSaleService
{
    public function __construct(
        private readonly CreatePricedSaleSnapshotService $pricing,
        private readonly ReserveSaleBalanceService $reservations,
        private readonly PrepareProviderTransactionService $transactions,
        private readonly SaleableNetworkService $networks,
    ) {}

    /** @param array{product_id: string, wallet_id: string, idempotency_key: string, expected_pricing_rule_id?: string, expected_commission_rule_id?: string} $data */
    public function handle(User $actor, array $data): Sale
    {
        $sale = DB::transaction(function () use ($actor, $data): Sale {
            $currentActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            if ($currentActor->role !== UserRole::SELLER || ! $currentActor->canAccessApplication()) {
                throw new AuthorizationException;
            }
            $seller = $currentActor->seller()->lockForUpdate()->firstOrFail();
            $key = 'seller:'.$seller->id.':'.hash('sha256', $data['idempotency_key']);
            $existing = $seller->sales()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->network_product_id !== $data['product_id'] || $existing->seller_wallet_id !== $data['wallet_id']) {
                    throw new ConflictHttpException('The idempotency key has already been used for a different sale request.');
                }

                return $existing;
            }
            $product = NetworkProduct::query()->findOrFail($data['product_id']);
            $network = Network::query()->lockForUpdate()->findOrFail($product->network_id);
            $product = $network->products()->lockForUpdate()->findOrFail($product->id);
            $this->networks->ensureAvailable($network);
            if ($product->status !== 'active') {
                throw ValidationException::withMessages(['product_id' => 'The network or product is not currently available for sale.']);
            }
            $connection = $this->networks->connectionForProduct($product);
            $wallet = SellerWallet::query()->where('seller_id', $seller->id)->lockForUpdate()->findOrFail($data['wallet_id']);
            if ($wallet->status !== 'active' || $wallet->currency_code !== $product->currency_code) {
                throw ValidationException::withMessages(['wallet_id' => 'The wallet must be active and use the product currency.']);
            }
            $sale = Sale::query()->create(['seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id,
                'network_id' => $network->id, 'network_product_id' => $product->id,
                'reference_no' => 'SALE-'.Str::ulid(), 'idempotency_key' => $key,
                'currency_code' => $product->currency_code, 'delivery_method' => 'screen']);
            $financial = $this->pricing->handle($sale);
            if ((isset($data['expected_pricing_rule_id']) && $data['expected_pricing_rule_id'] !== $financial->pricing_rule_id)
                || (isset($data['expected_commission_rule_id']) && $data['expected_commission_rule_id'] !== $financial->commission_rule_id)) {
                throw new ConflictHttpException('The price or commission has changed. Review the current offer before purchasing.');
            }
            if (bccomp(bcsub($wallet->balance, $wallet->reserved_balance, 4), $financial->seller_net_amount, 4) < 0) {
                throw ValidationException::withMessages(['wallet_id' => 'Insufficient available wallet balance.']);
            }
            $this->reservations->handle($sale, 'sale:'.$sale->id.':reservation');
            $this->transactions->handle($sale, $connection);

            return $sale->fresh();
        }, 3);
        if ($sale->status === 'balance_reserved') {
            ProcessSaleJob::dispatch($sale->id)->afterCommit();
        }

        return $sale->load('financial');
    }
}
