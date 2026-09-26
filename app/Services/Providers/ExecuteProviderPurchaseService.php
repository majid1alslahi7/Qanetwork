<?php

namespace App\Services\Providers;

use App\Models\ProviderTransaction;
use App\Providers\Data\PurchaseCardRequest;
use App\Providers\Data\PurchaseCardResult;
use App\Providers\Enums\ProviderTransactionStatus;
use App\Providers\Registry\ProviderAdapterRegistry;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ExecuteProviderPurchaseService
{
    public function __construct(
        private readonly ProviderAdapterRegistry $registry
    ) {
    }

    public function handle(
        ProviderTransaction $transaction
    ): PurchaseCardResult {
        /*
         * Phase 1:
         * Validate locally and mark the attempt as processing.
         *
         * We intentionally finish this DB transaction BEFORE
         * making the external provider call.
         */
        $prepared = DB::transaction(function () use ($transaction) {
            $locked = ProviderTransaction::query()
                ->lockForUpdate()
                ->findOrFail($transaction->id);

            if ($locked->status !== 'created') {
                throw new RuntimeException(
                    'Only a created provider transaction can be executed.'
                );
            }

            $sale = $locked->sale()->firstOrFail();

            if ($sale->status !== 'balance_reserved') {
                throw new RuntimeException(
                    'Sale must remain balance_reserved before provider execution.'
                );
            }

            $reservation = $sale->reservation()->first();

            if (
                ! $reservation ||
                $reservation->status !== 'reserved'
            ) {
                throw new RuntimeException(
                    'Sale must have an active reservation before provider execution.'
                );
            }

            $connection = $locked->connection()->firstOrFail();

            if (! $connection->is_enabled) {
                throw new RuntimeException(
                    'Provider connection is disabled.'
                );
            }

            if ($connection->network_id !== $sale->network_id) {
                throw new RuntimeException(
                    'Provider connection does not belong to the sale network.'
                );
            }

            $product = $sale->product()->firstOrFail();

            $locked->status = 'processing';
            $locked->attempt_count =
                ((int) $locked->attempt_count) + 1;
            $locked->request_started_at ??= now();
            $locked->save();

            $sale->status = 'processing_provider';
            $sale->save();

            return [
                'transaction_id' => $locked->id,
                'connection' => $connection,
                'request' => new PurchaseCardRequest(
                    internalTransactionId:
                        $locked->internal_transaction_id,
                    idempotencyKey:
                        $locked->idempotency_key,
                    externalProductId:
                        $product->external_product_id,
                    saleId:
                        $sale->id,
                    expectedFaceValue:
                        (string) $product->face_value,
                    currencyCode:
                        $sale->currency_code,
                ),
            ];
        }, 3);

        $adapter = $this->registry->forConnection(
            $prepared['connection']
        );

        /*
         * Phase 2:
         * External call happens OUTSIDE a DB transaction.
         */
        try {
            $result = $adapter->purchaseCard(
                $prepared['connection'],
                $prepared['request']
            );
        } catch (Throwable $e) {
            /*
             * Once the request may have left QaNetwork,
             * an exception is NOT proof that the provider
             * failed to issue a card.
             *
             * Treat it as UNKNOWN, never as FAILED.
             */
            $result = PurchaseCardResult::unknown(
                errorMessage: $this->safeExceptionMessage($e)
            );
        }

        /*
         * Defensive validation:
         * credentials are accepted only for CONFIRMED.
         */
        if (
            $result->status !==
                ProviderTransactionStatus::CONFIRMED &&
            $result->credentials !== null
        ) {
            throw new RuntimeException(
                'Provider returned credentials for a non-confirmed transaction.'
            );
        }

        if (
            $result->status ===
                ProviderTransactionStatus::CONFIRMED &&
            empty($result->credentials)
        ) {
            /*
             * Provider says confirmed but gave us no card.
             * We cannot safely complete or release the sale.
             */
            $result = PurchaseCardResult::unknown(
                providerTransactionId:
                    $result->providerTransactionId,
                providerStatus:
                    $result->providerStatus,
                errorMessage:
                    'Provider confirmed transaction without card credentials.'
            );
        }

        /*
         * Phase 3:
         * Persist the provider result.
         */
        return DB::transaction(function () use (
            $prepared,
            $result
        ) {
            $locked = ProviderTransaction::query()
                ->lockForUpdate()
                ->findOrFail($prepared['transaction_id']);

            if ($locked->status !== 'processing') {
                throw new RuntimeException(
                    'Provider transaction is no longer processing.'
                );
            }

            $sale = $locked->sale()
                ->lockForUpdate()
                ->firstOrFail();

            $locked->provider_transaction_id =
                $result->providerTransactionId;

            $locked->provider_status =
                $result->providerStatus;

            $locked->error_code =
                $result->errorCode;

            $locked->error_message =
                $result->errorMessage;

            switch ($result->status) {
                case ProviderTransactionStatus::CONFIRMED:
                    $locked->status = 'confirmed';
                    $locked->confirmed_at = now();

                    $sale->status = 'provider_confirmed';
                    $sale->provider_confirmed_at = now();
                    break;

                case ProviderTransactionStatus::FAILED:
                    $locked->status = 'failed';
                    $locked->failed_at = now();

                    /*
                     * Release is intentionally NOT done here.
                     * A later workflow service performs the
                     * financial transition explicitly.
                     */
                    $sale->status = 'failed';
                    $sale->failed_at = now();
                    $sale->failure_code =
                        $result->errorCode;
                    $sale->failure_message =
                        $result->errorMessage;
                    break;

                case ProviderTransactionStatus::TIMEOUT:
                    $locked->status = 'timeout';

                    $sale->status = 'timeout';
                    break;

                case ProviderTransactionStatus::UNKNOWN:
                    $locked->status = 'unknown';

                    $sale->status =
                        'unknown_provider_state';
                    break;

                case ProviderTransactionStatus::RECONCILIATION_REQUIRED:
                    $locked->status =
                        'reconciliation_required';

                    $sale->status =
                        'reconciliation_required';
                    break;

                case ProviderTransactionStatus::PENDING:
                    /*
                     * A purchase call must not leave QaNetwork
                     * in a reusable "processing" state.
                     */
                    $locked->status =
                        'reconciliation_required';

                    $sale->status =
                        'reconciliation_required';
                    break;
            }

            $locked->save();
            $sale->save();

            return $result;
        }, 3);
    }

    private function safeExceptionMessage(
        Throwable $e
    ): string {
        /*
         * Do not include request bodies, credentials,
         * tokens or provider raw responses here.
         */
        return mb_substr(
            $e->getMessage() ?: 'Provider call failed.',
            0,
            500
        );
    }
}
