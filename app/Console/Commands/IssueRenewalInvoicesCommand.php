<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Console\Command;

class IssueRenewalInvoicesCommand extends Command
{
    protected $signature = 'subscriptions:renew {--days=3}';

    protected $description = 'Issue renewal invoices for auto-renewing subscriptions that expire soon';

    public function handle(SubscriptionService $subscriptions): int
    {
        $this->info($subscriptions->issueRenewalInvoices((int) $this->option('days')).' renewal invoices issued.');

        return self::SUCCESS;
    }
}
