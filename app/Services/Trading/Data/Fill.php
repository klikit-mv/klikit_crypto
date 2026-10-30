<?php

namespace App\Services\Trading\Data;

final readonly class Fill
{
    public function __construct(
        public string $price,
        public string $quantity,
        public string $fee,
        public string $feeCurrency,
        public ?string $externalTradeId = null,
    ) {}
}
