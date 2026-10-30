<?php

namespace App\Services\Signals;

use App\Models\TradingSymbol;

final readonly class ParsedSignal
{
    /**
     * @param  list<string>  $targets  take-profit prices in order
     */
    public function __construct(
        public TradingSymbol $symbol,
        public string $side,
        public ?string $entry,
        public ?string $entryMax,
        public string $stopLoss,
        public array $targets,
        public string $leverage = '1',
    ) {}

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol->symbol,
            'market_type' => $this->symbol->market_type,
            'side' => $this->side,
            'entry' => $this->entry,
            'entry_max' => $this->entryMax,
            'stop_loss' => $this->stopLoss,
            'targets' => $this->targets,
            'leverage' => $this->leverage,
        ];
    }
}
