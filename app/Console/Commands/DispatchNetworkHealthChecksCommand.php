<?php

namespace App\Console\Commands;

use App\Jobs\CheckNetworkConnectionHealthJob;
use App\Models\NetworkConnection;
use Illuminate\Console\Command;

class DispatchNetworkHealthChecksCommand extends Command
{
    protected $signature = 'networks:check-health';

    protected $description = 'Queue health checks for enabled primary network connections';

    public function handle(): int
    {
        $count = 0;
        NetworkConnection::query()->where('is_enabled', true)->where('is_primary', true)
            ->where(function ($query): void {
                $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subMinute());
            })->select('id')->chunkById(100, function ($connections) use (&$count): void {
                foreach ($connections as $connection) {
                    CheckNetworkConnectionHealthJob::dispatch($connection->id);
                    $count++;
                }
            });
        $this->info('Queued '.$count.' connection health checks.');

        return self::SUCCESS;
    }
}
