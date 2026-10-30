<?php

namespace App\Services\Bots;

interface Strategy
{
    public function type(): string;

    /** Candle interval the strategy works on. */
    public function interval(array $params): string;

    /** Default parameters shown in the UI. */
    public function defaults(): array;

    /** Laravel validation rules for the parameters. */
    public function rules(): array;

    /** @return list<TradeDecision> */
    public function evaluate(StrategyContext $context): array;
}
