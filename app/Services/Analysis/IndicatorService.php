<?php

namespace App\Services\Analysis;

/**
 * Technical indicators. These are analytics, not money, so floats are acceptable here;
 * never feed indicator output back into balances or order quantities without rounding
 * through App\Support\Decimal.
 *
 * Every function returns an array aligned with the input (null where not yet defined).
 */
class IndicatorService
{
    /**
     * @param  list<float>  $values
     * @return list<?float>
     */
    public function sma(array $values, int $period): array
    {
        $out = [];
        $sum = 0.0;
        foreach ($values as $i => $value) {
            $sum += (float) $value;
            if ($i >= $period) {
                $sum -= (float) $values[$i - $period];
            }
            $out[] = $i >= $period - 1 ? $sum / $period : null;
        }

        return $out;
    }

    /**
     * Exponential moving average seeded with the SMA of the first $period values.
     *
     * @param  list<?float>  $values  leading nulls are skipped
     * @return list<?float>
     */
    public function ema(array $values, int $period): array
    {
        $out = array_fill(0, count($values), null);
        $k = 2 / ($period + 1);
        $seed = [];
        $prev = null;

        foreach ($values as $i => $value) {
            if ($value === null) {
                continue;
            }
            if ($prev === null) {
                $seed[] = (float) $value;
                if (count($seed) === $period) {
                    $prev = array_sum($seed) / $period;
                    $out[$i] = $prev;
                }

                continue;
            }
            $prev = ($value - $prev) * $k + $prev;
            $out[$i] = $prev;
        }

        return $out;
    }

    /**
     * Relative Strength Index with Wilder's smoothing.
     *
     * @param  list<float>  $closes
     * @return list<?float>
     */
    public function rsi(array $closes, int $period = 14): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        if ($n <= $period) {
            return $out;
        }

        $gain = $loss = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gain += max($change, 0);
            $loss += max(-$change, 0);
        }
        $avgGain = $gain / $period;
        $avgLoss = $loss / $period;
        $out[$period] = $this->rsiValue($avgGain, $avgLoss);

        for ($i = $period + 1; $i < $n; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $avgGain = ($avgGain * ($period - 1) + max($change, 0)) / $period;
            $avgLoss = ($avgLoss * ($period - 1) + max(-$change, 0)) / $period;
            $out[$i] = $this->rsiValue($avgGain, $avgLoss);
        }

        return $out;
    }

    /**
     * @param  list<float>  $closes
     * @return array{macd: list<?float>, signal: list<?float>, histogram: list<?float>}
     */
    public function macd(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        $fastEma = $this->ema($closes, $fast);
        $slowEma = $this->ema($closes, $slow);
        $macd = array_map(fn ($f, $s) => $f !== null && $s !== null ? $f - $s : null, $fastEma, $slowEma);
        $signalLine = $this->ema($macd, $signal);
        $histogram = array_map(fn ($m, $s) => $m !== null && $s !== null ? $m - $s : null, $macd, $signalLine);

        return ['macd' => $macd, 'signal' => $signalLine, 'histogram' => $histogram];
    }

    /** Last non-null value of a series. */
    public function last(array $series): ?float
    {
        for ($i = count($series) - 1; $i >= 0; $i--) {
            if ($series[$i] !== null) {
                return $series[$i];
            }
        }

        return null;
    }

    private function rsiValue(float $avgGain, float $avgLoss): float
    {
        if ($avgLoss == 0.0) {
            return $avgGain == 0.0 ? 50.0 : 100.0;
        }

        return 100 - 100 / (1 + $avgGain / $avgLoss);
    }
}
