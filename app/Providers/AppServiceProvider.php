<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Access\AuditLogger;
use App\Services\Payments\ManualPaymentGateway;
use App\Services\Payments\PaymentService;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Trading\MarketData\BinancePublicMarketData;
use App\Services\Trading\MarketData\MarketDataService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per-request caches (entitlements) must not leak between requests in long-running workers.
        $this->app->scoped(SubscriptionService::class);
        $this->app->scoped(AuditLogger::class, fn ($app) => new AuditLogger($app->bound('request') ? $app['request'] : null));

        $this->app->singleton(MarketDataService::class, fn ($app) => new MarketDataService(
            $app->make(BinancePublicMarketData::class),
        ));

        $this->app->bind(PaymentService::class, fn ($app) => new PaymentService(
            $app->make(SubscriptionService::class),
            $app->make(AuditLogger::class),
            $app->make(ManualPaymentGateway::class),
        ));
    }

    public function boot(): void
    {
        // Every catalogue permission is a Gate ability. Returning null (not false) lets
        // policies decide abilities that are not plain permissions.
        Gate::before(function (User $user, string $ability) {
            if (! $user->isActive()) {
                return false;
            }

            return $user->hasPermission($ability) ? true : null;
        });

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('trading', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
    }
}
