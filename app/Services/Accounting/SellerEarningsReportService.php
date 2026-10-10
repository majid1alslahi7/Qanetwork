<?php

namespace App\Services\Accounting;

use App\Models\Sale;
use App\Models\SaleFinancial;
use App\Models\Seller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class SellerEarningsReportService
{
    /** @return Builder<Sale> */
    public function sales(Seller $seller, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Sale::query()->where('seller_id', $seller->id)->where('status', 'completed')
            ->where('completed_at', '>=', $from)->where('completed_at', '<', $to->addDay())->whereHas('financial');
    }

    /** @return array{period: array<string, string>, currencies: list<array<string, int|string>>} */
    public function summary(Seller $seller, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = [];
        $snapshots = SaleFinancial::query()->whereIn('sale_id', $this->sales($seller, $from, $to)->select('id'));
        foreach ($snapshots->lazyById(500) as $snapshot) {
            $currency = $snapshot->currency_code;
            $totals[$currency] ??= ['currency_code' => $currency, 'sale_count' => 0, 'face_value' => '0.0000', 'seller_commission' => '0.0000', 'seller_net_amount' => '0.0000'];
            foreach (['face_value', 'seller_commission', 'seller_net_amount'] as $amount) {
                $totals[$currency][$amount] = bcadd($totals[$currency][$amount], $snapshot->{$amount}, 4);
            }
            $totals[$currency]['sale_count']++;
        }
        ksort($totals);

        return ['period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => 'UTC'], 'currencies' => array_values($totals)];
    }
}
