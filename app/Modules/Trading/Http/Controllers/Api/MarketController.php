<?php

namespace App\Modules\Trading\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TradingSymbol;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketController extends Controller
{
    public function symbols(Request $request, string $market): JsonResponse
    {
        $symbols = TradingSymbol::active()->whereIn('market_type', $this->marketTypes($market))->orderBy('symbol')->get();

        return response()->json(['data' => $symbols->map(fn (TradingSymbol $s) => [
            'id' => $s->id,
            'symbol' => $s->symbol,
            'market_type' => $s->market_type,
            'base_currency' => $s->base_currency,
            'quote_currency' => $s->quote_currency,
            'min_quantity' => Decimal::display($s->min_quantity, 18),
            'max_leverage' => Decimal::display($s->max_leverage, 2),
            'contract_size' => Decimal::display($s->contract_size, 18),
        ])]);
    }

    public function prices(Request $request, string $market): JsonResponse
    {
        $symbols = TradingSymbol::active()->whereIn('market_type', $this->marketTypes($market))->with('latestPrice')->orderBy('symbol')->get();

        return response()->json(['data' => $symbols->filter->latestPrice->values()->map(fn (TradingSymbol $s) => [
            'symbol_id' => $s->id,
            'symbol' => $s->symbol,
            'bid' => Decimal::display($s->latestPrice->bid, 18),
            'ask' => Decimal::display($s->latestPrice->ask, 18),
            'price' => Decimal::display($s->latestPrice->price, 18),
            'change_24h' => $s->latestPrice->change_24h,
            'timestamp' => $s->latestPrice->timestamp,
        ])]);
    }

    private function marketTypes(string $market): array
    {
        return $market === 'forex' ? [TradingSymbol::FOREX, TradingSymbol::CFD] : [TradingSymbol::SPOT, TradingSymbol::FUTURES];
    }
}
