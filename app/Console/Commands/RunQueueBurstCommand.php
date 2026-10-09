<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationsMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

#[Signature('qanetwork:work {queue : One of sales, reconciliation, network-health or delivery} {--seconds=50 : Worker lifetime before finishing its current job (1-50 seconds)}')]
#[Description('Run one bounded queue worker for Cron with an overlap lock and a hard process deadline')]
class RunQueueBurstCommand extends Command
{
    public function handle(): int
    {
        $queue = (string) $this->argument('queue');
        $seconds = filter_var($this->option('seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
        if (! in_array($queue, OperationsMonitor::QUEUES, true) || $seconds === false) {
            $this->error('Select a supported queue and a lifetime between 1 and 50 seconds.');

            return self::FAILURE;
        }
        $connection = config('queue.default');
        $queueConfiguration = config('queue.connections.'.$connection, []);
        $cacheDriver = config('cache.stores.'.config('cache.default').'.driver');
        if (! in_array($queueConfiguration['driver'] ?? null, ['database', 'redis', 'beanstalkd'], true)
            || ! is_numeric($queueConfiguration['retry_after'] ?? null) || $queueConfiguration['retry_after'] <= $seconds + 80
            || ! in_array($cacheDriver, ['database', 'redis', 'file', 'memcached', 'dynamodb'], true)) {
            $this->error('Configure a durable queue, retry_after greater than seconds + 80, and persistent atomic-lock storage.');

            return self::FAILURE;
        }
        $lock = null;
        try {
            $lock = Cache::lock($this->lockKey($queue), $seconds + 100);
            if (! $lock->get()) {
                $this->info('A Cron worker already holds this queue lock; skipped.');

                return self::SUCCESS;
            }
            $result = Process::path(base_path())->timeout($seconds + 70)->quietly()->run([
                PHP_BINARY, base_path('artisan'), 'queue:work', $connection,
                '--queue='.$queue, '--stop-when-empty', '--max-time='.$seconds,
                '--max-jobs=100', '--timeout=60', '--sleep=1', '--tries=3',
                '--no-interaction', '--quiet',
            ]);
            if (! $result->successful()) {
                $this->error('The worker stopped unsuccessfully. Inspect restricted application logs and failed-job monitoring.');

                return self::FAILURE;
            }
            $this->info('Queue worker burst finished. Check operations monitoring for observed activity and job failures.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('The worker could not complete within its deadline or its process/lock storage is unavailable.');

            return self::FAILURE;
        } finally {
            if ($lock !== null) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    $this->warn('Queue lock cleanup failed; its bounded lease will expire.');
                }
            }
        }
    }

    private function lockKey(string $queue): string
    {
        return 'qanetwork:cron-worker:'.app()->environment().':'.config('queue.default').':'.$queue;
    }
}
