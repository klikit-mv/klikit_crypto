<?php

namespace App\Services\Trading\MarketData;

use App\Models\TradingSymbol;
use App\Services\Trading\Contracts\MarketDataProvider;
use App\Services\Trading\Data\Ticker;
use Illuminate\Support\Facades\Http;

/**
 * Read-only spot prices from Binance's public REST API (no API key required).
 */
class BinancePublicMarketData implements MarketDataProvider
{
    public function name(): string
    {
        return 'binance';
    }

    public function supports(TradingSymbol $symbol): bool
    {
        return $symbol->market_type === TradingSymbol::SPOT;
    }

    public function tickers(iterable $symbols): array
    {
        $byExchangeSymbol = [];
        foreach ($symbols as $symbol) {
            $byExchangeSymbol[$symbol->base_currency.$symbol->quote_currency] = $symbol->id;
        }

        $response = Http::baseUrl(config('trading.market_data.binance_public_url'))
            ->timeout(10)
            ->get('/api/v3/ticker/24hr', ['symbols' => json_encode(array_keys($byExchangeSymbol))])
            ->throw();

        $tickers = [];
        foreach ($response->json() as $row) {
            $symbolId = $byExchangeSymbol[$row['symbol']] ?? null;
            if ($symbolId === null) {
                continue;
            }
            $tickers[$symbolId] = new Ticker(
                $row['symbol'],
                (string) $row['bidPrice'],
                (string) $row['askPrice'],
                (string) $row['lastPrice'],
                (string) $row['priceChangePercent'],
                (string) $row['volume'],
            );
        }

        return $tickers;
    }
}
