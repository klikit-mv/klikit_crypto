<?php

namespace App\Services\Bots;

final readonly class TradeDecision
{
    /**
     * @param  string|null  $amountUsd  for buys: USD to spend; sells use $quantity
     */
    public function __construct(
        public string $side,
        public string $reason,
        public ?string $amountUsd = null,
        public ?float $quantity = null,
    ) {}

    public static function buy(string $amountUsd, string $reason): self
    {
        return new self('buy', $reason, $amountUsd);
    }

    public static function sell(float $quantity, string $reason): self
    {
        return new self('sell', $reason, null, $quantity);
    }
}
