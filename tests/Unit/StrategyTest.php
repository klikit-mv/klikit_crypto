<?php

namespace Tests\Unit;

use App\Models\TradingSymbol;
use App\Services\Analysis\IndicatorService;
use App\Services\Bots\StrategyContext;
use App\Services\Bots\StrategyRegistry;
use PHPUnit\Framework\TestCase;

class StrategyTest extends TestCase
{
    private function context(array $closes, float $price, array $params, array $state = [], float $holding = 0, float $avg = 0, int $now = 1_000_000): StrategyContext
    {
        return new StrategyContext(new TradingSymbol(['symbol' => 'BTC/USDT']), $closes, count($closes), $price, $holding, $avg, $params, $state, $now);
    }

    private function strategy(string $type)
    {
        return (new StrategyRegistry(new IndicatorService))->get($type);
    }

    public function test_rsi_buys_on_cross_up_from_oversold_once_per_candle(): void
    {
        $s = $this->strategy('rsi');
        // Steady fall (RSI 0) then a sharp bounce lifts RSI back above 30.
        $closes = array_merge(array_map(fn ($i) => 100 - $i * 2, range(0, 20)), [90]);
        $ctx = $this->context($closes, 90, $s->defaults());

        $decisions = $s->evaluate($ctx);
        $this->assertSame('buy', $decisions[0]->side);
        $this->assertSame([], $s->evaluate($this->context($closes, 90, $s->defaults(), $ctx->state)));
    }

    public function test_trend_sells_holdings_on_bearish_cross(): void
    {
        $s = $this->strategy('trend');
        $closes = array_merge(range(100, 160, 2), range(150, 40, -10));
        $sells = [];
        for ($i = 30; $i <= count($closes); $i++) {
            foreach ($s->evaluate($this->context(array_slice($closes, 0, $i), end($closes), $s->defaults(), [], 1.5)) as $d) {
                $sells[] = $d;
            }
        }
        $this->assertSame('sell', $sells[0]->side);
        $this->assertSame(1.5, $sells[0]->quantity);
    }

    public function test_dca_schedule_safety_orders_and_take_profit(): void
    {
        $s = $this->strategy('dca');
        $p = $s->defaults();

        $c1 = $this->context([], 100, $p);
        $this->assertSame('Scheduled DCA buy', $s->evaluate($c1)[0]->reason);

        $c2 = $this->context([], 99, $p, $c1->state, 0.5, 100, 1_000_600);
        $this->assertSame([], $s->evaluate($c2));

        $c3 = $this->context([], 96.5, $p, $c2->state, 0.5, 100, 1_001_200);
        $this->assertStringStartsWith('Safety order 1', $s->evaluate($c3)[0]->reason);

        $c4 = $this->context([], 104, $p, $c3->state, 1.0, 98.5, 1_001_800);
        $exit = $s->evaluate($c4)[0];
        $this->assertSame(['sell', 1.0], [$exit->side, $exit->quantity]);
    }

    public function test_grid_buys_level_and_sells_at_next_level(): void
    {
        $s = $this->strategy('grid');
        $p = ['interval' => '1h', 'order_amount' => 100, 'lower_price' => 90, 'upper_price' => 110, 'grid_count' => 4];

        $c1 = $this->context([], 96, $p);
        $this->assertSame('buy', $s->evaluate($c1)[0]->side);
        $this->assertSame([], $s->evaluate($this->context([], 96, $p, $c1->state, 1.0)));

        $sell = $s->evaluate($this->context([], 100.5, $p, $c1->state, 2.0))[0];
        $this->assertSame('sell', $sell->side);
        $this->assertEqualsWithDelta(100 / 96, $sell->quantity, 1e-9);
    }

    public function test_scalping_take_profit_and_stop_loss(): void
    {
        $s = $this->strategy('scalping');
        $this->assertStringStartsWith('Take profit', $s->evaluate($this->context([], 100.5, $s->defaults(), [], 1, 100))[0]->reason);
        $this->assertStringStartsWith('Stop loss', $s->evaluate($this->context([], 99.6, $s->defaults(), [], 1, 100))[0]->reason);
    }
}
