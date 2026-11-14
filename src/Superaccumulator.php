<?php

declare(strict_types=1);

/*
 * The superaccumulator in this file is based on xsum by Radford M. Neal (https://gitlab.com/radfordneal/xsum): the
 * chunk layout and the carry-free add are his. Carry propagation and rounding follow github.com/ryckakas/fsum.
 *
 * Copyright 2015, 2018, 2021, 2024 Radford M. Neal
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
 * documentation files (the "Software"), to deal in the Software without restriction, including without
 * limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the
 * Software, and to permit persons to whom the Software is furnished to do so, subject to the following
 * conditions:
 *
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions
 * of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED
 * TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF
 * CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 */

namespace Fsum;

/**
 * An exact sum of finite floats and ints, held as 67 ints: the sum of chunk[i] * 2^(32i-1075). Chunks overlap by
 * 32 bits, so an add touches two of them and never carries. Ported from xsum.go of github.com/ryckakas/fsum.
 *
 * @internal
 */
final class Superaccumulator
{
    public const CHUNKS = 67;
    // One add changes a chunk by less than 2^52 and a normalized chunk is below 2^32 in magnitude, so this many
    // adds cannot overflow an int, which PHP would silently turn into a float.
    public const ADDS_PER_NORMALIZE = 2046;

    private const LAST_CHUNK = self::CHUNKS - 1;
    private const MANTISSA_MASK = (1 << 52) - 1;
    private const IMPLICIT_BIT = 1 << 52;
    private const EXPONENT_MASK = 0x7FF;
    private const LOW_MASK = (1 << 32) - 1;
    // 1 is 2^19 units of chunk 33, so an int splits into its low 13 bits there and the rest one chunk up.
    private const INT_CHUNK = 33;
    private const INT_LOW_BITS = 13;

    /**
     * @return array<int, int>
     */
    public static function zero(): array
    {
        return array_fill(0, self::CHUNKS, 0);
    }

    /**
     * Up to two floats need no accumulator: one IEEE addition is already the exact sum rounded once. -0.0 stands
     * in for a missing value, because adding it changes nothing, not even the sign of a zero. Null for other input.
     *
     * @param iterable<mixed> $values
     */
    public static function sumUpToTwo(iterable $values): ?float
    {
        if (!is_array($values) || count($values) > 2) {
            return null;
        }

        [$x, $y] = [...array_values($values), -0.0, -0.0];
        if (!is_float($x) || !is_float($y)) {
            return null;
        }

        return is_nan($x + $y) ? NAN : $x + $y;
    }

    /**
     * Adds floats to $chunk and returns how many of them were -0.0. NAN and infinities go to $special instead.
     * The caller counts the adds and normalizes in time. PHP reads float bits fastest a whole block at a time.
     *
     * @param array<int, int> $chunk
     * @param non-empty-list<float> $floats
     */
    public static function addFloats(array &$chunk, array $floats, ?float &$special): int
    {
        /** @var array<int, int> $bits */
        $bits = unpack('q*', pack('d*', ...$floats));
        $negativeZeros = 0;
        foreach ($bits as $b) {
            $exponent = ($b >> 52) & self::EXPONENT_MASK;
            if ($exponent === 0 || $exponent === self::EXPONENT_MASK) {
                $negativeZeros += self::addRare($chunk, $b, $special);

                continue;
            }

            $mantissa = ($b & self::MANTISSA_MASK) | self::IMPLICIT_BIT;
            $shift = $exponent & 31;
            $i = $exponent >> 5;
            if ($b >= 0) {
                $chunk[$i] += ($mantissa << $shift) & self::LOW_MASK;
                $chunk[$i + 1] += $mantissa >> (32 - $shift);
            } else {
                $chunk[$i] -= ($mantissa << $shift) & self::LOW_MASK;
                $chunk[$i + 1] -= $mantissa >> (32 - $shift);
            }
        }

        return $negativeZeros;
    }

    /**
     * @param array<int, int> $chunk
     */
    public static function addInt(array &$chunk, int $value): void
    {
        $chunk[self::INT_CHUNK] += ($value & ((1 << self::INT_LOW_BITS) - 1)) << (32 - self::INT_LOW_BITS);
        $chunk[self::INT_CHUNK + 1] += $value >> self::INT_LOW_BITS;
    }

    /**
     * Propagates carries upward. Afterwards every chunk but the last is a signed digit in [-2^31, 2^31), so the
     * highest non-zero chunk has the sign of the sum, and a carry stops at the first empty chunk. The last chunk
     * has nowhere to carry to: it absorbs, and stays exact far beyond any reachable input.
     *
     * @param array<int, int> $chunk
     *
     * @return array<int, int>
     */
    public static function normalize(array $chunk): array
    {
        // array_filter finds the non-zero chunks in C, so a sum of a few values skips the chunks nothing touched.
        $used = array_keys(array_filter($chunk));
        $next = 0;
        $i = $used[0] ?? self::LAST_CHUNK;
        $carry = 0;
        while ($i < self::LAST_CHUNK) {
            $c = $chunk[$i] + $carry;
            $digit = $c & self::LOW_MASK;
            if ($digit >= 1 << 31) {
                $digit -= 1 << 32;
            }

            $chunk[$i] = $digit;
            $carry = ($c - $digit) >> 32;
            $i++;
            if ($carry === 0) {
                while (($used[$next] ?? self::LAST_CHUNK) < $i) {
                    $next++;
                }

                $i = $used[$next] ?? self::LAST_CHUNK;
            }
        }

        $chunk[self::LAST_CHUNK] += $carry;

        return $chunk;
    }

    /**
     * Returns the sum rounded to the nearest float, ties to even; +0.0 for a zero sum. Only the magnitude is
     * rounded, so the two signs cannot disagree.
     *
     * @param array<int, int> $chunk
     */
    public static function round(array $chunk): float
    {
        $chunk = self::normalize($chunk);
        $used = array_keys(array_filter($chunk));
        if ($used === []) {
            return 0.0;
        }

        $top = $used[count($used) - 1];
        $sign = $chunk[$top] < 0 ? -1 : 1;
        if ($top === self::LAST_CHUNK) {
            return $sign * INF;
        }

        [$digits, $sticky] = self::magnitudeWindow($chunk, $used, $top, $sign);

        return $sign * self::roundMagnitude($digits, $sticky, $top);
    }

    /**
     * @param array<int, int> $chunk
     */
    private static function addRare(array &$chunk, int $b, ?float &$special): int
    {
        if (($b >> 52) & self::EXPONENT_MASK) {
            $x = ($b & self::MANTISSA_MASK) !== 0 ? NAN : ($b > 0 ? INF : -INF);
            $special = $special === null || $special === $x ? $x : NAN;

            return 0;
        }

        if ($b === PHP_INT_MIN) {
            return 1;
        }

        // A subnormal: no implicit bit, and the exponent of the smallest normal.
        $mantissa = ($b & self::MANTISSA_MASK) << 1;
        if ($b >= 0) {
            $chunk[0] += $mantissa & self::LOW_MASK;
            $chunk[1] += $mantissa >> 32;
        } else {
            $chunk[0] -= $mantissa & self::LOW_MASK;
            $chunk[1] -= $mantissa >> 32;
        }

        return 0;
    }

    /**
     * Converts the signed digits from $top - 4 to $top into unsigned digits of sign * sum, lowest first, and
     * reports whether anything below them is non-zero. What lies below matters only through its sign: a negative
     * remainder borrows one from the lowest digit.
     *
     * @param array<int, int> $chunk normalized
     * @param non-empty-list<int> $used the indexes of the non-zero chunks, ascending
     *
     * @return array{list<int>, bool}
     */
    private static function magnitudeWindow(array $chunk, array $used, int $top, int $sign): array
    {
        $k = count($used) - 1;
        while ($k >= 0 && $used[$k] > $top - 5) {
            $k--;
        }

        $sticky = $k >= 0;
        $borrow = $sticky && $sign * $chunk[$used[$k]] < 0 ? 1 : 0;
        $digits = [];
        for ($i = $top - 4; $i <= $top; $i++) {
            $digit = $sign * ($chunk[$i] ?? 0) - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $digits[] = $digit + ($borrow << 32);
        }

        return [$digits, $sticky];
    }

    /**
     * Rounds a positive magnitude whose highest non-zero chunk is $top. PHP has no unsigned 64-bit int, so this
     * works on the top 62 bits where the Go code takes 64.
     *
     * @param list<int> $digits
     */
    private static function roundMagnitude(array $digits, bool $sticky, int $top): float
    {
        [$w0, $w1, $w2, $w3, $w4] = $digits;
        // A borrow can empty the top digit: the magnitude then starts one chunk lower.
        if ($w4 === 0) {
            $top--;
            [$w0, $w1, $w2, $w3, $w4] = [0, $w0, $w1, $w2, $w3];
        }

        // Inputs are multiples of 2^-1074, so a sum below 2^-1021 is a float already: no rounding.
        if ($top === 0 || ($top === 1 && $w4 < 1 << 22)) {
            return self::fromBits(($top === 0 ? $w4 : ($w4 << 32) | $w3) >> 1);
        }

        $length = strlen(decbin($w4));
        $middle = ($w3 << 30) | ($w2 >> 2);
        $frac = ($w4 << (62 - $length)) | ($middle >> $length);
        $sticky = $sticky || ($middle & ((1 << $length) - 1)) !== 0 || ($w2 & 3) !== 0 || $w1 !== 0 || $w0 !== 0;

        $mantissa = $frac >> 9;
        $rest = $frac & 0x1FF;
        if ($rest > 0x100 || ($rest === 0x100 && ($sticky || ($mantissa & 1) === 1))) {
            $mantissa++;
        }

        $exponent = 32 * $top + $length - 53;
        if ($exponent >= self::EXPONENT_MASK) {
            return INF;
        }

        // A mantissa that rounded up to 2^53 carries into the exponent by itself, up to the bits of INF.
        return self::fromBits(($exponent << 52) + ($mantissa - self::IMPLICIT_BIT));
    }

    private static function fromBits(int $bits): float
    {
        /** @var array{1: float} $unpacked */
        $unpacked = unpack('d', pack('q', $bits));

        return $unpacked[1];
    }
}
