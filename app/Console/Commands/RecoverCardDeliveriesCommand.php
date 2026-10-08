<?php

namespace App\Console\Commands;

use App\Jobs\SendCardDeliveryJob;
use App\Models\CardDelivery;
use App\Services\Delivery\Contracts\SmsSender;
use Illuminate\Console\Command;

class RecoverCardDeliveriesCommand extends Command
{
    protected $signature = 'delivery:recover {--limit=100 : Maximum number of deliveries to recover}';

    protected $description = 'Recover queued SMS deliveries without repeating uncertain submissions';

    public function handle(SmsSender $sender): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('The --limit option must be an integer between 1 and 1000.');

            return self::FAILURE;
        }
        $available = $sender->available();
        $deliveries = CardDelivery::query()->where(function ($query) use ($available): void {
            $query->where(function ($query): void {
                $query->where('status', 'sending')->where('updated_at', '<=', now()->subMinutes(2));
            });
            if ($available) {
                $query->orWhere(function ($query): void {
                    $query->whereIn('status', ['queued', 'awaiting_configuration'])->where('updated_at', '<=', now()->subMinutes(2));
                })->orWhere(function ($query): void {
                    $query->where('status', 'retryable')->where('next_retry_at', '<=', now());
                });
            }
        })->orderBy('id')->limit($limit)->get(['id']);
        foreach ($deliveries as $delivery) {
            SendCardDeliveryJob::dispatch($delivery->id);
        }
        $this->info('Queued '.$deliveries->count().' delivery recovery job(s).');

        return self::SUCCESS;
    }
}
