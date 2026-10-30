<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Trading\TradingEngineService;
use Illuminate\Console\Command;
use Throwable;

class SyncOpenOrdersCommand extends Command
{
    protected $signature = 'trading:sync-orders';

    protected $description = 'Re-check open orders with their venue (fills paper limit orders that became marketable)';

    public function handle(TradingEngineService $engine): int
    {
        // Live orders whose execution job died after the venue call may sit in "pending":
        // reconcile them by client order id instead of resubmitting.
        Order::where('status', Order::PENDING)->where('mode', 'live')->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => Order::OPEN]);

        $filled = 0;
        Order::whereIn('status', [Order::OPEN, Order::PARTIALLY_FILLED])->with('symbol', 'user')->chunkById(200, function ($orders) use ($engine, &$filled) {
            foreach ($orders as $order) {
                try {
                    if ($engine->sync($order)->status === Order::FILLED) {
                        $filled++;
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });

        $this->info("$filled orders filled.");

        return self::SUCCESS;
    }
}
