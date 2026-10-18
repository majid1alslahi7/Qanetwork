<?php

namespace App\Services\Accounting;

use App\Enums\UserRole;
use App\Models\SellerLedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AccountStatementService
{
    /** @return array<string, mixed> */
    public function handle(User $account, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $asOf, int $page): array
    {
        return DB::transaction(function () use ($account, $from, $to, $asOf, $page): array {
            $totals = [];
            $query = $this->entries($account, $asOf);
            if ($account->role === UserRole::SELLER) {
                foreach ($account->seller()->firstOrFail()->wallets()->sharedLock()->get() as $wallet) {
                    $previous = SellerLedgerEntry::query()->where('seller_wallet_id', $wallet->id)->where('created_at', '<=', $asOf)
                        ->where('posted_at', '<', $from)->orderByDesc('posted_at')->orderByDesc('id')->first();
                    $first = $previous === null ? SellerLedgerEntry::query()->where('seller_wallet_id', $wallet->id)->orderBy('posted_at')->orderBy('id')->first() : null;
                    $opening = $previous?->balance_after ?? ($first === null ? $wallet->balance :
                        ($first->direction === 'credit' ? bcsub($first->balance_after, $first->amount, 4) : bcadd($first->balance_after, $first->amount, 4)));
                    $totals[$wallet->currency_code] = $this->total($wallet->currency_code, $opening);
                }
                $query->where('posted_at', '>=', $from);
            }
            $rows = [];
            $count = 0;
            foreach ($query->where('posted_at', '<', $to->addDay())->orderBy('posted_at')->orderBy('id')->cursor() as $entry) {
                $currency = $entry->currency_code;
                $totals[$currency] ??= $this->total($currency, '0.0000');
                $amount = $this->decimal($entry->amount);
                $credit = $entry->direction === 'credit';
                if (CarbonImmutable::parse($entry->posted_at, 'UTC')->lt($from)) {
                    $totals[$currency]['opening_balance'] = $credit ? bcadd($totals[$currency]['opening_balance'], $amount, 4) : bcsub($totals[$currency]['opening_balance'], $amount, 4);
                    $totals[$currency]['closing_balance'] = $totals[$currency]['opening_balance'];

                    continue;
                }
                $totals[$currency][$credit ? 'credits' : 'debits'] = bcadd($totals[$currency][$credit ? 'credits' : 'debits'], $amount, 4);
                $totals[$currency]['closing_balance'] = $credit ? bcadd($totals[$currency]['closing_balance'], $amount, 4) : bcsub($totals[$currency]['closing_balance'], $amount, 4);
                $totals[$currency]['entry_count']++;
                $count++;
                if ($count > ($page - 1) * 100 && $count <= $page * 100) {
                    $rows[] = ['id' => $entry->id, 'entry_type' => $entry->entry_type, 'direction' => $entry->direction,
                        'amount' => $amount, 'currency_code' => $currency, 'balance_after' => $totals[$currency]['closing_balance'],
                        'reference_type' => $entry->reference_type, 'reference_id' => $entry->reference_id,
                        'description' => $entry->description, 'posted_at' => CarbonImmutable::parse($entry->posted_at, 'UTC')->toIso8601String()];
                }
            }
            ksort($totals);

            return ['data' => $rows, 'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($count / 100)), 'per_page' => 100, 'total' => $count],
                'summary' => ['account' => ['id' => $account->id, 'name' => $account->name, 'role' => $account->role->value],
                    'basis' => match ($account->role) {
                        UserRole::SELLER => 'wallet_ledger', UserRole::NETWORK_OWNER => 'provider_payables', default => 'platform_revenue'
                    },
                    'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => 'UTC'],
                    'as_of' => $asOf->toIso8601String(), 'currencies' => array_values($totals)]];
        }, 3);
    }

    private function entries(User $account, CarbonImmutable $asOf): Builder
    {
        if ($account->role === UserRole::SELLER) {
            return DB::table('seller_ledger_entries')->where('seller_id', $account->seller()->firstOrFail()->id)->where('created_at', '<=', $asOf)
                ->select(['id', 'entry_type', 'direction', 'amount', 'currency_code', 'reference_type', 'reference_id', 'description', 'posted_at']);
        }
        $entries = DB::table('sale_accounting_entries')->where('created_at', '<=', $asOf)
            ->where('entry_type', $account->role === UserRole::ADMIN ? 'platform_revenue' : 'provider_payable')
            ->select(['id', 'entry_type', 'amount', 'currency_code', 'posted_at'])->selectRaw("'credit' as direction, 'sale' as reference_type, sale_id as reference_id, null as description");
        if ($account->role === UserRole::NETWORK_OWNER) {
            $owner = $account->networkOwner()->firstOrFail();
            $entries->where('network_owner_id', $owner->id);
            $payments = DB::table('provider_settlements')->where('network_owner_id', $owner->id)->where('created_at', '<=', $asOf)
                ->select(['id'])->selectRaw("'settlement' as entry_type, amount, currency_code, paid_at as posted_at, 'debit' as direction, 'provider_settlement' as reference_type, id as reference_id, null as description");
            $entries->unionAll($payments);
        }

        return DB::query()->fromSub($entries, 'statement_entries');
    }

    /** @return array<string, string|int> */
    private function total(string $currency, string $opening): array
    {
        return ['currency_code' => $currency, 'opening_balance' => $opening, 'credits' => '0.0000', 'debits' => '0.0000', 'closing_balance' => $opening, 'entry_count' => 0];
    }

    private function decimal(string|int|float $amount): string
    {
        return bcadd(is_float($amount) ? sprintf('%.4F', $amount) : (string) $amount, '0', 4);
    }
}
