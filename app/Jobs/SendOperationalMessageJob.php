<?php

namespace App\Jobs;

use App\Services\Delivery\SendOperationalMessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendOperationalMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $messageId)
    {
        $this->onQueue('delivery');
    }

    /**
     * Execute the job.
     */
    public function handle(SendOperationalMessageService $service): void
    {
        $service->handle($this->messageId);
    }
}
