<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\SaleAccountingEntry;
use App\Models\SellerLedgerEntry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PostSaleAccountingService
{
    public function handle(Sale $sale): void
    {
        DB::transaction(function () use ($sale): void {
            $current = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            $financial = $current->financial()->firstOrFail();
            $reservation = $current->reservation()->firstOrFail();
            $provider = $current->providerTransaction()->firstOrFail();
            $debit = SellerLedgerEntry::query()->where('idempotency_key', 'sale:'.$current->id.':seller-debit')->firstOrFail();
            if (! in_array($current->status, ['accounting_posted', 'completed'], true) || $provider->status !== 'confirmed'
                || $reservation->status !== 'captured' || ! $current->soldCard()->exists()
                || $financial->network_owner_id === null || $financial->currency_code !== $current->currency_code
                || $debit->seller_id !== $current->seller_id || $debit->seller_wallet_id !== $current->seller_wallet_id
                || $debit->reference_type !== 'sale' || $debit->reference_id !== $current->id
                || $debit->direction !== 'debit' || $debit->currency_code !== $current->currency_code
                || bccomp($debit->amount, $financial->seller_net_amount, 4) !== 0 || $debit->reversals()->exists()
                || bccomp($reservation->amount, $financial->seller_net_amount, 4) !== 0
                || bccomp(bcadd($financial->provider_amount, $financial->platform_commission, 4), $financial->seller_net_amount, 4) !== 0) {
                throw new RuntimeException('Sale accounting prerequisites are inconsistent.');
            }
            foreach (['provider_payable' => $financial->provider_amount, 'platform_revenue' => $financial->platform_commission] as $type => $amount) {
                if (bccomp($amount, '0', 4) < 0) {
                    throw new RuntimeException('Sale accounting amounts cannot be negative.');
                }
                $ownerId = $type === 'provider_payable' ? $financial->network_owner_id : null;
                $key = 'sale:'.$current->id.':'.$type;
                $existing = SaleAccountingEntry::query()->where('sale_id', $current->id)->where('entry_type', $type)->first();
                if ($existing !== null) {
                    if ($existing->network_owner_id !== $ownerId || $existing->currency_code !== $current->currency_code
                        || $existing->idempotency_key !== $key || bccomp($existing->amount, $amount, 4) !== 0) {
                        throw new RuntimeException('Existing sale accounting entry differs from its snapshot.');
                    }

                    continue;
                }
                SaleAccountingEntry::query()->create(['sale_id' => $current->id, 'network_owner_id' => $ownerId,
                    'entry_type' => $type, 'amount' => $amount, 'currency_code' => $current->currency_code,
                    'idempotency_key' => $key, 'posted_at' => $current->accounting_posted_at ?? now()]);
            }
        }, 3);
    }
}
