<?php

namespace App\Services\Trading\Adapters;

use App\Models\TradingSymbol;
use App\Services\Trading\Contracts\BrokerAdapter;

/** Forex / CFD brokers through the MT5 bridge. */
class Mt5BridgeBrokerAdapter extends HttpBridgeAdapter implements BrokerAdapter
{
    public function isMarketOpen(TradingSymbol $symbol): bool
    {
        return (bool) ($this->call('/market-hours', ['symbol' => $symbol->symbol])['open'] ?? false);
    }
}
