<?php

namespace App\Services\Trading\Adapters;

use App\Models\Order;
use App\Models\Position;
use App\Models\TradingSymbol;
use App\Services\Trading\Contracts\BrokerAdapter;
use App\Services\Trading\Contracts\ExchangeAdapter;
use App\Services\Trading\Data\ExecutionResult;
use App\Services\Trading\Data\Fill;
use App\Services\Trading\Data\Ticker;
use App\Services\Trading\MarketData\MarketDataService;
use App\Support\Decimal;
use Illuminate\Support\Carbon;

/**
 * Simulated venue that fills against the latest stored market prices. Market orders fill
 * immediately at the touch (ask for buys, bid for sells); limit orders fill once marketable.
 * Balances live in the platform ledger, so balances() is not used for paper accounts.
 */
class PaperTradingAdapter implements BrokerAdapter, ExchangeAdapter
{
    public function __construct(private readonly MarketDataService $marketData) {}

    public function name(): string
    {
        return 'paper';
    }

    public function ticker(TradingSymbol $symbol): Ticker
    {
        return $this->marketData->ticker($symbol);
    }

    public function placeOrder(Order $order): ExecutionResult
    {
        if ($order->symbol->usesBroker() && ! $this->isMarketOpen($order->symbol)) {
            return ExecutionResult::rejected('Market is closed.');
        }

        return $this->match($order) ?? ExecutionResult::open('paper-'.$order->uuid);
    }

    public function syncOrder(Order $order): ExecutionResult
    {
        return $this->match($order) ?? ExecutionResult::open($order->external_order_id);
    }

    public function cancelOrder(Order $order): bool
    {
        return true;
    }

    public function closePosition(Position $position): Fill
    {
        $ticker = $this->ticker($position->symbol);
        // Closing a long sells at the bid; closing a short buys at the ask.
        $price = $position->side === 'long' ? $ticker->bid : $ticker->ask;

        return $this->fill($position->symbol, $price, $position->quantity);
    }

    public function balances(): array
    {
        return [];
    }

    public function isMarketOpen(TradingSymbol $symbol, ?Carbon $at = null): bool
    {
        if (! $symbol->usesBroker()) {
            return true; // crypto trades 24/7
        }

        $at = ($at ?? now())->copy()->utc();

        // Forex week: Sunday 22:00 UTC to Friday 22:00 UTC.
        return match ($at->dayOfWeek) {
            Carbon::SATURDAY => false,
            Carbon::FRIDAY => $at->hour < 22,
            Carbon::SUNDAY => $at->hour >= 22,
            default => true,
        };
    }

    private function match(Order $order): ?ExecutionResult
    {
        $ticker = $this->ticker($order->symbol);
        $touch = Decimal::of($order->side === 'buy' ? $ticker->ask : $ticker->bid);

        if ($order->type === 'limit') {
            $limit = Decimal::of($order->price);
            $marketable = $order->side === 'buy' ? $touch->isLessThanOrEqualTo($limit) : $touch->isGreaterThanOrEqualTo($limit);
            if (! $marketable) {
                return null;
            }
        }

        $remaining = Decimal::of($order->quantity)->minus(Decimal::of($order->filled_quantity));

        return new ExecutionResult('filled', [$this->fill($order->symbol, $touch, $remaining)], $order->external_order_id ?? 'paper-'.$order->uuid);
    }

    private function fill(TradingSymbol $symbol, $price, $quantity): Fill
    {
        $notional = Decimal::mul($price, $quantity, $symbol->contract_size);
        $fee = Decimal::mul($notional, config('trading.paper.fee_rate'));

        return new Fill(Decimal::str($price), Decimal::str($quantity), Decimal::str($fee), $symbol->quote_currency);
    }
}
