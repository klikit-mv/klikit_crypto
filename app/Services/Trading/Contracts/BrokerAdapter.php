<?php

namespace App\Services\Trading\Contracts;

use App\Models\TradingSymbol;

/** Forex / CFD broker, typically via an MT5 bridge or broker REST API. */
interface BrokerAdapter extends TradingVenueAdapter
{
    /** Forex markets close at weekends and on broker holidays. */
    public function isMarketOpen(TradingSymbol $symbol): bool;
}
