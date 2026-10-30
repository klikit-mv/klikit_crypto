<?php

namespace App\Console\Commands;

use App\Models\BrokerAccount;
use App\Models\ExchangeAccount;
use App\Services\Trading\VenueAccountService;
use Illuminate\Console\Command;

class VerifyVenueAccountsCommand extends Command
{
    protected $signature = 'accounts:verify';

    protected $description = 'Re-verify connected exchange and broker credentials through the execution bridge';

    public function handle(VenueAccountService $service): int
    {
        foreach ([ExchangeAccount::with('exchange', 'user'), BrokerAccount::with('broker', 'user')] as $query) {
            $query->whereNotIn('status', ['revoked'])->chunkById(100, function ($accounts) use ($service) {
                foreach ($accounts as $account) {
                    $this->line(class_basename($account)." #{$account->id}: ".$service->verify($account)->status);
                }
            });
        }

        return self::SUCCESS;
    }
}
