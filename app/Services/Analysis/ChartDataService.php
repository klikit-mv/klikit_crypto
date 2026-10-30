<?php

namespace App\Services\Analysis;

use App\Models\TradingSymbol;

/** Candle + indicator payload for TradingView Lightweight Charts (time in UNIX seconds). */
class ChartDataService
{
    public function __construct(
        private readonly CandleService $candles,
        private readonly IndicatorService $indicators,
    ) {}

    /**
     * @param  list<string>  $indicators  e.g. ['ema:20', 'ema:50', 'rsi', 'macd']
     */
    public function build(TradingSymbol $symbol, string $interval, array $indicators = [], int $limit = 300): array
    {
        $series = $this->candles->series($symbol, $interval, $limit);
        $times = $series->map(fn ($c) => $c->open_time->getTimestamp())->all();
        $closes = $series->map(fn ($c) => (float) $c->close)->all();

        $line = fn (array $values) => array_values(array_filter(
            array_map(fn ($t, $v) => $v === null ? null : ['time' => $t, 'value' => round($v, 8)], $times, $values),
        ));

        $data = [
            'symbol' => $symbol->symbol,
            'market_type' => $symbol->market_type,
            'interval' => $interval,
            'candles' => $series->map(fn ($c) => [
                'time' => $c->open_time->getTimestamp(),
                'open' => (float) $c->open, 'high' => (float) $c->high, 'low' => (float) $c->low, 'close' => (float) $c->close,
                'volume' => (float) $c->volume,
            ])->all(),
            'indicators' => [],
        ];

        foreach ($indicators as $spec) {
            [$name, $param] = array_pad(explode(':', $spec, 2), 2, null);
            $data['indicators'][$spec] = match ($name) {
                'ema' => $line($this->indicators->ema($closes, max(2, (int) ($param ?: 20)))),
                'sma' => $line($this->indicators->sma($closes, max(2, (int) ($param ?: 20)))),
                'rsi' => $line($this->indicators->rsi($closes, max(2, (int) ($param ?: 14)))),
                'macd' => array_map($line, $this->indicators->macd($closes)),
                default => null,
            };
        }
        $data['indicators'] = array_filter($data['indicators'], fn ($v) => $v !== null);

        return $data;
    }
}
