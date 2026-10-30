<?php

namespace App\Console\Commands;

use App\Models\TradingBot;
use App\Services\Bots\BotService;
use App\Services\PlatformSettings;
use Illuminate\Console\Command;

class RunBotsCommand extends Command
{
    protected $signature = 'bots:run';

    protected $description = 'Evaluate every running trading bot once';

    public function handle(BotService $bots, PlatformSettings $settings): int
    {
        if ($settings->tradingHalted()) {
            $this->warn('Platform trading is halted; bots not evaluated.');

            return self::SUCCESS;
        }

        $count = 0;
        TradingBot::where('status', 'running')->chunkById(100, function ($batch) use ($bots, &$count) {
            foreach ($batch as $bot) {
                $bots->tick($bot);
                $count++;
            }
        });
        $this->info("$count bots evaluated.");

        return self::SUCCESS;
    }
}
