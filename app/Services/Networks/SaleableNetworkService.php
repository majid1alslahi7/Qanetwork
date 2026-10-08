<?php

namespace App\Services\Networks;

use App\Enums\UserRole;
use App\Models\Network;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class SaleableNetworkService
{
    /** @return Builder<Network> */
    public function query(): Builder
    {
        $freshSince = now()->subMinutes(5);

        return Network::query()->where('status', 'active')->where('sales_enabled', true)
            ->where('health_status', 'healthy')->where('last_health_check_at', '>=', $freshSince)
            ->whereHas('owner', function (Builder $query): void {
                $query->where('status', 'active')->where(function (Builder $query): void {
                    $query->whereNull('user_id')->orWhereHas('user', function (Builder $query): void {
                        $query->where('status', 'active')->where('role', UserRole::NETWORK_OWNER->value);
                    });
                });
            })->whereHas('connections', function (Builder $query): void {
                $query->where('is_enabled', true)->where('is_primary', true);
            }, '=', 1)->whereHas('connections', function (Builder $query) use ($freshSince): void {
                $query->where('is_enabled', true)->where('is_primary', true)->where('health_status', 'healthy')
                    ->where('last_checked_at', '>=', $freshSince);
            });
    }

    public function ensureAvailable(Network $network): void
    {
        if (! $this->query()->whereKey($network->id)->exists()) {
            throw ValidationException::withMessages(['product_id' => 'The network is not currently available for sale.']);
        }
    }
}
