<?php

namespace App\Jobs;

use App\Models\NetworkConnection;
use App\Services\Networks\CheckNetworkConnectionHealthService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class CheckNetworkConnectionHealthJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public array $backoff = [30, 60];

    public function __construct(public readonly string $connectionId)
    {
        $this->onQueue('network-health');
    }

    public function uniqueId(): string
    {
        return 'health:'.$this->connectionId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(CheckNetworkConnectionHealthService $service): void
    {
        $connection = NetworkConnection::query()->find($this->connectionId);
        if ($connection !== null && $connection->is_enabled) {
            $service->handle($connection);
        }
    }
}
