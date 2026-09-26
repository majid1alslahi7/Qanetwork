<?php

namespace App\Jobs;

use App\Models\ProviderTransaction;
use App\Models\Sale;
use App\Services\Sales\ReconcileSaleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReconcileSaleJob implements ShouldQueue
{
    use Queueable;

    /*
     * Queue-level attempts protect against infrastructure/job
     * failures. They are deliberately separate from the
     * provider reconciliation attempt counter.
     */
    public int $tries = 10;

    public int $timeout = 60;

    private const MAX_RECONCILIATION_ATTEMPTS = 5;

    private const BACKOFF_SECONDS = [
        60,
        300,
        900,
        1800,
    ];

    public function __construct(
        public readonly string $saleId
    ) {
        $this->onQueue('reconciliation');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                'reconcile-sale:'.$this->saleId
            ))
                ->releaseAfter(60)
                ->expireAfter(120),
        ];
    }

    public function handle(
        ReconcileSaleService $reconcileSaleService
    ): void {
        $state = DB::transaction(function () {
            $sale = Sale::query()
                ->lockForUpdate()
                ->findOrFail($this->saleId);

            $transaction = ProviderTransaction::query()
                ->where('sale_id', $sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Terminal local states need no provider call.
             */
            if (
                $sale->status === 'completed' ||
                (
                    $sale->status === 'failed' &&
                    $transaction->status === 'failed'
                )
            ) {
                return [
                    'action' => 'stop',
                    'attempt' => null,
                ];
            }

            /*
             * Once escalated, automated reconciliation stops.
             */
            if ($transaction->manual_review_required_at !== null) {
                return [
                    'action' => 'stop',
                    'attempt' => null,
                ];
            }

            /*
             * CREATED has not started a provider purchase.
             * ProcessSaleService owns that state.
             */
            if ($transaction->status === 'created') {
                return [
                    'action' => 'stop',
                    'attempt' => null,
                ];
            }

            /*
             * Confirmed/failed provider states may still require
             * local finalization, so they are intentionally
             * allowed through without counting a new provider
             * status check.
             */
            if (
                $transaction->status === 'confirmed' ||
                $transaction->status === 'failed'
            ) {
                return [
                    'action' => 'finalize',
                    'attempt' => null,
                ];
            }

            if (! in_array(
                $transaction->status,
                [
                    'processing',
                    'timeout',
                    'unknown',
                    'reconciliation_required',
                ],
                true
            )) {
                throw new RuntimeException(
                    'Provider transaction is not eligible for queued reconciliation.'
                );
            }

            if (
                $transaction->reconciliation_attempt_count >=
                self::MAX_RECONCILIATION_ATTEMPTS
            ) {
                $transaction->manual_review_required_at ??= now();
                $transaction->save();

                return [
                    'action' => 'stop',
                    'attempt' => null,
                ];
            }

            $transaction->reconciliation_attempt_count++;
            $transaction->last_reconciliation_at = now();
            $transaction->save();

            return [
                'action' => 'reconcile',
                'attempt' =>
                    $transaction->reconciliation_attempt_count,
            ];
        });

        if ($state['action'] === 'stop') {
            return;
        }

        /*
         * No external provider call is made while the database
         * transaction above is open.
         */
        $result = $reconcileSaleService->handle(
            Sale::query()->findOrFail($this->saleId)
        );

        if (! $result->requiresReconciliation) {
            return;
        }

        /*
         * The provider state is still uncertain.
         * Never release the seller reservation here.
         */
        $attempt = $state['attempt'];

        if ($attempt === null) {
            throw new RuntimeException(
                'Reconciliation attempt number is missing.'
            );
        }

        if ($attempt >= self::MAX_RECONCILIATION_ATTEMPTS) {
            DB::transaction(function () {
                $transaction = ProviderTransaction::query()
                    ->where('sale_id', $this->saleId)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Re-check current state because another worker
                 * may have finalized the sale meanwhile.
                 */
                if (in_array(
                    $transaction->status,
                    [
                        'processing',
                        'timeout',
                        'unknown',
                        'reconciliation_required',
                    ],
                    true
                )) {
                    $transaction->manual_review_required_at ??= now();
                    $transaction->save();
                }
            });

            return;
        }

        $this->release(
            self::BACKOFF_SECONDS[$attempt - 1]
        );
    }
}
