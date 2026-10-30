<?php

namespace App\Support;

final class HashrateFormatter
{
    public static function format(float|int|string|null $hashesPerSecond): string
    {
        $value = (float) $hashesPerSecond;
        foreach (['EH/s' => 1e18, 'PH/s' => 1e15, 'TH/s' => 1e12, 'GH/s' => 1e9, 'MH/s' => 1e6, 'kH/s' => 1e3] as $unit => $size) {
            if ($value >= $size) {
                return number_format($value / $size, 2).' '.$unit;
            }
        }

        return number_format($value, 0).' H/s';
    }
}
