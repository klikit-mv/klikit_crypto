<?php

namespace App\Services\Bots\Strategies;

use App\Services\Bots\StrategyContext;

/** Buys dips below a short EMA; exits on a small take-profit or stop-loss from the entry. */
class ScalpingStrategy extends AbstractStrategy
{
    public function type(): string
    {
        return 'scalping';
    }

    public function defaults(): array
    {
        return ['interval' => '5m', 'order_amount' => 100, 'ema' => 20, 'dip_pct' => 0.5, 'take_profit_pct' => 0.4, 'stop_loss_pct' => 0.3];
    }

    public function rules(): array
    {
        return $this->baseRules() + [
            'ema' => ['required', 'integer', 'min:2', 'max:200'],
            'dip_pct' => ['required', 'numeric', 'min:0.05', 'max:20'],
            'take_profit_pct' => ['required', 'numeric', 'min:0.05', 'max:50'],
            'stop_loss_pct' => ['required', 'numeric', 'min:0.05', 'max:50'],
        ];
    }

    public function evaluate(StrategyContext $c): array
    {
        // Exits are checked on every run (price based), entries once per candle.
        if ($c->holds() && $c->averageCost > 0) {
            $change = ($c->price - $c->averageCost) / $c->averageCost * 100;
            if ($change >= $c->params['take_profit_pct']) {
                return [$this->sellAll($c, sprintf('Take profit +%.2f%%', $change))];
            }
            if ($change <= -$c->params['stop_loss_pct']) {
                return [$this->sellAll($c, sprintf('Stop loss %.2f%%', $change))];
            }

            return [];
        }
        if (! $this->newCandle($c)) {
            return [];
        }

        $ema = $this->indicators->last($this->indicators->ema($c->closes, (int) $c->params['ema']));
        if ($ema !== null && $c->price <= $ema * (1 - $c->params['dip_pct'] / 100)) {
            return [$this->buy($c, sprintf('Price %.2f%% below EMA%d', ($ema - $c->price) / $ema * 100, $c->params['ema']))];
        }

        return [];
    }
}
