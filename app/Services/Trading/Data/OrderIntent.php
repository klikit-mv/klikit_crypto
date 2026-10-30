<?php

namespace App\Services\Trading\Data;

use App\Models\TradingSymbol;
use App\Models\User;

/**
 * A validated trading instruction from any source (manual, signal or bot). Every source goes
 * through the same risk engine before an order is created.
 */
final readonly class OrderIntent
{
    public function __construct(
        public User $user,
        public TradingSymbol $symbol,
        public string $side,
        public string $type,
        public string $quantity,
        public ?string $price = null,
        public ?string $stopPrice = null,
        public ?string $stopLoss = null,
        public ?string $takeProfit = null,
        public string $leverage = '1',
        public string $mode = 'paper',
        public string $source = 'manual',
        public ?int $exchangeAccountId = null,
        public ?int $brokerAccountId = null,
    ) {}

    public static function fromArray(User $user, TradingSymbol $symbol, array $data, string $source = 'manual'): self
    {
        return new self(
            user: $user,
            symbol: $symbol,
            side: $data['side'],
            type: $data['type'],
            quantity: (string) $data['quantity'],
            price: isset($data['price']) ? (string) $data['price'] : null,
            stopPrice: isset($data['stop_price']) ? (string) $data['stop_price'] : null,
            stopLoss: isset($data['stop_loss']) ? (string) $data['stop_loss'] : null,
            takeProfit: isset($data['take_profit']) ? (string) $data['take_profit'] : null,
            leverage: (string) ($data['leverage'] ?? '1'),
            mode: $data['mode'] ?? 'paper',
            source: $source,
            exchangeAccountId: $data['exchange_account_id'] ?? null,
            brokerAccountId: $data['broker_account_id'] ?? null,
        );
    }
}
