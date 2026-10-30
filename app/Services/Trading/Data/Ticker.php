<?php

namespace App\Services\Trading\Data;

final readonly class Ticker
{
    public function __construct(
        public string $symbol,
        public string $bid,
        public string $ask,
        public string $last,
        public ?string $change24h = null,
        public ?string $volume24h = null,
    ) {}
}
