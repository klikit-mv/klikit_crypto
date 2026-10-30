<?php

namespace App\Console\Commands;

use App\Models\MiningDevice;
use App\Services\Mining\MiningService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ComputeMiningProfitabilityCommand extends Command
{
    protected $signature = 'mining:profitability {--date= : Day to compute (default: today and yesterday)}';

    protected $description = 'Compute revenue, electricity, pool fees and net profit per device';

    public function handle(MiningService $mining): int
    {
        $mining->markOffline();
        $dates = $this->option('date') ? [Carbon::parse($this->option('date'))] : [now()->subDay(), now()];

        MiningDevice::with('pool')->chunkById(200, function ($devices) use ($mining, $dates) {
            foreach ($devices as $device) {
                foreach ($dates as $date) {
                    $mining->computeDay($device, $date);
                }
            }
        });

        return self::SUCCESS;
    }
}
