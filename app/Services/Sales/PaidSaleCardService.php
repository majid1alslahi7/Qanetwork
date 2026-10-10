<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\SellerLedgerEntry;
use App\Models\SoldCard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

class PaidSaleCardService
{
    /** Call while holding the sale row lock in a transaction. */
    public function card(Sale $sale): SoldCard
    {
        $transaction = $sale->providerTransaction()->first();
        $reservation = $sale->reservation()->first();
        $financial = $sale->financial()->first();
        $card = $sale->soldCard()->lockForUpdate()->first();
        $entry = SellerLedgerEntry::query()->where('idempotency_key', 'sale:'.$sale->id.':seller-debit')->first();
        if ($sale->status !== 'completed' || $transaction?->status !== 'confirmed' || $reservation?->status !== 'captured'
            || $financial === null || $card === null || $entry === null
            || $entry->seller_id !== $sale->seller_id || $entry->seller_wallet_id !== $sale->seller_wallet_id
            || $entry->reference_type !== 'sale' || $entry->reference_id !== $sale->id
            || $entry->direction !== 'debit' || $entry->currency_code !== $sale->currency_code
            || bccomp($entry->amount, $financial->seller_net_amount, 4) !== 0
            || bccomp($reservation->amount, $financial->seller_net_amount, 4) !== 0 || $entry->reversals()->exists()) {
            throw new ConflictHttpException('Card credentials are available only after a confirmed and paid sale.');
        }

        return $card;
    }

    /** @return array<string, string> */
    public function credentials(SoldCard $card): array
    {
        try {
            $stored = $card->credentials();
        } catch (Throwable) {
            throw new ServiceUnavailableHttpException(null, 'Card credentials are temporarily unavailable.');
        }
        $credentials = [];
        foreach (['username', 'password', 'pin', 'serial'] as $key) {
            if (isset($stored[$key]) && is_string($stored[$key]) && $stored[$key] !== '' && strlen($stored[$key]) <= 4096) {
                $credentials[$key] = $stored[$key];
            }
        }
        $usernameOnly = ($stored['login_mode'] ?? null) === 'username_only' && isset($credentials['username']) && ! isset($credentials['password']);
        if (! isset($credentials['pin']) && ! isset($credentials['username'], $credentials['password']) && ! $usernameOnly) {
            throw new ServiceUnavailableHttpException(null, 'Card credentials are temporarily unavailable.');
        }
        if ($usernameOnly) {
            $credentials['login_mode'] = 'username_only';
        }

        return $credentials;
    }
}
