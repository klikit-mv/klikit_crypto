<?php

namespace App\Services\Bots\Strategies;

use App\Services\Bots\StrategyContext;

/** Trend following on fast/slow EMA crossovers. */
class TrendStrategy extends AbstractStrategy
{
    public function type(): string
    {
        return 'trend';
    }

    public function defaults(): array
    {
        return ['interval' => '4h', 'order_amount' => 100, 'fast_ema' => 12, 'slow_ema' => 26];
    }

    public function rules(): array
    {
        return $this->baseRules() + [
            'fast_ema' => ['required', 'integer', 'min:2', 'max:200'],
            'slow_ema' => ['required', 'integer', 'min:3', 'max:400', 'gt:fast_ema'],
        ];
    }

    public function evaluate(StrategyContext $c): array
    {
        if (! $this->newCandle($c)) {
            return [];
        }
        $fast = $this->indicators->ema($c->closes, (int) $c->params['fast_ema']);
        $slow = $this->indicators->ema($c->closes, (int) $c->params['slow_ema']);

        if (! $c->holds() && $this->crossedAbove($fast, $slow)) {
            return [$this->buy($c, 'Fast EMA crossed above slow EMA')];
        }
        if ($c->holds() && $this->crossedBelow($fast, $slow)) {
            return [$this->sellAll($c, 'Fast EMA crossed below slow EMA')];
        }

        return [];
    }
}
