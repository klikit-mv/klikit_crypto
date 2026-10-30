<?php

namespace App\Console\Commands;

use App\Models\MiningPoolAccount;
use App\Services\Mining\MiningService;
use Illuminate\Console\Command;
use Throwable;

class SyncMiningPoolsCommand extends Command
{
    protected $signature = 'mining:sync-pools {--days=7}';

    protected $description = 'Import earnings and payouts from mining pool APIs';

    public function handle(MiningService $mining): int
    {
        MiningPoolAccount::with('pool', 'user')->chunkById(100, function ($accounts) use ($mining) {
            foreach ($accounts as $account) {
                try {
                    $this->line("{$account->pool->name}/{$account->account_name}: ".$mining->syncPoolAccount($account, (int) $this->option('days')).' days');
                } catch (Throwable $e) {
                    report($e);
                    $this->warn("{$account->pool->name}/{$account->account_name}: {$e->getMessage()}");
                }
            }
        });

        return self::SUCCESS;
    }
}
