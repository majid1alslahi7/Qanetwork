<?php

namespace App\Services\Accounts;

use App\Models\Sale;
use App\Models\SaleFinancial;
use App\Models\Seller;
use App\Models\SellerContact;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class SellerContactHistoryService
{
    /** @return Builder<Sale> */
    public function sales(Seller $seller, SellerContact $contact, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        abort_unless($contact->seller_id === $seller->id, 404);

        return $seller->sales()->getQuery()->where('seller_contact_id', $contact->id)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to->addDay());
    }

    /** @return array<string, mixed> */
    public function summary(Seller $seller, SellerContact $contact, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sales = $this->sales($seller, $contact, $from, $to);
        $counts = (clone $sales)->select('status')->selectRaw('count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($count): int => (int) $count)->all();
        $totals = [];
        $snapshots = SaleFinancial::query()->whereIn('sale_id', (clone $sales)->where('status', 'completed')->select('id'));
        foreach ($snapshots->lazyById(500) as $snapshot) {
            $currency = $snapshot->currency_code;
            $totals[$currency] ??= ['currency_code' => $currency, 'sale_count' => 0, 'face_value' => '0.0000', 'seller_commission' => '0.0000', 'seller_net_amount' => '0.0000'];
            foreach (['face_value', 'seller_commission', 'seller_net_amount'] as $amount) {
                $totals[$currency][$amount] = bcadd($totals[$currency][$amount], $snapshot->{$amount}, 4);
            }
            $totals[$currency]['sale_count']++;
        }
        ksort($totals);

        return ['period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => 'UTC', 'basis' => 'created_at'],
            'status_counts' => (object) $counts, 'currencies' => array_values($totals)];
    }
}
