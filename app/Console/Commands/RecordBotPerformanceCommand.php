<?php

namespace App\Console\Commands;

use App\Models\TradingBot;
use App\Services\Bots\BotService;
use Illuminate\Console\Command;

class RecordBotPerformanceCommand extends Command
{
    protected $signature = 'bots:performance';

    protected $description = 'Snapshot daily performance for all bots';

    public function handle(BotService $bots): int
    {
        TradingBot::with('user')->chunkById(200, fn ($batch) => $batch->each(fn ($bot) => $bots->recordPerformance($bot)));

        return self::SUCCESS;
    }
}
