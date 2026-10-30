<?php

namespace App\Services\Bots\Strategies;

use App\Services\Analysis\IndicatorService;
use App\Services\Bots\StrategyContext;

/**
 * Telegram-signal and manual bots do not generate trades from market data: the signal bot
 * executes through signal subscriptions, the manual bot through user-approved bot signals.
 */
class PassiveStrategy extends AbstractStrategy
{
    public function __construct(private readonly string $strategyType, IndicatorService $indicators)
    {
        parent::__construct($indicators);
    }

    public function type(): string
    {
        return $this->strategyType;
    }

    public function defaults(): array
    {
        return ['interval' => '1h', 'order_amount' => 100];
    }

    public function rules(): array
    {
        return $this->baseRules();
    }

    public function evaluate(StrategyContext $context): array
    {
        return [];
    }
}
