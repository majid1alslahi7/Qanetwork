<?php

namespace App\Services\Networks;

use App\Enums\UserRole;
use App\Models\Network;
use App\Models\NetworkConnection;
use App\Models\NetworkProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class SaleableNetworkService
{
    /** @return Builder<Network> */
    public function query(): Builder
    {
        $freshSince = now()->subMinutes(5);

        return Network::query()->where('status', 'active')->where('sales_enabled', true)
            ->whereHas('owner', function (Builder $query): void {
                $query->where('status', 'active')->where(function (Builder $query): void {
                    $query->whereNull('user_id')->orWhereHas('user', function (Builder $query): void {
                        $query->where('status', 'active')->where('role', UserRole::NETWORK_OWNER->value);
                    });
                });
            })->where(function (Builder $query) use ($freshSince): void {
                $query->where(function (Builder $legacy) use ($freshSince): void {
                    $legacy->where('health_status', 'healthy')->where('last_health_check_at', '>=', $freshSince)
                        ->whereHas('connections', fn (Builder $connections) => $connections->where('is_enabled', true)->where('is_primary', true), '=', 1)
                        ->whereHas('connections', fn (Builder $connections) => $connections->where('is_enabled', true)->where('is_primary', true)->where('health_status', 'healthy')->where('last_checked_at', '>=', $freshSince));
                })->orWhereHas('products', fn (Builder $products) => $this->healthyExplicitProducts($products));
            });
    }

    public function hasHealthyExplicitSource(Network $network): bool
    {
        return $this->healthyExplicitProducts($network->products()->getQuery())->exists();
    }

    /** @param Builder<NetworkProduct> $products
     * @return Builder<NetworkProduct>
     */
    private function healthyExplicitProducts(Builder $products): Builder
    {
        return $products->where('status', 'active')->whereNotNull('fulfillment_connection_id')
            ->whereHas('fulfillmentConnection', fn (Builder $connections) => $connections
                ->whereColumn('network_connections.network_id', 'network_products.network_id')
                ->where('is_enabled', true)->where('health_status', 'healthy')->where('last_checked_at', '>=', now()->subMinutes(5)));
    }

    public function ensureAvailable(Network $network): void
    {
        if (! $this->query()->whereKey($network->id)->exists()) {
            throw ValidationException::withMessages(['product_id' => 'The network is not currently available for sale.']);
        }
    }

    public function connectionForProduct(NetworkProduct $product): NetworkConnection
    {
        if ($product->fulfillment_connection_id !== null) {
            $connection = $product->network->connections()->whereKey($product->fulfillment_connection_id)->first();
        } else {
            $primary = $product->network->connections()->where('is_enabled', true)->where('is_primary', true)->get();
            $connection = $primary->count() === 1 ? $primary->first() : null;
        }
        if ($connection === null || ! $connection->is_enabled || $connection->health_status !== 'healthy'
            || $connection->last_checked_at === null || $connection->last_checked_at->lt(now()->subMinutes(5))) {
            throw ValidationException::withMessages(['product_id' => 'The selected product source is not currently available.']);
        }
        if ($connection->driver === 'stored_cards' && ! $product->inventoryCards()->where('status', 'available')
            ->whereNull('sale_id')->whereNull('internal_transaction_id')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists()) {
            throw ValidationException::withMessages(['product_id' => 'No available cards remain for this product.']);
        }

        return $connection;
    }
}
