<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Trading\TradingEngineService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a funded, risk-approved order to its venue. Runs on the dedicated trading queue.
 * Not retried automatically: a venue timeout may still have created the order, so failed
 * executions are left for reconciliation instead of being blindly resubmitted.
 */
class ExecuteOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $orderId) {}

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function handle(TradingEngineService $engine): void
    {
        $order = Order::with('symbol', 'user', 'exchangeAccount.exchange', 'brokerAccount.broker')->find($this->orderId);
        if ($order) {
            $engine->execute($order);
        }
    }
}
