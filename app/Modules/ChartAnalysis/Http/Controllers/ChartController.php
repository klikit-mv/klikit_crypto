<?php

namespace App\Modules\ChartAnalysis\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MarketCandle;
use App\Models\PriceAlert;
use App\Models\TradingSymbol;
use App\Modules\ChartAnalysis\AlertManager;
use App\Services\Analysis\ChartDataService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Serves both the Blade chart page and the JSON used by it and by the API. */
class ChartController extends Controller
{
    private const BASIC_INDICATORS = ['ema', 'sma'];

    public function page(Request $request, SubscriptionService $subscriptions): View
    {
        $user = $request->user();

        return view('charts.index', [
            'symbols' => TradingSymbol::active()->orderBy('market_type')->orderBy('symbol')->get(),
            'selected' => (int) $request->query('symbol_id', TradingSymbol::active()->value('id')),
            'interval' => in_array($request->query('interval'), MarketCandle::INTERVALS, true) ? $request->query('interval') : '1h',
            'advanced' => $subscriptions->hasFeature($user, 'charts.advanced'),
            'alerts' => PriceAlert::where('user_id', $user->id)->with('symbol')->latest()->limit(30)->get(),
            'notifications' => $user->notifications()->latest()->limit(10)->get(),
        ]);
    }

    public function data(Request $request, ChartDataService $charts, SubscriptionService $subscriptions, ?TradingSymbol $symbol = null): JsonResponse
    {
        $data = $request->validate([
            'symbol_id' => [$symbol ? 'nullable' : 'required', 'integer', 'exists:trading_symbols,id'],
            'interval' => ['nullable', Rule::in(MarketCandle::INTERVALS)],
            'indicators' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:1000'],
        ]);
        $symbol ??= TradingSymbol::findOrFail($data['symbol_id']);

        $requested = array_filter(array_map('trim', explode(',', $data['indicators'] ?? '')));
        $advanced = $subscriptions->hasFeature($request->user(), 'charts.advanced');
        $allowed = array_values(array_filter($requested, fn ($i) => $advanced || in_array(explode(':', $i)[0], self::BASIC_INDICATORS, true)));

        return response()->json($charts->build($symbol, $data['interval'] ?? '1h', $allowed, $data['limit'] ?? 300) + [
            'locked_indicators' => array_values(array_diff($requested, $allowed)),
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        return response()->json(['data' => PriceAlert::where('user_id', $request->user()->id)->with('symbol:id,symbol,market_type')->latest()->get()]);
    }

    public function storeAlert(Request $request, AlertManager $manager): JsonResponse|RedirectResponse
    {
        $alert = $manager->create($request->user(), $request->validate($manager->rules()));

        return $request->expectsJson()
            ? response()->json(['data' => $alert], 201)
            : back()->with('status', 'Alert created.');
    }

    public function destroyAlert(Request $request, PriceAlert $alert): JsonResponse|RedirectResponse
    {
        abort_unless($alert->user_id === $request->user()->id, 404);
        $alert->update(['status' => 'cancelled']);

        return $request->expectsJson() ? response()->json(['message' => 'Alert cancelled.']) : back()->with('status', 'Alert cancelled.');
    }

    public function markNotificationsRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
