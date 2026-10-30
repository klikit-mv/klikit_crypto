<?php

namespace App\Services\Bots\Strategies;

use App\Services\Bots\StrategyContext;

/** Buy when RSI crosses up out of oversold; sell when it crosses down out of overbought. */
class RsiStrategy extends AbstractStrategy
{
    public function type(): string
    {
        return 'rsi';
    }

    public function defaults(): array
    {
        return ['interval' => '1h', 'order_amount' => 100, 'period' => 14, 'oversold' => 30, 'overbought' => 70];
    }

    public function rules(): array
    {
        return $this->baseRules() + [
            'period' => ['required', 'integer', 'min:2', 'max:100'],
            'oversold' => ['required', 'numeric', 'min:1', 'max:50'],
            'overbought' => ['required', 'numeric', 'min:50', 'max:99'],
        ];
    }

    public function evaluate(StrategyContext $c): array
    {
        if (! $this->newCandle($c)) {
            return [];
        }
        $rsi = $this->indicators->rsi($c->closes, (int) $c->params['period']);
        $n = count($rsi);
        if ($n < 2 || $rsi[$n - 1] === null || $rsi[$n - 2] === null) {
            return [];
        }
        [$prev, $now] = [$rsi[$n - 2], $rsi[$n - 1]];

        if (! $c->holds() && $prev < $c->params['oversold'] && $now >= $c->params['oversold']) {
            return [$this->buy($c, sprintf('RSI crossed up through %s (%.1f)', $c->params['oversold'], $now))];
        }
        if ($c->holds() && $prev > $c->params['overbought'] && $now <= $c->params['overbought']) {
            return [$this->sellAll($c, sprintf('RSI crossed down through %s (%.1f)', $c->params['overbought'], $now))];
        }

        return [];
    }
}
