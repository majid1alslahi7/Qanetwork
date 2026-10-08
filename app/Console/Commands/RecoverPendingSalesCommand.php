<?php

namespace App\Console\Commands;

use App\Jobs\ProcessSaleJob;
use App\Jobs\ReconcileSaleJob;
use App\Models\ProviderTransaction;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RecoverPendingSalesCommand extends Command
{
    protected $signature = 'sales:recover {--limit=100 : Maximum number of interrupted sales to enqueue}';

    protected $description = 'Recover unstarted purchases and interrupted local sale finalization';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('The --limit option must be an integer between 1 and 1000.');

            return self::FAILURE;
        }
        $count = 0;
        ProviderTransaction::query()->whereNull('manual_review_required_at')->where('updated_at', '<=', now()->subMinutes(2))
            ->where(function (Builder $query): void {
                $query->where(function (Builder $pending): void {
                    $pending->where('status', 'created')->whereNull('request_started_at')->where('attempt_count', 0)
                        ->whereHas('sale', fn (Builder $sale) => $sale->where('status', 'balance_reserved')->whereHas('financial')
                            ->whereHas('reservation', fn (Builder $reservation) => $reservation->where('status', 'reserved')));
                })->orWhere(function (Builder $confirmed): void {
                    $confirmed->where('status', 'confirmed')->whereHas('sale', fn (Builder $sale) => $sale
                        ->whereIn('status', ['provider_confirmed', 'accounting_posted'])->whereHas('soldCard'));
                })->orWhere(function (Builder $failed): void {
                    $failed->where('status', 'failed')->whereHas('sale', fn (Builder $sale) => $sale->where('status', 'failed')
                        ->whereHas('reservation', fn (Builder $reservation) => $reservation->where('status', 'reserved')));
                });
            })->orderBy('id')->limit($limit)->get(['id', 'sale_id', 'status'])->each(function (ProviderTransaction $transaction) use (&$count): void {
                if ($transaction->status === 'created') {
                    ProcessSaleJob::dispatch($transaction->sale_id);
                } else {
                    ReconcileSaleJob::dispatch($transaction->sale_id);
                }
                $count++;
            });
        $this->info('Queued '.$count.' interrupted sale(s) for recovery.');

        return self::SUCCESS;
    }
}
