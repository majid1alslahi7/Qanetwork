<?php

namespace App\Providers\Inventory;

use App\Models\InventoryCard;
use App\Models\NetworkConnection;
use App\Models\NetworkProduct;
use App\Models\ProviderTransaction;
use App\Providers\Contracts\ProviderAdapter;
use App\Providers\Data\ProviderProduct;
use App\Providers\Data\PurchaseCardRequest;
use App\Providers\Data\PurchaseCardResult;
use App\Providers\Data\TransactionStatusResult;
use App\Providers\Enums\ProviderTransactionStatus;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class StoredCardAdapter implements ProviderAdapter
{
    public function healthCheck(NetworkConnection $connection): bool
    {
        return $connection->driver === 'stored_cards' && $connection->is_enabled && DB::connection()->getPdo() !== null;
    }

    public function getBalance(NetworkConnection $connection): ?string
    {
        return null;
    }

    public function getProducts(NetworkConnection $connection): array
    {
        return NetworkProduct::query()->where('network_id', $connection->network_id)->where('status', 'active')
            ->where(fn (Builder $query) => $query->where('fulfillment_connection_id', $connection->id)->orWhereNull('fulfillment_connection_id'))->get()
            ->map(fn (NetworkProduct $product): ProviderProduct => new ProviderProduct($product->external_product_id, $product->display_name ?? $product->name, $product->face_value, $product->currency_code))->all();
    }

    public function checkAvailability(NetworkConnection $connection, string $externalProductId): bool
    {
        $product = NetworkProduct::query()->where('network_id', $connection->network_id)->where('external_product_id', $externalProductId)->where('status', 'active')->first();

        return $this->healthCheck($connection) && $product !== null && $this->availableCards($product)->exists();
    }

    public function purchaseCard(NetworkConnection $connection, PurchaseCardRequest $request): PurchaseCardResult
    {
        return DB::transaction(function () use ($connection, $request): PurchaseCardResult {
            $transaction = ProviderTransaction::query()->where('internal_transaction_id', $request->internalTransactionId)->lockForUpdate()->first();
            if ($transaction === null || $transaction->sale_id !== $request->saleId || $transaction->network_connection_id !== $connection->id || $transaction->idempotency_key !== $request->idempotencyKey) {
                return PurchaseCardResult::unknown(errorMessage: 'The inventory transaction identity could not be verified.');
            }
            $allocated = InventoryCard::query()->where('internal_transaction_id', $request->internalTransactionId)->first();
            if ($allocated !== null) {
                $status = $this->checkTransaction($connection, $request->internalTransactionId, $allocated->id);

                return new PurchaseCardResult($status->status, $status->providerTransactionId, $status->providerCardReference, $status->credentials, $status->providerStatus);
            }
            if (! in_array($transaction->status, ['created', 'processing'], true)) {
                return PurchaseCardResult::unknown(errorMessage: 'The inventory transaction requires reconciliation.');
            }
            $current = NetworkConnection::query()->findOrFail($connection->id);
            $sale = $transaction->sale()->firstOrFail();
            $reservation = $sale->reservation()->first();
            $financial = $sale->financial()->first();
            if (! in_array($sale->status, ['balance_reserved', 'processing_provider'], true) || $reservation?->status !== 'reserved' || $financial === null
                || bccomp($reservation->amount, $financial->seller_net_amount, 4) !== 0 || bccomp($financial->face_value, $request->expectedFaceValue, 4) !== 0) {
                return PurchaseCardResult::unknown(errorMessage: 'The inventory sale reservation could not be verified.');
            }
            $product = NetworkProduct::query()->lockForUpdate()->findOrFail($sale->network_product_id);
            if (! $this->healthCheck($current) || $product->network_id !== $current->network_id || $product->external_product_id !== $request->externalProductId || $product->status !== 'active'
                || $product->currency_code !== $request->currencyCode || bccomp($product->face_value, $request->expectedFaceValue, 4) !== 0) {
                return PurchaseCardResult::failed(errorCode: 'INVENTORY_UNAVAILABLE', errorMessage: 'The inventory product is unavailable or has changed.');
            }
            $card = $this->availableCards($product)->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')->orderBy('expires_at')->orderBy('id')->lockForUpdate()->first();
            if ($card === null) {
                return PurchaseCardResult::failed(errorCode: 'INVENTORY_EXHAUSTED', errorMessage: 'No available cards remain for this product.');
            }
            $credentials = $card->credentials_encrypted;
            if (! is_array($credentials) || ! is_string($credentials['username'] ?? null) || $credentials['username'] === ''
                || (! is_string($credentials['password'] ?? null) && ($credentials['login_mode'] ?? null) !== 'username_only')) {
                return PurchaseCardResult::unknown(errorMessage: 'Inventory card credentials require manual review.');
            }
            $card->status = 'allocated';
            $card->sale_id = $sale->id;
            $card->internal_transaction_id = $request->internalTransactionId;
            $card->allocated_at = now();
            $card->save();

            return PurchaseCardResult::confirmed($card->id, $credentials, $card->id, 'inventory_allocated');
        }, 3);
    }

    public function checkTransaction(NetworkConnection $connection, string $internalTransactionId, ?string $providerTransactionId = null): TransactionStatusResult
    {
        $transaction = ProviderTransaction::query()->where('internal_transaction_id', $internalTransactionId)->where('network_connection_id', $connection->id)->first();
        $card = InventoryCard::query()->where('internal_transaction_id', $internalTransactionId)->where('network_id', $connection->network_id)->first();
        $sale = $transaction?->sale()->first();
        if ($transaction === null || $card === null || $card->status !== 'allocated' || $card->sale_id !== $transaction->sale_id || ($providerTransactionId !== null && $card->id !== $providerTransactionId)) {
            return new TransactionStatusResult(ProviderTransactionStatus::RECONCILIATION_REQUIRED, errorCode: 'INVENTORY_REVIEW_REQUIRED');
        }
        if ($sale === null || $sale->network_id !== $card->network_id || $sale->network_product_id !== $card->network_product_id) {
            return new TransactionStatusResult(ProviderTransactionStatus::RECONCILIATION_REQUIRED, errorCode: 'INVENTORY_REVIEW_REQUIRED');
        }

        try {
            $credentials = $card->credentials_encrypted;
        } catch (DecryptException) {
            return new TransactionStatusResult(ProviderTransactionStatus::RECONCILIATION_REQUIRED, errorCode: 'INVENTORY_REVIEW_REQUIRED');
        }
        if (! is_array($credentials) || ! is_string($credentials['username'] ?? null) || $credentials['username'] === ''
            || (! is_string($credentials['password'] ?? null) && ($credentials['login_mode'] ?? null) !== 'username_only')) {
            return new TransactionStatusResult(ProviderTransactionStatus::RECONCILIATION_REQUIRED, errorCode: 'INVENTORY_REVIEW_REQUIRED');
        }

        return new TransactionStatusResult(ProviderTransactionStatus::CONFIRMED, $card->id, $card->id, $credentials, 'inventory_allocated');
    }

    /** @return Builder<InventoryCard> */
    private function availableCards(NetworkProduct $product): Builder
    {
        return InventoryCard::query()->where('network_id', $product->network_id)->where('network_product_id', $product->id)->where('status', 'available')
            ->whereNull('sale_id')->whereNull('internal_transaction_id')->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
