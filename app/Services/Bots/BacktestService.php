<?php

namespace App\Services\Bots;

use App\Models\BotBacktest;
use App\Models\BotStrategy;
use App\Models\MarketCandle;
use App\Models\TradingSymbol;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Replays stored candles through a strategy. Fills happen at the candle close with the
 * paper fee rate; this is an estimate, not a promise of live results (no slippage model).
 */
class BacktestService
{
    private const WARMUP = 50;

    public function __construct(
        private readonly StrategyRegistry $strategies,
        private readonly BotService $bots,
    ) {}

    public function run(?User $user, BotStrategy $strategyModel, TradingSymbol $symbol, array $parameters, Carbon $from, Carbon $to, float $startingCash = 10000): BotBacktest
    {
        $strategy = $this->strategies->automated()[$strategyModel->strategy_type]
            ?? throw ValidationException::withMessages(['strategy_id' => 'This strategy cannot be backtested.']);
        $params = $this->bots->validateParams($strategyModel->strategy_type, $parameters);
        $interval = $strategy->interval($params);

        $candles = MarketCandle::where('symbol_id', $symbol->id)->where('interval', $interval)
            ->whereBetween('open_time', [$from, $to])->orderBy('open_time')->get();
        if ($candles->count() <= self::WARMUP + 1) {
            throw ValidationException::withMessages(['period_start' => "Not enough $interval candles in this period (need more than ".(self::WARMUP + 1).', found '.$candles->count().'). Back-fill with php artisan market:candles.']);
        }

        $results = $this->simulate($strategy, $symbol, $params, $candles->all(), $interval, $startingCash);

        return BotBacktest::create([
            'strategy_id' => $strategyModel->id,
            'user_id' => $user?->id,
            'symbol_id' => $symbol->id,
            'parameters' => $params,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'status' => 'completed',
            'results' => $results,
        ]);
    }

    /** @param list<MarketCandle> $candles */
    public function simulate(Strategy $strategy, TradingSymbol $symbol, array $params, array $candles, string $interval, float $startingCash): array
    {
        $feeRate = (float) config('trading.paper.fee_rate');
        $cash = $startingCash;
        $qty = $cost = $peak = $maxDrawdown = 0.0;
        $peak = $startingCash;
        $state = [];
        $trades = $equity = [];
        $wins = $closedTrades = 0;
        $closes = array_map(fn ($c) => (float) $c->close, $candles);
        $seconds = MarketCandle::seconds($interval);

        for ($i = self::WARMUP; $i < count($candles); $i++) {
            $price = $closes[$i];
            $time = $candles[$i]->open_time->getTimestamp();
            $context = new StrategyContext($symbol, array_slice($closes, 0, $i + 1), $time, $price, $qty, $qty > 0 ? $cost / $qty : 0.0, $params, $state, $time + $seconds);

            foreach ($strategy->evaluate($context) as $decision) {
                if ($decision->side === 'buy') {
                    $spend = min((float) $decision->amountUsd, $cash / (1 + $feeRate));
                    if ($spend < 1) {
                        continue;
                    }
                    $bought = $spend / $price;
                    $fee = $spend * $feeRate;
                    $cash -= $spend + $fee;
                    $qty += $bought;
                    $cost += $spend + $fee;
                    $trades[] = ['time' => $time, 'side' => 'buy', 'price' => $price, 'quantity' => round($bought, 8), 'reason' => $decision->reason];
                } else {
                    $sold = min($decision->quantity, $qty);
                    if ($sold <= 0) {
                        continue;
                    }
                    $proceeds = $sold * $price;
                    $fee = $proceeds * $feeRate;
                    $basis = $cost * $sold / $qty;
                    $pnl = $proceeds - $fee - $basis;
                    $cash += $proceeds - $fee;
                    $cost -= $basis;
                    $qty -= $sold;
                    $closedTrades++;
                    $wins += $pnl > 0 ? 1 : 0;
                    $trades[] = ['time' => $time, 'side' => 'sell', 'price' => $price, 'quantity' => round($sold, 8), 'pnl' => round($pnl, 2), 'reason' => $decision->reason];
                }
            }
            $state = $context->state;

            $value = $cash + $qty * $price;
            $peak = max($peak, $value);
            $maxDrawdown = max($maxDrawdown, $peak > 0 ? ($peak - $value) / $peak * 100 : 0);
            $equity[] = ['time' => $time, 'value' => round($value, 2)];
        }

        $final = end($equity)['value'];
        $first = $closes[self::WARMUP];
        $last = end($closes);
        $step = max(1, (int) ceil(count($equity) / 300));

        return [
            'interval' => $interval,
            'starting_cash' => $startingCash,
            'final_equity' => $final,
            'return_pct' => round(($final / $startingCash - 1) * 100, 2),
            'buy_and_hold_return_pct' => round(($last / $first - 1) * 100, 2),
            'max_drawdown_pct' => round($maxDrawdown, 2),
            'trades' => count($trades),
            'closed_trades' => $closedTrades,
            'win_rate' => $closedTrades ? round($wins / $closedTrades * 100, 2) : null,
            'open_quantity' => round($qty, 8),
            'candles' => count($candles),
            'equity_curve' => array_values(array_filter($equity, fn ($k) => $k % $step === 0, ARRAY_FILTER_USE_KEY)),
            'trade_log' => array_slice($trades, -200),
        ];
    }
}
