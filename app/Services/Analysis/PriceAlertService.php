<?php

namespace App\Services\Analysis;

use App\Models\MarketPrice;
use App\Models\PriceAlert;
use App\Notifications\PriceAlertTriggered;
use App\Support\Decimal;

class PriceAlertService
{
    public function __construct(
        private readonly CandleService $candles,
        private readonly IndicatorService $indicators,
    ) {}

    /** Evaluate active alerts; returns the number triggered. */
    public function check(): int
    {
        $triggered = 0;
        PriceAlert::where('status', 'active')->with('symbol', 'user')->chunkById(500, function ($alerts) use (&$triggered) {
            foreach ($alerts as $alert) {
                $value = $this->currentValue($alert);
                if ($value === null || ! $this->conditionMet($alert, $value)) {
                    continue;
                }

                $alert->update(['status' => 'triggered', 'triggered_value' => Decimal::str($value), 'triggered_at' => now()]);
                $alert->user->notify(new PriceAlertTriggered($alert));
                $triggered++;
            }
        });

        return $triggered;
    }

    private function currentValue(PriceAlert $alert): ?string
    {
        if (str_starts_with($alert->condition, 'price_')) {
            return MarketPrice::where('symbol_id', $alert->symbol_id)->latest('timestamp')->value('price');
        }

        $rsi = $this->indicators->last($this->indicators->rsi($this->candles->closes($alert->symbol, $alert->interval, 200)));

        return $rsi === null ? null : number_format($rsi, 8, '.', '');
    }

    private function conditionMet(PriceAlert $alert, string $value): bool
    {
        $value = Decimal::of($value);
        $target = Decimal::of($alert->target);

        return str_ends_with($alert->condition, '_above') ? $value->isGreaterThanOrEqualTo($target) : $value->isLessThanOrEqualTo($target);
    }
}
