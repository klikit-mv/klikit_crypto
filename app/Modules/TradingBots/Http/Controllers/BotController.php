<?php

namespace App\Modules\TradingBots\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BotBacktest;
use App\Models\BotSignal;
use App\Models\BotStrategy;
use App\Models\TradingBot;
use App\Models\TradingSymbol;
use App\Services\Bots\BacktestService;
use App\Services\Bots\BotPositionService;
use App\Services\Bots\BotService;
use App\Services\Bots\StrategyRegistry;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Web pages and REST endpoints for trading bots (JSON when the client expects it). */
class BotController extends Controller
{
    public function index(Request $request, StrategyRegistry $registry, SubscriptionService $subscriptions, BotPositionService $positions): View|JsonResponse
    {
        $user = $request->user();
        $bots = TradingBot::where('user_id', $user->id)->with('strategy', 'symbol')->latest()->get();

        if ($request->expectsJson()) {
            return response()->json(['data' => $bots->map(fn ($b) => $this->present($b, $positions))]);
        }

        return view('bots.index', [
            'bots' => $bots,
            'summaries' => $bots->mapWithKeys(fn ($b) => [$b->id => $positions->summary($b)]),
            'strategies' => BotStrategy::where('is_active', true)->get(),
            'defaults' => collect($registry->all())->map->defaults(),
            'symbols' => TradingSymbol::active()->where('market_type', TradingSymbol::SPOT)->orderBy('symbol')->get(),
            'pendingSignals' => BotSignal::whereIn('bot_id', $bots->pluck('id'))->where('status', 'pending_approval')->with('bot')->latest()->get(),
            'maxBots' => $subscriptions->limit($user, 'bots.max'),
            'automated' => $subscriptions->hasFeature($user, 'bots.automated'),
        ]);
    }

    public function store(Request $request, BotService $bots): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'strategy_id' => ['required', Rule::exists('bot_strategies', 'id')->where('is_active', true)],
            'symbol_id' => ['required', Rule::exists('trading_symbols', 'id')->where('is_active', true)],
            'execution_mode' => ['required', Rule::in(['semi_automatic', 'fully_automatic'])],
            'parameters' => ['nullable', 'array'],
        ]);
        $bot = $bots->create($request->user(), $data);

        return $request->expectsJson() ? response()->json(['data' => $bot], 201) : redirect()->route('bots.show', $bot)->with('status', 'Bot created. Review the parameters and start it.');
    }

    public function show(Request $request, TradingBot $bot, BotPositionService $positions): View|JsonResponse
    {
        $this->owns($request, $bot);
        $bot->load('strategy', 'symbol');

        if ($request->expectsJson()) {
            return response()->json(['data' => $this->present($bot, $positions) + [
                'signals' => $bot->signals()->latest()->limit(50)->get(),
                'performance' => $bot->performance()->orderBy('period_date')->get(),
            ]]);
        }

        return view('bots.show', [
            'bot' => $bot,
            'summary' => $positions->summary($bot),
            'parameters' => $bot->currentConfiguration()?->parameters ?? [],
            'signals' => $bot->signals()->with('order')->latest()->limit(50)->get(),
            'runs' => $bot->runs()->latest()->limit(10)->get(),
        ]);
    }

    public function update(Request $request, TradingBot $bot, BotService $bots): JsonResponse|RedirectResponse
    {
        $this->owns($request, $bot);
        $bots->updateParameters($bot, $request->validate(['parameters' => ['required', 'array']])['parameters']);

        return $request->expectsJson() ? response()->json(['message' => 'Parameters saved.']) : back()->with('status', 'Parameters saved (new configuration version).');
    }

    public function start(Request $request, TradingBot $bot, BotService $bots): JsonResponse|RedirectResponse
    {
        $this->owns($request, $bot);
        $bots->start($bot);

        return $request->expectsJson() ? response()->json(['status' => 'running']) : back()->with('status', 'Bot started.');
    }

    public function stop(Request $request, TradingBot $bot, BotService $bots): JsonResponse|RedirectResponse
    {
        $this->owns($request, $bot);
        $bots->stop($bot);

        return $request->expectsJson() ? response()->json(['status' => 'stopped']) : back()->with('status', 'Bot stopped.');
    }

    public function destroy(Request $request, TradingBot $bot, BotService $bots): JsonResponse|RedirectResponse
    {
        $this->owns($request, $bot);
        if ($bot->status === 'running') {
            $bots->stop($bot);
        }
        $bot->delete();

        return $request->expectsJson() ? response()->json(['message' => 'Bot deleted.']) : redirect()->route('bots.index')->with('status', 'Bot deleted.');
    }

    public function approveSignal(Request $request, BotSignal $signal, BotService $bots): JsonResponse|RedirectResponse
    {
        $this->owns($request, $signal->bot);
        $signal = $bots->approveSignal($signal);

        return $request->expectsJson() ? response()->json(['data' => $signal]) : back()->with('status', "Signal {$signal->status}.");
    }

    public function rejectSignal(Request $request, BotSignal $signal, BotService $bots): JsonResponse|RedirectResponse
    {
        $this->owns($request, $signal->bot);
        $bots->rejectSignal($signal);

        return $request->expectsJson() ? response()->json(['data' => $signal->fresh()]) : back()->with('status', 'Signal rejected.');
    }

    public function backtests(Request $request, StrategyRegistry $registry): View|JsonResponse
    {
        $backtests = BotBacktest::where('user_id', $request->user()->id)->with('strategy')->latest()->limit(20)->get();
        if ($request->expectsJson()) {
            return response()->json(['data' => $backtests]);
        }

        return view('bots.backtest', [
            'backtests' => $backtests,
            'selected' => $request->query('id') ? $backtests->firstWhere('id', (int) $request->query('id')) : $backtests->first(),
            'strategies' => BotStrategy::whereIn('strategy_type', array_keys($registry->automated()))->get(),
            'defaults' => collect($registry->all())->map->defaults(),
            'symbols' => TradingSymbol::active()->orderBy('market_type')->orderBy('symbol')->get(),
        ]);
    }

    public function runBacktest(Request $request, BacktestService $backtests, SubscriptionService $subscriptions): JsonResponse|RedirectResponse
    {
        abort_unless($subscriptions->hasFeature($request->user(), 'charts.advanced'), 402, 'Backtesting requires a plan with advanced charts.');
        $data = $request->validate([
            'strategy_id' => ['required', 'exists:bot_strategies,id'],
            'symbol_id' => ['required', 'exists:trading_symbols,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'starting_cash' => ['nullable', 'numeric', 'min:100', 'max:100000000'],
            'parameters' => ['nullable', 'array'],
        ]);

        $backtest = $backtests->run(
            $request->user(),
            BotStrategy::findOrFail($data['strategy_id']),
            TradingSymbol::findOrFail($data['symbol_id']),
            $data['parameters'] ?? [],
            Carbon::parse($data['period_start'])->startOfDay(),
            Carbon::parse($data['period_end'])->endOfDay(),
            (float) ($data['starting_cash'] ?? 10000),
        );

        return $request->expectsJson() ? response()->json(['data' => $backtest], 201) : redirect()->route('bots.backtests', ['id' => $backtest->id]);
    }

    private function owns(Request $request, TradingBot $bot): void
    {
        abort_unless($bot->user_id === $request->user()->id, 404);
    }

    private function present(TradingBot $bot, BotPositionService $positions): array
    {
        $s = $positions->summary($bot);

        return [
            'id' => $bot->id,
            'name' => $bot->name,
            'strategy' => $bot->strategy->strategy_type,
            'symbol' => $bot->symbol->symbol,
            'mode' => $bot->mode,
            'execution_mode' => $bot->execution_mode,
            'status' => $bot->status,
            'last_run_at' => $bot->last_run_at,
            'last_error' => $bot->last_error,
            'holding' => Decimal::display($s['holding']),
            'average_cost' => Decimal::display($s['average_cost']),
            'realized_pnl' => Decimal::display($s['realized'], 2),
            'closed_trades' => $s['trades'],
            'win_rate' => $s['trades'] ? round($s['wins'] / $s['trades'] * 100, 2) : null,
        ];
    }
}
