<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Console\Command;

class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Expire subscriptions whose paid period has ended';

    public function handle(SubscriptionService $subscriptions): int
    {
        $this->info($subscriptions->expireLapsed().' subscriptions expired.');

        return self::SUCCESS;
    }
}
