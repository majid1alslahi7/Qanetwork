<?php

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OperationsMonitor
{
    public const QUEUES = ['sales', 'reconciliation', 'network-health', 'delivery'];

    /** @var array<string, int> */
    private array $lastWritten = [];

    public function recordScheduler(): void
    {
        $this->record('scheduler');
    }

    public function recordWorker(string $connection, string $queues): void
    {
        if ($connection !== config('queue.default') || ! $this->hasWorkerQueue()) {
            return;
        }
        foreach (array_intersect(self::QUEUES, array_map('trim', explode(',', $queues))) as $queue) {
            $this->record('worker:'.$queue);
        }
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        try {
            $failures = ['status' => 'available', 'count' => $this->failedJobs()->count()];
        } catch (Throwable) {
            $failures = ['status' => 'unavailable', 'count' => null];
        }

        return [
            'checked_at' => now()->toIso8601String(),
            'scheduler' => $this->signal('scheduler'),
            'workers' => array_map(fn (string $queue): array => [
                'queue' => $queue, ...$this->signal('worker:'.$queue, $this->hasWorkerQueue()),
            ], self::QUEUES),
            'failed_jobs' => $failures,
        ];
    }

    public function failedJobs(): Builder
    {
        if (config('queue.failed.driver') !== 'database-uuids') {
            throw new RuntimeException('Failed-job monitoring is unavailable for this storage driver.');
        }

        return DB::connection(config('queue.failed.database'))->table(config('queue.failed.table'));
    }

    private function hasWorkerQueue(): bool
    {
        return in_array(config('queue.connections.'.config('queue.default').'.driver'), ['database', 'redis', 'beanstalkd', 'sqs'], true);
    }

    private function hasSharedCache(): bool
    {
        return in_array(config('cache.stores.'.config('cache.default').'.driver'), ['database', 'redis', 'file', 'memcached', 'dynamodb'], true);
    }

    private function key(string $component): string
    {
        return 'qanetwork:operations:v1:'.app()->environment().':'.config('queue.default').':'.$component;
    }

    private function record(string $component): void
    {
        if (! $this->hasSharedCache()) {
            return;
        }
        $timestamp = now()->getTimestamp();
        $lastWritten = $this->lastWritten[$component] ?? 0;
        if ($timestamp >= $lastWritten && $timestamp - $lastWritten < 30) {
            return;
        }
        $this->lastWritten[$component] = $timestamp;
        try {
            Cache::put($this->key($component), $timestamp, 86400);
        } catch (Throwable) {
            // Monitoring storage must never interrupt a financial job or scheduled task.
            try {
                Log::warning('Operations heartbeat could not be stored.', ['component' => $component]);
            } catch (Throwable) {
            }
        }
    }

    /** @return array{status: string, last_seen_at: ?string, age_seconds: ?int} */
    private function signal(string $component, bool $enabled = true): array
    {
        $empty = ['last_seen_at' => null, 'age_seconds' => null];
        if (! $enabled || ! $this->hasSharedCache()) {
            return ['status' => 'unavailable', ...$empty];
        }
        try {
            $timestamp = Cache::get($this->key($component));
        } catch (Throwable) {
            return ['status' => 'unavailable', ...$empty];
        }
        $age = is_int($timestamp) ? now()->getTimestamp() - $timestamp : -1;
        if ($age < 0) {
            return ['status' => 'unknown', ...$empty];
        }

        return ['status' => $age <= 180 ? 'recent' : 'stale',
            'last_seen_at' => CarbonImmutable::createFromTimestampUTC($timestamp)->toIso8601String(),
            'age_seconds' => $age];
    }
}
