<?php

namespace App\Services\Bots\Strategies;

use App\Services\Analysis\IndicatorService;
use App\Services\Bots\Strategy;
use App\Services\Bots\StrategyContext;
use App\Services\Bots\TradeDecision;

abstract class AbstractStrategy implements Strategy
{
    public function __construct(protected readonly IndicatorService $indicators) {}

    public function interval(array $params): string
    {
        return $params['interval'] ?? '1h';
    }

    protected function baseRules(): array
    {
        return [
            'interval' => ['required', 'in:5m,15m,1h,4h,1d'],
            'order_amount' => ['required', 'numeric', 'min:1'],
        ];
    }

    /** Signals act once per closed candle. */
    protected function newCandle(StrategyContext $c): bool
    {
        if (($c->state['last_candle'] ?? null) === $c->candleTime) {
            return false;
        }
        $c->state['last_candle'] = $c->candleTime;

        return true;
    }

    protected function buy(StrategyContext $c, string $reason): TradeDecision
    {
        return TradeDecision::buy((string) $c->params['order_amount'], $reason);
    }

    protected function sellAll(StrategyContext $c, string $reason): TradeDecision
    {
        return TradeDecision::sell($c->holding, $reason);
    }

    /** True when series a crossed above b on the last bar. */
    protected function crossedAbove(array $a, array $b): bool
    {
        $n = count($a);

        return $n >= 2 && isset($a[$n - 2], $b[$n - 2], $a[$n - 1], $b[$n - 1])
            && $a[$n - 2] <= $b[$n - 2] && $a[$n - 1] > $b[$n - 1];
    }

    protected function crossedBelow(array $a, array $b): bool
    {
        return $this->crossedAbove($b, $a);
    }
}
