<?php

declare(strict_types=1);

namespace Fsum\Tests\Support;

use RuntimeException;

/**
 * Math.sumPrecise computed with exact arithmetic (bcmath), independent of the library: every value is summed as an
 * integer count of 2^-1074, and PHP's string-to-float cast rounds the exact decimal total once, ties to even.
 */
final class Oracle
{
    private const UNIT_EXPONENT = 1074;

    /** @var array<int, numeric-string> */
    private static array $powersOfTwo = [];

    /** @var numeric-string|null */
    private static ?string $unitInDecimal = null;

    /**
     * @param iterable<int|float> $values
     */
    public static function sum(iterable $values): float
    {
        $special = null;
        $allNegativeZero = true;
        $units = '0';
        foreach ($values as $value) {
            if (is_float($value) && (is_nan($value) || is_infinite($value))) {
                $special = $special === null || $special === $value ? $value : NAN;

                continue;
            }

            $allNegativeZero = $allNegativeZero && is_float($value) && $value === 0.0 && fdiv(1.0, $value) < 0;
            $units = bcadd($units, self::units($value));
        }

        if ($special !== null) {
            return is_nan($special) ? NAN : $special;
        }

        if ($allNegativeZero) {
            return -0.0;
        }

        return (float) self::toDecimal($units);
    }

    /**
     * The value as an exact integer multiple of 2^-1074, the smallest float.
     *
     * @return numeric-string
     */
    private static function units(int|float $value): string
    {
        if (is_int($value)) {
            return bcmul((string) $value, self::powerOfTwo(self::UNIT_EXPONENT));
        }

        $unpacked = unpack('J', pack('E', $value));
        if ($unpacked === false || !is_int($unpacked[1] ?? null)) {
            throw new RuntimeException('cannot read the bits of a float');
        }

        $bits = $unpacked[1];
        $biasedExponent = ($bits >> 52) & 0x7FF;
        $mantissa = $bits & 0xFFFFFFFFFFFFF;
        if ($biasedExponent !== 0) {
            $mantissa |= 1 << 52;
        }

        $units = bcmul((string) $mantissa, self::powerOfTwo(max($biasedExponent, 1) - 1));

        return $bits < 0 ? bcmul($units, '-1') : $units;
    }

    /**
     * n * 2^-1074 = n * 5^1074 / 10^1074: an exact decimal with 1074 digits after the point.
     *
     * @param numeric-string $units
     *
     * @return numeric-string
     */
    private static function toDecimal(string $units): string
    {
        self::$unitInDecimal ??= bcpow('5', (string) self::UNIT_EXPONENT);

        return bcdiv(bcmul($units, self::$unitInDecimal), bcpow('10', (string) self::UNIT_EXPONENT), self::UNIT_EXPONENT);
    }

    /**
     * @return numeric-string
     */
    private static function powerOfTwo(int $exponent): string
    {
        return self::$powersOfTwo[$exponent] ??= bcpow('2', (string) $exponent);
    }
}
