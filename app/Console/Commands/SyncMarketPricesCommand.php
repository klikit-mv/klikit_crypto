<?php

namespace App\Console\Commands;

use App\Models\TradingSymbol;
use App\Services\Trading\MarketData\MarketDataService;
use Illuminate\Console\Command;
use Throwable;

class SyncMarketPricesCommand extends Command
{
    protected $signature = 'market:sync';

    protected $description = 'Fetch the latest prices from the configured read-only market data providers';

    public function handle(MarketDataService $marketData): int
    {
        try {
            $count = $marketData->refresh(TradingSymbol::active()->get());
        } catch (Throwable $e) {
            report($e);
            $this->error("Market data sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Stored $count prices.");

        return self::SUCCESS;
    }
}
