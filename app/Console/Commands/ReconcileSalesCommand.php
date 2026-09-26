<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileSaleJob;
use App\Models\ProviderTransaction;
use Illuminate\Console\Command;

class ReconcileSalesCommand extends Command
{
    protected $signature = 'sales:reconcile
        {--limit=100 : Maximum number of sales to enqueue}';

    protected $description =
        'Queue provider reconciliation for eligible uncertain sales';

    public function handle(): int
    {
        $limit = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 1000,
                ],
            ]
        );

        if ($limit === false) {
            $this->error(
                'The --limit option must be an integer between 1 and 1000.'
            );

            return self::FAILURE;
        }

        $dispatched = 0;

        ProviderTransaction::query()
            ->whereIn('status', [
                'processing',
                'timeout',
                'unknown',
                'reconciliation_required',
            ])
            ->whereNull('manual_review_required_at')
            ->where(
                'reconciliation_attempt_count',
                '<',
                5
            )
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'sale_id'])
            ->each(function (
                ProviderTransaction $transaction
            ) use (&$dispatched): void {
                ReconcileSaleJob::dispatch(
                    $transaction->sale_id
                );

                $dispatched++;
            });

        $this->info(
            "Queued {$dispatched} sale(s) for reconciliation."
        );

        return self::SUCCESS;
    }
}
