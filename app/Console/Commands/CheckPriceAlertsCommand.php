<?php

namespace App\Console\Commands;

use App\Services\Analysis\PriceAlertService;
use Illuminate\Console\Command;

class CheckPriceAlertsCommand extends Command
{
    protected $signature = 'alerts:check';

    protected $description = 'Trigger price and indicator alerts';

    public function handle(PriceAlertService $alerts): int
    {
        $this->info($alerts->check().' alerts triggered.');

        return self::SUCCESS;
    }
}
