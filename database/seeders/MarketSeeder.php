<?php

namespace Database\Seeders;

use App\Models\Broker;
use App\Models\Exchange;
use App\Models\MarketPrice;
use App\Models\TradingSymbol;
use App\Support\Decimal;
use Illuminate\Database\Seeder;

/**
 * Venues, instruments and an initial price snapshot so paper trading works out of the box.
 * Run `php artisan market:sync` to replace seeded crypto prices with live public prices.
 */
class MarketSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['binance', 'Binance', 'ccxt', true],
            ['kraken', 'Kraken', 'ccxt', true],
            ['bybit', 'Bybit', 'ccxt', true],
        ] as [$code, $name, $type, $futures]) {
            Exchange::firstOrCreate(['code' => $code], ['name' => $name, 'api_type' => $type, 'status' => 'disabled', 'supports_futures' => $futures]);
        }
        Broker::firstOrCreate(['code' => 'mt5-generic'], ['name' => 'MetaTrader 5 (bridge)', 'integration_type' => 'mt5_bridge', 'status' => 'disabled']);

        // symbol, market, base, quote, price, spread, precision, min qty, max leverage, contract size
        $instruments = [
            ['BTC/USDT', 'SPOT', 'BTC', 'USDT', '67000', '5', 2, '0.00001', 1, 1],
            ['ETH/USDT', 'SPOT', 'ETH', 'USDT', '3500', '0.5', 2, '0.0001', 1, 1],
            ['SOL/USDT', 'SPOT', 'SOL', 'USDT', '150', '0.05', 3, '0.01', 1, 1],
            ['BNB/USDT', 'SPOT', 'BNB', 'USDT', '580', '0.1', 2, '0.001', 1, 1],
            ['XRP/USDT', 'SPOT', 'XRP', 'USDT', '0.55', '0.0005', 4, '1', 1, 1],
            ['BTC/USDT', 'FUTURES', 'BTC', 'USDT', '67010', '5', 1, '0.001', 50, 1],
            ['ETH/USDT', 'FUTURES', 'ETH', 'USDT', '3501', '0.5', 2, '0.01', 50, 1],
            ['EUR/USD', 'FOREX', 'EUR', 'USD', '1.0850', '0.0001', 5, '0.01', 30, 100000],
            ['GBP/USD', 'FOREX', 'GBP', 'USD', '1.2700', '0.00015', 5, '0.01', 30, 100000],
            ['USD/JPY', 'FOREX', 'USD', 'JPY', '150.00', '0.015', 3, '0.01', 30, 100000],
            ['AUD/USD', 'FOREX', 'AUD', 'USD', '0.6600', '0.0001', 5, '0.01', 30, 100000],
            ['XAU/USD', 'CFD', 'XAU', 'USD', '2350.00', '0.30', 2, '0.01', 20, 100],
        ];

        foreach ($instruments as [$symbol, $market, $base, $quote, $price, $spread, $precision, $minQty, $maxLev, $contract]) {
            $instrument = TradingSymbol::firstOrCreate(['symbol' => $symbol, 'market_type' => $market], [
                'base_currency' => $base,
                'quote_currency' => $quote,
                'price_precision' => $precision,
                'quantity_precision' => 8,
                'min_quantity' => $minQty,
                'max_leverage' => $maxLev,
                'contract_size' => $contract,
            ]);

            if (! $instrument->prices()->exists()) {
                $half = Decimal::div($spread, 2);
                MarketPrice::create([
                    'symbol_id' => $instrument->id,
                    'source' => 'seed',
                    'bid' => Decimal::str(Decimal::of($price)->minus($half)),
                    'ask' => Decimal::str(Decimal::of($price)->plus($half)),
                    'price' => $price,
                    'change_24h' => 0,
                    'timestamp' => now(),
                ]);
            }
        }
    }
}
