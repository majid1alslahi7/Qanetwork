<?php

namespace App\Providers;

use App\Models\AccountRegistration;
use App\Models\Network;
use App\Models\ProviderSettlement;
use App\Models\Sale;
use App\Models\SellerDeposit;
use App\Observers\OperationalNotificationObserver;
use App\Providers\Inventory\StoredCardAdapter;
use App\Providers\MikroTik\MikroTikHotspotAdapter;
use App\Providers\MikroTik\MikroTikUserManagerAdapter;
use App\Providers\MikroTik\RouterOsClient;
use App\Providers\MikroTik\RouterOsConnector;
use App\Providers\MikroTik\SocketRouterOsClient;
use App\Providers\MikroTik\StreamRouterOsConnector;
use App\Providers\Registry\ProviderAdapterRegistry;
use App\Services\Delivery\Contracts\SmsSender;
use App\Services\Delivery\UnavailableSmsSender;
use App\Services\Operations\OperationsMonitor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OperationsMonitor::class);
        $this->app->bind(SmsSender::class, UnavailableSmsSender::class);
        $this->app->bind(RouterOsConnector::class, StreamRouterOsConnector::class);
        $this->app->bind(RouterOsClient::class, SocketRouterOsClient::class);
        $this->app->singleton(ProviderAdapterRegistry::class, function ($app): ProviderAdapterRegistry {
            $registry = new ProviderAdapterRegistry;
            $registry->register('mikrotik_hotspot', $app->make(MikroTikHotspotAdapter::class));
            $registry->register('mikrotik_user_manager', $app->make(MikroTikUserManagerAdapter::class));
            $registry->register('stored_cards', $app->make(StoredCardAdapter::class));

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Sale::class, SellerDeposit::class, ProviderSettlement::class, AccountRegistration::class, Network::class] as $model) {
            $model::observe(OperationalNotificationObserver::class);
        }
        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            $this->app->make(OperationsMonitor::class)->recordScheduler();
        });
        Event::listen(Looping::class, function (Looping $event): void {
            $this->app->make(OperationsMonitor::class)->recordWorker($event->connectionName, (string) $event->queue);
        });
        Event::listen([JobProcessing::class, JobProcessed::class], function (JobProcessing|JobProcessed $event): void {
            $this->app->make(OperationsMonitor::class)->recordWorker($event->connectionName, (string) $event->job->getQueue());
        });
        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');
            $account = is_string($email) ? mb_strtolower(trim($email)) : '';

            return [
                Limit::perMinute(5)->by('login-account:'.hash('sha256', $account).'|'.$request->ip()),
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by((string) $request->user()->id));
        RateLimiter::for('notification-feed', fn (Request $request) => Limit::perMinute(10)->by('notification-feed:'.$request->user()->id));
        RateLimiter::for('notification-device', fn (Request $request) => Limit::perMinute(10)->by('notification-device:'.$request->user()->id));
        RateLimiter::for('registration', fn (Request $request) => [
            Limit::perMinute(3)->by('registration-minute:'.$request->ip()),
            Limit::perHour(20)->by('registration-hour:'.$request->ip()),
        ]);
        RateLimiter::for('card-reveal', fn (Request $request) => Limit::perMinute(20)->by('card-reveal:'.$request->user()->id));
        RateLimiter::for('network-health', fn (Request $request) => Limit::perMinute(10)->by('network-health:'.$request->user()->id));
        RateLimiter::for('manual-review', fn (Request $request) => Limit::perMinute(10)->by('manual-review:'.$request->user()->id));
        RateLimiter::for('card-delivery', fn (Request $request) => Limit::perMinute(5)->by('card-delivery:'.$request->user()->id));
        RateLimiter::for('inventory-import', fn (Request $request) => Limit::perMinute(3)->by('inventory-import:'.$request->user()->id));
    }
}
