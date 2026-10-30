<?php

namespace App\Services\Trading;

use App\Models\MarketPrice;
use App\Models\TradingSymbol;
use App\Services\Trading\Exceptions\RiskViolation;
use App\Support\Decimal;
use Brick\Math\BigDecimal;

/**
 * Converts amounts to their USD equivalent using stored market prices, so risk limits can be
 * expressed in a single currency across crypto and Forex instruments.
 */
class PriceConverter
{
    public function toUsd(BigDecimal|string $amount, string $currency): BigDecimal
    {
        $currency = strtoupper($currency);
        if (in_array($currency, config('trading.usd_equivalents'), true)) {
            return Decimal::of($amount);
        }

        foreach (config('trading.usd_equivalents') as $usd) {
            if ($rate = $this->latest("$currency/$usd")) {
                return Decimal::mul($amount, $rate);
            }
            if ($rate = $this->latest("$usd/$currency")) {
                return Decimal::div($amount, $rate);
            }
        }

        throw new RiskViolation('no_conversion_rate', "Cannot value $currency in USD: no conversion rate available.");
    }

    private function latest(string $symbol): ?string
    {
        $symbolIds = TradingSymbol::where('symbol', $symbol)->pluck('id');
        if ($symbolIds->isEmpty()) {
            return null;
        }

        return MarketPrice::whereIn('symbol_id', $symbolIds)->latest('timestamp')->value('price');
    }
}
