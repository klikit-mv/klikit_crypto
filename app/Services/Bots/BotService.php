<?php

namespace App\Services\Bots;

use App\Models\BotConfiguration;
use App\Models\BotOrder;
use App\Models\BotPerformance;
use App\Models\BotRun;
use App\Models\BotSignal;
use App\Models\BotStrategy;
use App\Models\MarketCandle;
use App\Models\TradingBot;
use App\Models\TradingSymbol;
use App\Models\User;
use App\Notifications\BotSignalAwaitingApproval;
use App\Services\Access\AuditLogger;
use App\Services\Analysis\CandleService;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Trading\Data\OrderIntent;
use App\Services\Trading\Exceptions\AdapterException;
use App\Services\Trading\Exceptions\RiskViolation;
use App\Services\Trading\MarketData\MarketDataService;
use App\Services\Trading\PriceConverter;
use App\Services\Trading\TradingEngineService;
use App\Support\Decimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class BotService
{
    /** Pending bot signals older than this are expired rather than executed at a stale price. */
    public const SIGNAL_TTL_MINUTES = 30;

    public function __construct(
        private readonly StrategyRegistry $strategies,
        private readonly BotPositionService $positions,
        private readonly CandleService $candles,
        private readonly MarketDataService $marketData,
        private readonly PriceConverter $converter,
        private readonly TradingEngineService $engine,
        private readonly SubscriptionService $subscriptions,
        private readonly AuditLogger $audit,
    ) {}

    public function create(User $user, array $data): TradingBot
    {
        $max = $this->subscriptions->limit($user, 'bots.max');
        if ($max !== -1 && TradingBot::where('user_id', $user->id)->count() >= $max) {
            throw ValidationException::withMessages(['strategy_id' => $max === 0 ? 'Your plan does not include trading bots.' : "Your plan allows $max bots."]);
        }
        if ($data['execution_mode'] === 'fully_automatic' && ! $this->subscriptions->hasFeature($user, 'bots.automated')) {
            throw ValidationException::withMessages(['execution_mode' => 'Fully automatic bots require a plan with automated trading.']);
        }

        $strategy = BotStrategy::findOrFail($data['strategy_id']);
        $symbol = TradingSymbol::findOrFail($data['symbol_id']);
        if (! $symbol->isSpot()) {
            throw ValidationException::withMessages(['symbol_id' => 'Bots currently trade spot instruments only.']);
        }
        $params = $this->validateParams($strategy->strategy_type, $data['parameters'] ?? []);

        return DB::transaction(function () use ($user, $data, $strategy, $symbol, $params) {
            $bot = TradingBot::create([
                'user_id' => $user->id,
                'strategy_id' => $strategy->id,
                'symbol_id' => $symbol->id,
                'name' => $data['name'],
                'market' => $symbol->market_type,
                'mode' => 'paper',
                'execution_mode' => $data['execution_mode'],
                'status' => 'stopped',
                'state' => [],
            ]);
            BotConfiguration::create(['bot_id' => $bot->id, 'parameters' => $params, 'version' => 1]);
            $this->audit->log('bot.created', $user, $bot, ['strategy' => $strategy->strategy_type]);

            return $bot;
        });
    }

    public function updateParameters(TradingBot $bot, array $parameters): void
    {
        $params = $this->validateParams($bot->strategy->strategy_type, $parameters);
        $version = (int) $bot->configurations()->max('version') + 1;
        BotConfiguration::create(['bot_id' => $bot->id, 'parameters' => $params, 'version' => $version]);
    }

    public function validateParams(string $type, array $parameters): array
    {
        $strategy = $this->strategies->get($type);
        $params = array_merge($strategy->defaults(), array_filter($parameters, fn ($v) => $v !== null && $v !== ''));

        return Validator::make($params, $strategy->rules())->validate() + $params;
    }

    public function start(TradingBot $bot): void
    {
        if ($bot->status === 'running') {
            return;
        }
        if ($bot->execution_mode === 'fully_automatic' && ! $this->subscriptions->hasFeature($bot->user, 'bots.automated')) {
            throw ValidationException::withMessages(['bot' => 'Your plan does not include automated bots.']);
        }
        $active = TradingBot::where('user_id', $bot->user_id)->where('status', 'running')->count();
        $max = $this->subscriptions->limit($bot->user, 'bots.max');
        if ($max !== -1 && $active >= $max) {
            throw ValidationException::withMessages(['bot' => "Your plan allows $max running bots."]);
        }

        $bot->update(['status' => 'running', 'last_error' => null]);
        BotRun::create(['bot_id' => $bot->id, 'started_at' => now(), 'status' => 'running']);
        $this->audit->log('bot.started', $bot->user, $bot);
    }

    public function stop(TradingBot $bot, string $reason = 'stopped', ?string $error = null): void
    {
        $bot->update(['status' => $error ? 'error' : 'stopped', 'last_error' => $error]);
        BotRun::where('bot_id', $bot->id)->where('status', 'running')->update(['status' => $error ? 'failed' : $reason, 'stopped_at' => now(), 'error' => $error]);
        BotSignal::where('bot_id', $bot->id)->where('status', 'pending_approval')->update(['status' => 'expired']);
        $this->audit->log('bot.stopped', $bot->user, $bot, ['reason' => $reason, 'error' => $error]);
    }

    /** One evaluation of a running bot (called every minute by bots:run). */
    public function tick(TradingBot $bot): void
    {
        $bot->loadMissing('strategy', 'symbol', 'user');
        try {
            if (! $bot->user->isActive()) {
                $this->stop($bot, 'stopped', 'Account is not active');

                return;
            }
            if ($bot->execution_mode === 'fully_automatic' && ! $this->subscriptions->hasFeature($bot->user, 'bots.automated')) {
                $this->stop($bot, 'stopped', 'Subscription no longer includes automated bots');

                return;
            }

            $strategy = $this->strategies->get($bot->strategy->strategy_type);
            $params = $bot->currentConfiguration()->parameters;
            $interval = $strategy->interval($params);
            $series = $this->candles->series($bot->symbol, $interval, 300);
            $ticker = $this->marketData->ticker($bot->symbol);
            $position = $this->positions->summary($bot);

            // Only closed candles feed indicators; the open one is still changing.
            $closed = $series->filter(fn ($c) => $c->open_time->getTimestamp() + MarketCandle::seconds($interval) <= now()->getTimestamp())->values();
            $context = new StrategyContext(
                symbol: $bot->symbol,
                closes: $closed->map(fn ($c) => (float) $c->close)->all(),
                candleTime: (int) ($closed->last()?->open_time->getTimestamp() ?? 0),
                price: (float) $ticker->last,
                holding: (float) (string) $position['holding'],
                averageCost: (float) (string) $position['average_cost'],
                params: $params,
                state: $bot->state ?? [],
                now: now()->getTimestamp(),
            );

            $decisions = $strategy->evaluate($context);
            $bot->update(['state' => $context->state, 'last_run_at' => now(), 'last_error' => null]);

            foreach ($decisions as $decision) {
                $this->handleDecision($bot, $decision, $position['holding']);
            }
        } catch (Throwable $e) {
            report($e);
            $bot->update(['last_error' => substr($e->getMessage(), 0, 250), 'last_run_at' => now()]);
        }
    }

    public function approveSignal(BotSignal $signal): BotSignal
    {
        if ($signal->status !== 'pending_approval') {
            throw new RiskViolation('signal_not_pending', 'This bot signal is no longer awaiting approval.');
        }
        if ($signal->created_at->lt(now()->subMinutes(self::SIGNAL_TTL_MINUTES))) {
            $signal->update(['status' => 'expired']);
            throw new RiskViolation('signal_expired', 'This bot signal has expired.');
        }

        return $this->executeSignal($signal);
    }

    public function rejectSignal(BotSignal $signal): void
    {
        if ($signal->status === 'pending_approval') {
            $signal->update(['status' => 'rejected']);
        }
    }

    /** Daily performance snapshot for a bot. */
    public function recordPerformance(TradingBot $bot): BotPerformance
    {
        $summary = $this->positions->summary($bot);

        return BotPerformance::updateOrCreate(
            ['bot_id' => $bot->id, 'period_date' => now()->toDateString()],
            [
                'profit' => Decimal::str($summary['realized']),
                'drawdown' => Decimal::str($summary['max_drawdown'], 4),
                'win_rate' => $summary['trades'] ? round($summary['wins'] / $summary['trades'] * 100, 4) : 0,
                'trades_count' => $summary['trades'],
            ],
        );
    }

    private function handleDecision(TradingBot $bot, TradeDecision $decision, $holding): void
    {
        $symbol = $bot->symbol;
        $ticker = $this->marketData->ticker($symbol);

        if ($decision->side === 'buy') {
            $price = Decimal::of($ticker->ask);
            $usdPerUnit = $this->converter->toUsd(Decimal::mul($price, $symbol->contract_size), $symbol->quote_currency);
            $quantity = Decimal::div($decision->amountUsd, $usdPerUnit);
        } else {
            $quantity = Decimal::min(Decimal::of(number_format($decision->quantity, 18, '.', '')), $holding);
        }
        $quantity = $quantity->toScale($symbol->quantity_precision, RoundingMode::DOWN);

        $signal = BotSignal::create([
            'bot_id' => $bot->id,
            'side' => $decision->side,
            'type' => 'market',
            'quantity' => Decimal::str($quantity),
            'reason' => substr($decision->reason, 0, 255),
            'status' => 'pending_approval',
        ]);

        if ($quantity->isLessThan(Decimal::of($symbol->min_quantity)) || $quantity->isZero()) {
            $signal->update(['status' => 'failed', 'reason' => $signal->reason.' — quantity below instrument minimum']);

            return;
        }

        if ($bot->execution_mode === 'fully_automatic') {
            $this->executeSignal($signal);
        } else {
            $bot->user->notify(new BotSignalAwaitingApproval($signal));
        }
    }

    private function executeSignal(BotSignal $signal): BotSignal
    {
        $bot = $signal->bot()->with('symbol', 'user')->first();

        try {
            $order = $this->engine->submit(OrderIntent::fromArray($bot->user, $bot->symbol, [
                'side' => $signal->side,
                'type' => 'market',
                'quantity' => (string) Decimal::of($signal->quantity)->stripTrailingZeros(),
                'mode' => $bot->mode,
            ], 'bot'), "bot-signal-{$signal->id}");

            BotOrder::create([
                'bot_id' => $bot->id,
                'bot_run_id' => BotRun::where('bot_id', $bot->id)->where('status', 'running')->value('id'),
                'order_id' => $order->id,
                'execution_status' => $order->status,
            ]);
            $signal->update(['status' => $order->status === 'rejected' ? 'failed' : 'executed', 'order_id' => $order->id]);
        } catch (RiskViolation|AdapterException $e) {
            $signal->update(['status' => 'failed', 'reason' => substr($signal->reason.' — '.$e->getMessage(), 0, 255)]);
        }

        return $signal;
    }
}
