<?php

namespace App\Console\Commands;

use App\Services\Delivery\Contracts\SmsSender;
use App\Services\Operations\OperationsMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

#[Signature('qanetwork:preflight {--json : Print a machine-readable report without configuration values}')]
#[Description('Check production configuration, storage and observed background activity without purchasing or sending messages')]
class ProductionPreflightCommand extends Command
{
    public function handle(OperationsMonitor $monitor): int
    {
        try {
            $runtime = $monitor->summary();
        } catch (Throwable) {
            $runtime = [];
        }

        $checks = [
            'environment' => ['Use APP_ENV=production.', fn (): bool => app()->isProduction()],
            'debug' => ['Disable APP_DEBUG.', fn (): bool => config('app.debug') === false],
            'url' => ['Set APP_URL to a public HTTPS URL without credentials, query or fragment.', $this->hasPublicHttpsUrl(...)],
            'encryption' => ['Configure a valid APP_KEY and cipher; preserve existing encryption keys.', $this->hasEncryptionKey(...)],
            'tokens' => ['Use a positive Sanctum token expiration.', fn (): bool => is_int(config('sanctum.expiration')) && config('sanctum.expiration') > 0],
            'database' => ['Make the application database reachable.', fn (): bool => DB::connection()->selectOne('SELECT 1') !== null],
            'migrations' => ['Apply all application and package migrations.', $this->hasCurrentSchema(...)],
            'queue' => ['Use a reachable durable queue with retry_after greater than the 60-second job timeout.', $this->hasDurableQueue(...)],
            'cache' => ['Use a reachable persistent cache with working atomic locks.', $this->hasWorkingCache(...)],
            'sms' => ['Configure the SMS sender; this check never sends a message.', fn (): bool => app(SmsSender::class)->available()],
            'scheduler' => ['Run the scheduler every minute; activity must be observed within 180 seconds.', fn (): bool => ($runtime['scheduler']['status'] ?? null) === 'recent'],
            'failed_jobs' => ['Enable failed-job monitoring and investigate all outstanding failures.', fn (): bool => ($runtime['failed_jobs']['status'] ?? null) === 'available' && ($runtime['failed_jobs']['count'] ?? null) === 0],
        ];
        foreach (OperationsMonitor::QUEUES as $queue) {
            $checks['worker:'.$queue] = [
                'Observe worker activity within 180 seconds for queue '.$queue.'.',
                fn (): bool => (collect($runtime['workers'] ?? [])->firstWhere('queue', $queue)['status'] ?? null) === 'recent',
            ];
        }

        $results = [];
        foreach ($checks as $name => [$message, $check]) {
            try {
                $passed = $check();
            } catch (Throwable) {
                $passed = false;
            }
            $results[] = ['check' => $name, 'status' => $passed ? 'pass' : 'fail', 'message' => $message];
        }
        $passed = ! in_array('fail', array_column($results, 'status'), true);
        $scope = 'These checks do not verify public DNS/TLS, backup restoration or a live provider sale.';
        if ($this->option('json')) {
            $this->line(json_encode(['passed' => $passed, 'checks' => $results, 'scope' => $scope], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Check', 'Status', 'Requirement'], array_map(fn (array $result): array => array_values($result), $results));
            $this->line($scope);
            if ($passed) {
                $this->info('Production preflight checks passed. Complete the external launch verification before enabling sales.');
            } else {
                $this->error('Production preflight failed. Resolve the failed requirements before launch.');
            }
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private function hasPublicHttpsUrl(): bool
    {
        $url = config('app.url');
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');

        return ($parts['scheme'] ?? null) === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! array_key_exists('query', $parts) && ! array_key_exists('fragment', $parts)
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && str_contains($host, '.')
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && ! preg_match('/(^|\.)(localhost|local|test|example|invalid)$/', $host)
            && ! in_array($host, ['example.com', 'example.net', 'example.org'], true)
            && (! isset($parts['port']) || ($parts['port'] >= 1 && $parts['port'] <= 65535));
    }

    private function hasEncryptionKey(): bool
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            return false;
        }
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }

        return is_string($key) && Encrypter::supported($key, config('app.cipher'));
    }

    private function hasCurrentSchema(): bool
    {
        $migrator = app('migrator');
        $repository = $migrator->getRepository();
        if (! $repository->repositoryExists()) {
            return false;
        }
        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));

        return array_diff(array_keys($files), $repository->getRan()) === [];
    }

    private function hasDurableQueue(): bool
    {
        $connection = config('queue.default');
        $configuration = config('queue.connections.'.$connection, []);
        if (! in_array($configuration['driver'] ?? null, ['database', 'redis', 'beanstalkd'], true)
            || ! is_numeric($configuration['retry_after'] ?? null) || $configuration['retry_after'] <= 60) {
            return false;
        }
        foreach (OperationsMonitor::QUEUES as $queue) {
            Queue::connection($connection)->size($queue);
        }

        return true;
    }

    private function hasWorkingCache(): bool
    {
        if (! in_array(config('cache.stores.'.config('cache.default').'.driver'), ['database', 'redis', 'file', 'memcached', 'dynamodb'], true)) {
            return false;
        }
        $cache = Cache::store();
        $key = 'qanetwork:preflight:'.Str::uuid();
        $lock = $cache->lock($key.':lock', 30);
        $contender = $cache->lock($key.':lock', 30);
        try {
            $cache->put($key, 'probe', 30);
            if ($cache->get($key) !== 'probe' || ! $lock->get()) {
                return false;
            }

            return ! $contender->get();
        } finally {
            try {
                try {
                    $contender->release();
                } finally {
                    $lock->release();
                }
            } finally {
                $cache->forget($key);
            }
        }
    }
}
