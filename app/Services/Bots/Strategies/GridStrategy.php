<?php

namespace App\Services\Bots\Strategies;

use App\Services\Bots\StrategyContext;
use App\Services\Bots\TradeDecision;

/**
 * Grid trading between lower and upper bounds. Each level buys order_amount when price
 * falls to it and sells that lot when price reaches the next level up.
 */
class GridStrategy extends AbstractStrategy
{
    public function type(): string
    {
        return 'grid';
    }

    public function defaults(): array
    {
        return ['interval' => '1h', 'order_amount' => 50, 'lower_price' => null, 'upper_price' => null, 'grid_count' => 10];
    }

    public function rules(): array
    {
        return $this->baseRules() + [
            'lower_price' => ['required', 'numeric', 'gt:0'],
            'upper_price' => ['required', 'numeric', 'gt:lower_price'],
            'grid_count' => ['required', 'integer', 'min:2', 'max:100'],
        ];
    }

    public function evaluate(StrategyContext $c): array
    {
        $p = $c->params;
        $step = ($p['upper_price'] - $p['lower_price']) / $p['grid_count'];
        $levels = array_map(fn ($i) => $p['lower_price'] + $i * $step, range(0, $p['grid_count']));
        $lots = $c->state['lots'] ?? []; // level index => quantity bought there

        // Sell lots whose next level has been reached.
        foreach ($lots as $i => $qty) {
            if (isset($levels[$i + 1]) && $c->price >= $levels[$i + 1]) {
                unset($lots[$i]);
                $c->state['lots'] = $lots;

                return [TradeDecision::sell(min($qty, $c->holding), sprintf('Grid sell at level %d (%.8g)', $i + 1, $levels[$i + 1]))];
            }
        }

        // Buy at the highest empty level at or above the price (below the top bound).
        if ($c->price >= $p['lower_price'] && $c->price < $p['upper_price']) {
            $i = (int) floor(($c->price - $p['lower_price']) / $step);
            if (! isset($lots[$i])) {
                $lots[$i] = (float) $p['order_amount'] / $c->price;
                $c->state['lots'] = $lots;

                return [$this->buy($c, sprintf('Grid buy at level %d (%.8g)', $i, $levels[$i]))];
            }
        }

        return [];
    }
}
