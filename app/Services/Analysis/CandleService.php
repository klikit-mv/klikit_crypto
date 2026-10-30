<?php

namespace App\Services\Analysis;

use App\Models\MarketCandle;
use App\Models\TradingSymbol;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * OHLCV candles. Every stored price tick updates the open candle of each interval, so any
 * instrument with a price feed (crypto or Forex) gets charts; spot crypto history can be
 * back-filled from Binance's public klines.
 */
class CandleService
{
    public function recordTick(int $symbolId, string $price, ?Carbon $at = null, string $volume = '0'): void
    {
        $at = ($at ?? now())->copy()->utc();
        $price = Decimal::of($price);

        foreach (MarketCandle::INTERVALS as $interval) {
            $openTime = $this->openTime($at, $interval);
            $candle = MarketCandle::where(['symbol_id' => $symbolId, 'interval' => $interval, 'open_time' => $openTime])->first();

            if ($candle === null) {
                MarketCandle::create([
                    'symbol_id' => $symbolId, 'interval' => $interval, 'open_time' => $openTime,
                    'open' => Decimal::str($price), 'high' => Decimal::str($price), 'low' => Decimal::str($price),
                    'close' => Decimal::str($price), 'volume' => Decimal::str($volume),
                ]);

                continue;
            }

            $candle->forceFill([
                'high' => Decimal::str(Decimal::of($candle->high)->isLessThan($price) ? $price : $candle->high),
                'low' => Decimal::str(Decimal::of($candle->low)->isGreaterThan($price) ? $price : $candle->low),
                'close' => Decimal::str($price),
                'volume' => Decimal::str(Decimal::of($candle->volume)->plus(Decimal::of($volume))),
            ])->save();
        }
    }

    /** Back-fill spot candles from Binance public klines. Returns the number stored. */
    public function backfillFromBinance(TradingSymbol $symbol, string $interval, int $limit = 500): int
    {
        $rows = Http::baseUrl(config('trading.market_data.binance_public_url'))
            ->timeout(15)
            ->get('/api/v3/klines', ['symbol' => $symbol->base_currency.$symbol->quote_currency, 'interval' => $interval, 'limit' => min($limit, 1000)])
            ->throw()
            ->json();

        $records = array_map(fn (array $k) => [
            'symbol_id' => $symbol->id,
            'interval' => $interval,
            'open_time' => Carbon::createFromTimestampMs($k[0])->utc()->format('Y-m-d H:i:s'),
            'open' => Decimal::str($k[1]), 'high' => Decimal::str($k[2]), 'low' => Decimal::str($k[3]),
            'close' => Decimal::str($k[4]), 'volume' => Decimal::str($k[5]),
        ], $rows);

        foreach (array_chunk($records, 200) as $chunk) {
            MarketCandle::upsert($chunk, ['symbol_id', 'interval', 'open_time'], ['open', 'high', 'low', 'close', 'volume']);
        }

        return count($records);
    }

    /** @return Collection<int, MarketCandle> oldest first */
    public function series(TradingSymbol $symbol, string $interval, int $limit = 300): Collection
    {
        return MarketCandle::where('symbol_id', $symbol->id)->where('interval', $interval)
            ->orderByDesc('open_time')->limit($limit)->get()->reverse()->values();
    }

    /** @return list<float> */
    public function closes(TradingSymbol $symbol, string $interval, int $limit = 300): array
    {
        return $this->series($symbol, $interval, $limit)->map(fn ($c) => (float) $c->close)->all();
    }

    public function openTime(Carbon $at, string $interval): Carbon
    {
        $seconds = MarketCandle::seconds($interval);

        return Carbon::createFromTimestampUTC(intdiv($at->getTimestamp(), $seconds) * $seconds);
    }
}
