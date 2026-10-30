<?php

namespace App\Services\Trading\Contracts;

use App\Models\Order;
use App\Models\Position;
use App\Models\TradingSymbol;
use App\Services\Trading\Data\ExecutionResult;
use App\Services\Trading\Data\Fill;
use App\Services\Trading\Data\Ticker;

/**
 * Common contract for every execution venue. Crypto exchanges and Forex brokers share it,
 * but are implemented by separate adapters because their order types, market hours,
 * leverage and execution rules differ.
 */
interface TradingVenueAdapter
{
    public function name(): string;

    public function ticker(TradingSymbol $symbol): Ticker;

    /** Submit a new order. Must be safe to retry with the same Order (use its uuid as client id). */
    public function placeOrder(Order $order): ExecutionResult;

    /** Re-check an open order (used for limit orders and reconciliation). */
    public function syncOrder(Order $order): ExecutionResult;

    public function cancelOrder(Order $order): bool;

    /** Close an open margined position at market; returns the closing fill. */
    public function closePosition(Position $position): Fill;

    /** @return array<string, string> currency => total balance */
    public function balances(): array;
}
