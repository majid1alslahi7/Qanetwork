<?php

namespace App\Services\Providers;

use App\Models\ProviderTransaction;
use App\Models\SoldCard;
use App\Providers\Data\TransactionStatusResult;
use App\Providers\Enums\ProviderTransactionStatus;
use App\Providers\Registry\ProviderAdapterRegistry;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ReconcileProviderTransactionService
{
    public function __construct(
        private readonly ProviderAdapterRegistry $registry
    ) {
    }

    public function handle(
        ProviderTransaction $transaction
    ): TransactionStatusResult {
        /*
         * Phase 1:
         * Read a fresh transaction and validate that it is
         * eligible for reconciliation.
         *
         * Never hold a DB transaction while calling the provider.
         */
        $prepared = DB::transaction(function () use ($transaction) {
            $locked = ProviderTransaction::query()
                ->lockForUpdate()
                ->findOrFail($transaction->id);

            /*
             * A confirmed/failed transaction already has a
             * definitive provider result. Do not contact the
             * provider again through this service.
             */
            if (in_array(
                $locked->status,
                ['confirmed', 'failed'],
                true
            )) {
                throw new RuntimeException(
                    'Final provider transaction cannot be reconciled.'
                );
            }

            /*
             * "created" means purchaseCard() has not yet been
             * attempted, so there is nothing to reconcile.
             */
            if ($locked->status === 'created') {
                throw new RuntimeException(
                    'Provider transaction has not been executed yet.'
                );
            }

            if (! in_array(
                $locked->status,
                [
                    'processing',
                    'timeout',
                    'unknown',
                    'reconciliation_required',
                ],
                true
            )) {
                throw new RuntimeException(
                    'Provider transaction is not eligible for reconciliation.'
                );
            }

            $sale = $locked->sale()->firstOrFail();

            $reservation = $sale->reservation()->first();

            if (
                ! $reservation ||
                $reservation->status !== 'reserved'
            ) {
                throw new RuntimeException(
                    'Reconciliation requires an active seller reservation.'
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

            return [
                'transaction_id' => $locked->id,
                'connection' => $connection,
                'internal_transaction_id' =>
                    $locked->internal_transaction_id,
                'provider_transaction_id' =>
                    $locked->provider_transaction_id,
            ];
        }, 3);

        $adapter = $this->registry->forConnection(
            $prepared['connection']
        );

        /*
         * Critical rule:
         *
         * Reconciliation NEVER calls purchaseCard().
         * It only asks the provider about the already existing
         * transaction.
         */
        try {
            $result = $adapter->checkTransaction(
                $prepared['connection'],
                $prepared['internal_transaction_id'],
                $prepared['provider_transaction_id']
            );
        } catch (Throwable $e) {
            /*
             * Failure to check status does not prove that the
             * original purchase failed.
             */
            $result = new TransactionStatusResult(
                status:
                    ProviderTransactionStatus::RECONCILIATION_REQUIRED,
                providerTransactionId:
                    $prepared['provider_transaction_id'],
                errorCode: 'RECONCILIATION_CHECK_FAILED',
                errorMessage: 'Provider adapter call failed; reconciliation required.'
            );
        }

        /*
         * Credentials are valid only after a positive provider
         * confirmation.
         */
        if (
            $result->status !==
                ProviderTransactionStatus::CONFIRMED &&
            $result->credentials !== null
        ) {
            throw new RuntimeException(
                'Provider returned credentials for a non-confirmed reconciliation result.'
            );
        }

        /*
         * A provider may report "confirmed" while its API failed
         * to return the actual card credentials. We cannot debit
         * or release the seller in that state.
         */
        if (
            $result->status ===
                ProviderTransactionStatus::CONFIRMED &&
            empty($result->credentials)
        ) {
            $result = new TransactionStatusResult(
                status:
                    ProviderTransactionStatus::RECONCILIATION_REQUIRED,
                providerTransactionId:
                    $result->providerTransactionId
                        ?? $prepared['provider_transaction_id'],
                providerCardReference:
                    $result->providerCardReference,
                providerStatus:
                    $result->providerStatus,
                errorCode:
                    'CONFIRMED_WITHOUT_CREDENTIALS',
                errorMessage:
                    'Provider confirmed transaction without card credentials.'
            );
        }

        /*
         * Phase 3:
         * Persist only the newly learned provider state.
         *
         * Financial capture/release will be orchestrated in the
         * next layer after this state transition is proven.
         */
        return DB::transaction(function () use (
            $prepared,
            $result
        ) {
            $locked = ProviderTransaction::query()
                ->lockForUpdate()
                ->findOrFail($prepared['transaction_id']);

            /*
             * Another worker may have finalized the transaction
             * while this worker was waiting for the provider.
             * Never overwrite a final state with stale data.
             */
            if (in_array(
                $locked->status,
                ['confirmed', 'failed'],
                true
            )) {
                throw new RuntimeException(
                    'Provider transaction became final during reconciliation.'
                );
            }

            $sale = $locked->sale()
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Preserve an already-known provider transaction ID.
             * A provider must not suddenly identify the same
             * operation as a different transaction.
             */
            if (
                $locked->provider_transaction_id !== null &&
                $result->providerTransactionId !== null &&
                $locked->provider_transaction_id !==
                    $result->providerTransactionId
            ) {
                throw new RuntimeException(
                    'Provider transaction identifier changed during reconciliation.'
                );
            }

            if (
                $locked->provider_transaction_id === null &&
                $result->providerTransactionId !== null
            ) {
                $locked->provider_transaction_id =
                    $result->providerTransactionId;
            }

            $locked->provider_status =
                $result->providerStatus;

            $locked->error_code =
                $result->errorCode;

            $locked->error_message =
                $result->errorMessage;

            switch ($result->status) {
                case ProviderTransactionStatus::CONFIRMED:
                    /*
                     * Provider confirmation and encrypted card
                     * persistence must commit together.
                     *
                     * Accounting happens later. Therefore, if
                     * seller capture fails, the issued card is
                     * still safely recoverable from our DB.
                     */
                    $soldCard = SoldCard::query()
                        ->where('sale_id', $sale->id)
                        ->first();

                    if (! $soldCard) {
                        $soldCard = new SoldCard([
                            'sale_id' => $sale->id,
                            'provider_card_reference' =>
                                $result->providerCardReference,
                            'sold_at' => now(),
                        ]);

                        $soldCard->setCredentials(
                            $result->credentials
                        );

                        $soldCard->save();
                    } else {
                        if (
                            $soldCard->provider_card_reference !== null &&
                            $result->providerCardReference !== null &&
                            $soldCard->provider_card_reference !==
                                $result->providerCardReference
                        ) {
                            throw new RuntimeException(
                                'Provider card reference changed during reconciliation.'
                            );
                        }
                    }

                    $locked->status = 'confirmed';
                    $locked->confirmed_at ??= now();

                    $sale->status = 'provider_confirmed';
                    $sale->provider_confirmed_at ??= now();
                    break;

                case ProviderTransactionStatus::FAILED:
                    $locked->status = 'failed';
                    $locked->failed_at ??= now();

                    $sale->status = 'failed';
                    $sale->failed_at ??= now();
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

                case ProviderTransactionStatus::PENDING:
                case ProviderTransactionStatus::RECONCILIATION_REQUIRED:
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

}
