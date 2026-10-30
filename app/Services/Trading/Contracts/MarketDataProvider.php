<?php

namespace App\Services\Trading\Contracts;

use App\Models\TradingSymbol;
use App\Services\Trading\Data\Ticker;

/** Read-only price source. Needs no user credentials. */
interface MarketDataProvider
{
    public function name(): string;

    public function supports(TradingSymbol $symbol): bool;

    /**
     * @param  iterable<TradingSymbol>  $symbols
     * @return array<int, Ticker> keyed by symbol id
     */
    public function tickers(iterable $symbols): array;
}
