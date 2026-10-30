<?php

namespace App\Modules\Dashboard;

use App\Models\Announcement;
use App\Models\MiningEarning;
use App\Models\MiningExpense;
use App\Models\Order;
use App\Models\TelegramSignal;
use App\Models\TradingBot;
use App\Models\TradingSymbol;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Trading\PortfolioService;
use App\Support\Decimal;

/**
 * Data for the user dashboard, shared by the Blade UI and the REST API.
 */
class DashboardService
{
    public function __construct(
        private readonly PortfolioService $portfolio,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function overview(User $user, string $mode = 'paper'): array
    {
        $subscription = $this->subscriptions->current($user);
        $since = now()->subDays(30)->toDateString();

        return [
            'mode' => $mode,
            'portfolio' => $this->portfolio->summary($user, $mode),
            'bots' => [
                'active' => TradingBot::where('user_id', $user->id)->where('status', 'running')->count(),
                'total' => TradingBot::where('user_id', $user->id)->count(),
            ],
            'mining' => [
                'earnings_30d' => MiningEarning::where('user_id', $user->id)->where('date', '>=', $since)->get()->groupBy('currency')
                    ->map(fn ($rows) => Decimal::display($rows->reduce(fn ($s, $r) => $s->plus(Decimal::of($r->amount)), Decimal::of(0)))),
                'expenses_30d' => Decimal::display(MiningExpense::whereHas('device', fn ($q) => $q->where('user_id', $user->id))->where('date', '>=', $since)->get()
                    ->reduce(fn ($s, $e) => $s->plus(Decimal::of($e->electricity_cost))->plus(Decimal::of($e->pool_fees))->plus(Decimal::of($e->other_costs)), Decimal::of(0)), 2),
            ],
            'signals' => TelegramSignal::whereHas('channel', fn ($q) => $q->where('user_id', $user->id))->latest('received_at')->limit(5)
                ->get(['id', 'symbol', 'signal_type', 'entry_price', 'status', 'received_at']),
            'market' => TradingSymbol::active()->with('latestPrice')->orderBy('market_type')->orderBy('symbol')->limit(12)->get()
                ->map(fn (TradingSymbol $s) => [
                    'symbol' => $s->symbol,
                    'market_type' => $s->market_type,
                    'price' => $s->latestPrice ? Decimal::display($s->latestPrice->price) : null,
                    'change_24h' => $s->latestPrice?->change_24h,
                ]),
            'recent_orders' => Order::where('user_id', $user->id)->with('symbol:id,symbol')->latest()->limit(10)->get()
                ->map(fn (Order $o) => [
                    'id' => $o->uuid,
                    'symbol' => $o->symbol->symbol,
                    'side' => $o->side,
                    'type' => $o->type,
                    'quantity' => Decimal::display($o->quantity),
                    'average_fill_price' => $o->average_fill_price ? Decimal::display($o->average_fill_price) : null,
                    'status' => $o->status,
                    'created_at' => $o->created_at,
                ]),
            'subscription' => $subscription ? [
                'plan' => $subscription->plan->name,
                'expires_at' => $subscription->expires_at,
                'auto_renew' => $subscription->auto_renew,
            ] : null,
            'announcements' => Announcement::visible()->latest('published_at')->limit(3)->get(['id', 'title', 'body', 'level', 'published_at']),
        ];
    }
}
