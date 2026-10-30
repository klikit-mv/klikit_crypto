<?php

namespace App\Services\Bots;

use App\Services\Analysis\IndicatorService;
use App\Services\Bots\Strategies\DcaStrategy;
use App\Services\Bots\Strategies\GridStrategy;
use App\Services\Bots\Strategies\MacdStrategy;
use App\Services\Bots\Strategies\PassiveStrategy;
use App\Services\Bots\Strategies\RsiStrategy;
use App\Services\Bots\Strategies\ScalpingStrategy;
use App\Services\Bots\Strategies\TrendStrategy;
use InvalidArgumentException;

class StrategyRegistry
{
    /** @var array<string, Strategy> */
    private array $strategies;

    public function __construct(IndicatorService $indicators)
    {
        foreach ([
            new GridStrategy($indicators), new DcaStrategy($indicators), new ScalpingStrategy($indicators),
            new TrendStrategy($indicators), new RsiStrategy($indicators), new MacdStrategy($indicators),
            new PassiveStrategy('telegram_signal', $indicators), new PassiveStrategy('manual', $indicators),
        ] as $strategy) {
            $this->strategies[$strategy->type()] = $strategy;
        }
    }

    public function get(string $type): Strategy
    {
        return $this->strategies[$type] ?? throw new InvalidArgumentException("Unknown strategy [$type].");
    }

    /** @return array<string, Strategy> */
    public function all(): array
    {
        return $this->strategies;
    }

    /** Strategies that generate trades from market data (and can be backtested). */
    public function automated(): array
    {
        return array_filter($this->strategies, fn (Strategy $s) => ! $s instanceof PassiveStrategy);
    }
}
