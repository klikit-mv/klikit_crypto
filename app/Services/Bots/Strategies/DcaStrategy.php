<?php

namespace App\Services\Bots\Strategies;

use App\Services\Bots\StrategyContext;

/**
 * Dollar-cost averaging: a base buy every interval, extra "safety" buys when price falls
 * by step_pct below the last buy, and a full exit at take_profit_pct above average cost.
 */
class DcaStrategy extends AbstractStrategy
{
    public function type(): string
    {
        return 'dca';
    }

    public function defaults(): array
    {
        return ['interval' => '1h', 'order_amount' => 50, 'interval_hours' => 24, 'safety_orders' => 3, 'step_pct' => 3, 'take_profit_pct' => 5];
    }

    public function rules(): array
    {
        return $this->baseRules() + [
            'interval_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'safety_orders' => ['required', 'integer', 'min:0', 'max:20'],
            'step_pct' => ['required', 'numeric', 'min:0.1', 'max:50'],
            'take_profit_pct' => ['required', 'numeric', 'min:0.1', 'max:500'],
        ];
    }

    public function evaluate(StrategyContext $c): array
    {
        $p = $c->params;
        $s = &$c->state;

        if ($c->holds() && $c->averageCost > 0 && $c->price >= $c->averageCost * (1 + $p['take_profit_pct'] / 100)) {
            $s['safety_used'] = 0;
            $s['last_buy_price'] = null;

            return [$this->sellAll($c, sprintf('Take profit at %.2f%% above average cost', ($c->price / $c->averageCost - 1) * 100))];
        }

        $lastBuyAt = $s['last_buy_at'] ?? null;
        if ($lastBuyAt === null || $c->now - $lastBuyAt >= $p['interval_hours'] * 3600) {
            $s['last_buy_at'] = $c->now;
            $s['last_buy_price'] = $c->price;

            return [$this->buy($c, 'Scheduled DCA buy')];
        }

        $used = $s['safety_used'] ?? 0;
        $lastPrice = $s['last_buy_price'] ?? null;
        if ($lastPrice && $used < $p['safety_orders'] && $c->price <= $lastPrice * (1 - $p['step_pct'] / 100)) {
            $s['safety_used'] = $used + 1;
            $s['last_buy_price'] = $c->price;

            return [$this->buy($c, sprintf('Safety order %d after %.2f%% drop', $used + 1, (1 - $c->price / $lastPrice) * 100))];
        }

        return [];
    }
}
