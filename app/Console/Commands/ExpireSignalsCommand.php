<?php

namespace App\Console\Commands;

use App\Services\Signals\SignalService;
use Illuminate\Console\Command;

class ExpireSignalsCommand extends Command
{
    protected $signature = 'signals:expire';

    protected $description = 'Expire signal approvals whose validity window has passed';

    public function handle(SignalService $signals): int
    {
        $this->info($signals->expireStale().' signal approvals expired.');

        return self::SUCCESS;
    }
}
