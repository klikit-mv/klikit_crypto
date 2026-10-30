<?php

namespace App\Http\Middleware;

use App\Services\Subscriptions\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('feature:bots.automated') */
class EnsureFeature
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! $request->user() || ! $this->subscriptions->hasFeature($request->user(), $feature)) {
            abort(402, 'Your subscription plan does not include this feature.');
        }

        return $next($request);
    }
}
