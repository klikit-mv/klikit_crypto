<?php

namespace App\Console\Commands;

use App\Models\MarketCandle;
use App\Models\TradingSymbol;
use App\Services\Analysis\CandleService;
use Illuminate\Console\Command;
use Throwable;

class BackfillCandlesCommand extends Command
{
    protected $signature = 'market:candles {--interval=1h} {--limit=500}';

    protected $description = 'Back-fill spot candle history from Binance public klines';

    public function handle(CandleService $candles): int
    {
        $interval = $this->option('interval');
        if (! in_array($interval, MarketCandle::INTERVALS, true)) {
            $this->error('Interval must be one of '.implode(', ', MarketCandle::INTERVALS));

            return self::FAILURE;
        }

        foreach (TradingSymbol::active()->where('market_type', TradingSymbol::SPOT)->get() as $symbol) {
            try {
                $this->line("{$symbol->symbol}: ".$candles->backfillFromBinance($symbol, $interval, (int) $this->option('limit')).' candles');
            } catch (Throwable $e) {
                $this->warn("{$symbol->symbol}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
