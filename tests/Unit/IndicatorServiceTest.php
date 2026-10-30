<?php

namespace Tests\Unit;

use App\Services\Analysis\IndicatorService;
use PHPUnit\Framework\TestCase;

class IndicatorServiceTest extends TestCase
{
    public function test_sma_and_ema(): void
    {
        $i = new IndicatorService;

        $this->assertSame([null, null, 2.0, 3.0, 4.0], $i->sma([1, 2, 3, 4, 5], 3));
        // EMA(3): seed 2, then (4-2)*0.5+2 = 3, (5-3)*0.5+3 = 4
        $this->assertSame([null, null, 2.0, 3.0, 4.0], $i->ema([1, 2, 3, 4, 5], 3));
    }

    public function test_rsi_matches_wilder_reference_values(): void
    {
        // StockCharts' RSI worked example (their spreadsheet: 70.46, 66.25 ... 57.92).
        $closes = [44.34, 44.09, 44.15, 43.61, 44.33, 44.83, 45.10, 45.42, 45.84, 46.08, 45.89, 46.03, 45.61, 46.28, 46.28, 46.00, 46.03, 46.41, 46.22, 45.64];
        $rsi = (new IndicatorService)->rsi($closes, 14);

        $this->assertNull($rsi[13]);
        $this->assertEqualsWithDelta(70.46, $rsi[14], 0.01);
        $this->assertEqualsWithDelta(66.25, $rsi[15], 0.01);
        $this->assertEqualsWithDelta(57.92, $rsi[19], 0.01);
    }

    public function test_rsi_extremes(): void
    {
        $i = new IndicatorService;
        $this->assertSame(100.0, $i->last($i->rsi(range(1, 20), 14)));
        $this->assertSame(0.0, $i->last($i->rsi(range(20, 1, -1), 14)));
    }

    public function test_macd_histogram_is_macd_minus_signal(): void
    {
        $closes = array_map(fn ($x) => 100 + sin($x / 3) * 5, range(1, 80));
        $macd = (new IndicatorService)->macd($closes);

        $this->assertNull($macd['signal'][30]);
        $this->assertNotNull($macd['signal'][33]);
        $this->assertEqualsWithDelta($macd['macd'][60] - $macd['signal'][60], $macd['histogram'][60], 1e-9);
    }
}
