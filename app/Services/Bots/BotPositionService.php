<?php

namespace App\Services\Bots;

use App\Models\Order;
use App\Models\TradingBot;
use App\Support\Decimal;
use Brick\Math\BigDecimal;

/**
 * Derives a bot's holdings and realised results from the orders it placed, so they are
 * always consistent with what actually filled (never from bot memory).
 */
class BotPositionService
{
    /**
     * @return array{holding: BigDecimal, average_cost: BigDecimal, realized: BigDecimal, trades: int, wins: int, max_drawdown: BigDecimal, fees: BigDecimal}
     */
    public function summary(TradingBot $bot): array
    {
        $orders = Order::whereIn('id', $bot->botOrders()->pluck('order_id'))
            ->where('filled_quantity', '>', 0)->with('executions')->orderBy('id')->get();

        $holding = $cost = $realized = $fees = $peak = $drawdown = BigDecimal::zero();
        $trades = $wins = 0;

        foreach ($orders as $order) {
            $qty = Decimal::of($order->filled_quantity);
            $price = Decimal::of($order->average_fill_price);
            $fee = $order->executions->reduce(fn ($s, $e) => $s->plus(Decimal::of($e->fee)), BigDecimal::zero());
            $fees = $fees->plus($fee);

            if ($order->side === 'buy') {
                $holding = $holding->plus($qty);
                $cost = $cost->plus(Decimal::mul($qty, $price))->plus($fee);

                continue;
            }

            $sold = Decimal::min($qty, $holding);
            if (! $sold->isPositive()) {
                continue;
            }
            $avg = Decimal::div($cost, $holding);
            $pnl = Decimal::mul($sold, $price)->minus($fee)->minus(Decimal::mul($sold, $avg));
            $cost = $cost->minus(Decimal::mul($sold, $avg));
            $holding = $holding->minus($sold);
            $realized = $realized->plus($pnl);
            $trades++;
            $wins += $pnl->isPositive() ? 1 : 0;

            $peak = $realized->isGreaterThan($peak) ? $realized : $peak;
            $dd = $peak->minus($realized);
            $drawdown = $dd->isGreaterThan($drawdown) ? $dd : $drawdown;
        }

        return [
            'holding' => $holding,
            'average_cost' => $holding->isPositive() ? Decimal::div($cost, $holding) : BigDecimal::zero(),
            'realized' => $realized,
            'trades' => $trades,
            'wins' => $wins,
            'max_drawdown' => $drawdown,
            'fees' => $fees,
        ];
    }
}
