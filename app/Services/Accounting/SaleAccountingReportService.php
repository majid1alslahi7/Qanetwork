<?php

namespace App\Services\Accounting;

use App\Models\NetworkOwner;
use App\Models\ProviderSettlement;
use App\Models\SaleAccountingEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SaleAccountingReportService
{
    public function entries(CarbonImmutable $from, CarbonImmutable $to, ?NetworkOwner $owner = null): Builder
    {
        $query = SaleAccountingEntry::query()->where('posted_at', '>=', $from)->where('posted_at', '<', $to->addDay());
        if ($owner !== null) {
            $query->where('network_owner_id', $owner->id)->where('entry_type', 'provider_payable');
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public function summary(CarbonImmutable $from, CarbonImmutable $to, ?NetworkOwner $owner = null): array
    {
        $totals = [];
        foreach ($this->entries($from, $to, $owner)->select(['entry_type', 'currency_code', 'amount'])->cursor() as $entry) {
            if (! isset($totals[$entry->currency_code])) {
                $totals[$entry->currency_code] = ['currency_code' => $entry->currency_code, 'provider_payable' => '0.0000', 'entry_count' => 0];
                if ($owner === null) {
                    $totals[$entry->currency_code]['platform_revenue'] = '0.0000';
                }
            }
            $totals[$entry->currency_code][$entry->entry_type] = bcadd($totals[$entry->currency_code][$entry->entry_type], $entry->amount, 4);
            $totals[$entry->currency_code]['entry_count']++;
        }
        ksort($totals);

        return ['period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => 'UTC'],
            'currencies' => array_values($totals)];
    }

    /** @return array<string, mixed> */
    public function ownerBalance(NetworkOwner $owner): array
    {
        return DB::transaction(function () use ($owner): array {
            NetworkOwner::query()->sharedLock()->findOrFail($owner->id);
            $totals = [];
            foreach (SaleAccountingEntry::query()->where('network_owner_id', $owner->id)->where('entry_type', 'provider_payable')
                ->select(['currency_code', 'amount'])->cursor() as $entry) {
                $totals[$entry->currency_code] ??= ['currency_code' => $entry->currency_code, 'accrued' => '0.0000', 'settled' => '0.0000'];
                $totals[$entry->currency_code]['accrued'] = bcadd($totals[$entry->currency_code]['accrued'], $entry->amount, 4);
            }
            foreach (ProviderSettlement::query()->where('network_owner_id', $owner->id)->select(['currency_code', 'amount'])->cursor() as $payment) {
                $totals[$payment->currency_code] ??= ['currency_code' => $payment->currency_code, 'accrued' => '0.0000', 'settled' => '0.0000'];
                $totals[$payment->currency_code]['settled'] = bcadd($totals[$payment->currency_code]['settled'], $payment->amount, 4);
            }
            foreach ($totals as &$total) {
                $total['outstanding'] = bcsub($total['accrued'], $total['settled'], 4);
                if (bccomp($total['outstanding'], '0', 4) < 0) {
                    throw new RuntimeException('Provider settlements exceed recorded payables.');
                }
            }
            unset($total);
            ksort($totals);

            return ['currencies' => array_values($totals)];
        }, 3);
    }
}
