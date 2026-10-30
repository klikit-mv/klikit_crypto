<?php

namespace App\Services\Trading;

use App\Models\Order;
use App\Models\Position;
use App\Services\Trading\Adapters\CcxtBridgeExchangeAdapter;
use App\Services\Trading\Adapters\Mt5BridgeBrokerAdapter;
use App\Services\Trading\Adapters\PaperTradingAdapter;
use App\Services\Trading\Contracts\TradingVenueAdapter;
use App\Services\Trading\Exceptions\AdapterException;

/**
 * Selects the execution adapter for an order: paper, a crypto exchange or a Forex broker.
 */
class ExecutionRouter
{
    public function __construct(private readonly PaperTradingAdapter $paper) {}

    public function forOrder(Order $order): TradingVenueAdapter
    {
        if ($order->mode !== 'live') {
            return $this->paper;
        }

        $this->assertLiveEnabled();

        if ($order->symbol->usesBroker()) {
            $account = $order->brokerAccount ?? throw new AdapterException('Live Forex orders need a broker account.');

            return new Mt5BridgeBrokerAdapter($account->broker->code, $account->encrypted_credentials, config('trading.bridges.mt5'));
        }

        $account = $order->exchangeAccount ?? throw new AdapterException('Live crypto orders need an exchange account.');

        return new CcxtBridgeExchangeAdapter($account->exchange->code, $account->encrypted_credentials, config('trading.bridges.ccxt'));
    }

    public function forPosition(Position $position): TradingVenueAdapter
    {
        if ($position->mode !== 'live') {
            return $this->paper;
        }

        $this->assertLiveEnabled();

        // Live positions are closed through the account of the order that opened them.
        $order = $position->trades()->with('order')->oldest()->first()?->order
            ?? throw new AdapterException('Cannot resolve the account for this position.');

        return $this->forOrder($order);
    }

    private function assertLiveEnabled(): void
    {
        if (! config('trading.live_trading_enabled')) {
            throw new AdapterException('Live trading is disabled on this platform.');
        }
    }
}
