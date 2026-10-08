<?php

namespace App\Jobs;

use App\Services\Delivery\SendCardDeliveryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SendCardDeliveryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct(public readonly string $deliveryId)
    {
        $this->onQueue('delivery');
    }

    public function uniqueId(): string
    {
        return 'card-delivery:'.$this->deliveryId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('card-delivery:'.$this->deliveryId))->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(SendCardDeliveryService $service): void
    {
        $delivery = $service->handle($this->deliveryId);
        if ($delivery->status === 'retryable') {
            $this->release(max(1, (int) ceil(now()->diffInSeconds($delivery->next_retry_at, false))));
        }
    }
}
