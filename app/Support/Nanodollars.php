<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is stored as integer nanodollars: 1 USD = 1,000,000,000.
 * Pixelforge reports costs with nine decimal places, so this is lossless.
 */
final class Nanodollars
{
    public const PER_DOLLAR = 1_000_000_000;

    public static function fromDecimal(string $usd): int
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,9}))?$/', $usd, $m)) {
            throw new InvalidArgumentException("Not an exact dollar amount: {$usd}");
        }

        $nanos = (int) $m[2] * self::PER_DOLLAR + (int) str_pad($m[3] ?? '', 9, '0');

        return $m[1] === '-' ? -$nanos : $nanos;
    }

    public static function toDecimal(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $nanos = abs($nanos);

        return sprintf('%s%d.%09d', $sign, intdiv($nanos, self::PER_DOLLAR), $nanos % self::PER_DOLLAR);
    }

    /**
     * Applies a percentage markup, rounding any fraction of a nanodollar up.
     */
    public static function withMarkup(int $nanos, int $percent): int
    {
        $scaled = $nanos * (100 + $percent);

        return intdiv($scaled + 99, 100);
    }
}
