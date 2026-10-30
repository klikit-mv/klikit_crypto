<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Exact decimal arithmetic for money and quantities. Never use floats for financial values.
 */
final class Decimal
{
    public const SCALE = 18;

    public static function of(BigDecimal|string|int|null $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        return BigDecimal::of($value === null || $value === '' ? '0' : (string) $value);
    }

    /** Normalised string suitable for DECIMAL(36,18) columns. */
    public static function str(BigDecimal|string|int|null $value, int $scale = self::SCALE): string
    {
        return (string) self::of($value)->toScale($scale, RoundingMode::HALF_UP);
    }

    public static function div(BigDecimal|string|int $a, BigDecimal|string|int $b, int $scale = self::SCALE): BigDecimal
    {
        return self::of($a)->dividedBy(self::of($b), $scale, RoundingMode::HALF_UP);
    }

    public static function mul(BigDecimal|string|int ...$values): BigDecimal
    {
        $result = BigDecimal::one();
        foreach ($values as $value) {
            $result = $result->multipliedBy(self::of($value));
        }

        return $result->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    public static function min(BigDecimal|string $a, BigDecimal|string $b): BigDecimal
    {
        return self::of($a)->isLessThan(self::of($b)) ? self::of($a) : self::of($b);
    }

    /** Human-friendly representation with trailing zeros removed. */
    public static function display(BigDecimal|string|int|null $value, int $maxScale = 8): string
    {
        $str = (string) self::of($value)->toScale($maxScale, RoundingMode::HALF_UP);

        return str_contains($str, '.') ? rtrim(rtrim($str, '0'), '.') : $str;
    }

    /** Fixed-scale amount with thousands separators, e.g. 12,345.60 (no float conversion). */
    public static function money(BigDecimal|string|int|null $value, int $scale = 2): string
    {
        $str = (string) self::of($value)->toScale($scale, RoundingMode::HALF_UP);
        $negative = str_starts_with($str, '-');
        [$int, $frac] = array_pad(explode('.', ltrim($str, '-')), 2, null);
        $int = strrev(implode(',', str_split(strrev($int), 3)));

        return ($negative ? '-' : '').$int.($frac !== null ? '.'.$frac : '');
    }
}
