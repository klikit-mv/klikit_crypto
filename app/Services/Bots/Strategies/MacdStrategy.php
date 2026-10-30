<?php

namespace App\Services\Bots\Strategies;

use App\Services\Bots\StrategyContext;

/** Trades MACD / signal-line crossovers. */
class MacdStrategy extends AbstractStrategy
{
    public function type(): string
    {
        return 'macd';
    }

    public function defaults(): array
    {
        return ['interval' => '1h', 'order_amount' => 100, 'fast' => 12, 'slow' => 26, 'signal' => 9];
    }

    public function rules(): array
    {
        return $this->baseRules() + [
            'fast' => ['required', 'integer', 'min:2', 'max:100'],
            'slow' => ['required', 'integer', 'min:3', 'max:200', 'gt:fast'],
            'signal' => ['required', 'integer', 'min:2', 'max:100'],
        ];
    }

    public function evaluate(StrategyContext $c): array
    {
        if (! $this->newCandle($c)) {
            return [];
        }
        $m = $this->indicators->macd($c->closes, (int) $c->params['fast'], (int) $c->params['slow'], (int) $c->params['signal']);

        if (! $c->holds() && $this->crossedAbove($m['macd'], $m['signal'])) {
            return [$this->buy($c, 'MACD crossed above signal line')];
        }
        if ($c->holds() && $this->crossedBelow($m['macd'], $m['signal'])) {
            return [$this->sellAll($c, 'MACD crossed below signal line')];
        }

        return [];
    }
}
