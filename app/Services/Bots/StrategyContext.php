<?php

namespace App\Services\Bots;

use App\Models\TradingSymbol;

/**
 * Everything a strategy may look at. Identical in live runs and backtests, so a strategy
 * behaves the same in both.
 */
final class StrategyContext
{
    /**
     * @param  list<float>  $closes  closed candle closes, oldest first
     * @param  array<string, mixed>  $params  validated strategy parameters
     * @param  array<string, mixed>  $state  strategy memory; strategies may modify it
     */
    public function __construct(
        public readonly TradingSymbol $symbol,
        public readonly array $closes,
        public readonly int $candleTime,
        public readonly float $price,
        public readonly float $holding,
        public readonly float $averageCost,
        public readonly array $params,
        public array $state,
        public readonly int $now,
    ) {}

    public function holds(): bool
    {
        return $this->holding > 0;
    }
}
