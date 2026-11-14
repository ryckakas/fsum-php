<?php

declare(strict_types=1);

/*
 * This file is a PHP translation, with Math.sumPrecise semantics, of the polyfill in the TC39 proposal
 * (https://github.com/tc39/proposal-math-sum), which adapts the fsum loop of CPython's Modules/mathmodule.c.
 *
 * CPython: Copyright (c) 2001 Python Software Foundation; All Rights Reserved. Used under the PSF License
 * Agreement, https://docs.python.org/3/license.html
 *
 * The polyfill: BSD 3-Clause License
 *
 * Copyright (c) 2024 Kevin Gibbons
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that
 * the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice, this list of conditions and the
 * following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the
 * following disclaimer in the documentation and/or other materials provided with the distribution.
 *
 * 3. Neither the name of the copyright holder nor the names of its contributors may be used to endorse or
 * promote products derived from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A
 * PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO,
 * PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR
 * OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH
 * DAMAGE.
 */

namespace Fsum\Tests\Support;

/**
 * The v0.1 engine, Shewchuk partials, kept as a test-only reference: it shares no code with the superaccumulator.
 */
final class Shewchuk
{
    private const TWO_1023 = 2.0 ** 1023;
    // The gap between PHP_FLOAT_MAX and the next float below it.
    private const MAX_ULP = 2.0 ** 971;
    // Clearing an int's low 11 bits leaves at most 52 significant bits, so both halves convert exactly.
    private const INT_LOW_BITS = 0x7FF;

    /** @var list<float> non-overlapping, in increasing magnitude; the sum is these plus overflow * 2^1024 */
    private array $partials = [];
    private int $overflow = 0;
    private ?float $special = null;
    // False while every value so far was -0.0, which is exactly when the sum itself is -0.0.
    private bool $sawNonNegativeZero = false;

    /**
     * @param iterable<int|float> $values
     */
    public static function sum(iterable $values): float
    {
        $shewchuk = new self();
        foreach ($values as $value) {
            $shewchuk->add($value);
        }

        return $shewchuk->result();
    }

    public function add(int|float $value): void
    {
        if (is_int($value)) {
            $this->addInt($value);

            return;
        }

        if (is_nan($value) || is_infinite($value)) {
            $this->addSpecial($value);

            return;
        }

        if ($value !== 0.0 || fdiv(1.0, $value) > 0) {
            $this->sawNonNegativeZero = true;
        }

        if ($this->special === null && $value !== 0.0) {
            $this->addFinite($value);
        }
    }

    public function result(): float
    {
        if ($this->special !== null) {
            return is_nan($this->special) ? NAN : $this->special;
        }

        if (!$this->sawNonNegativeZero) {
            return -0.0;
        }

        if ($this->overflow !== 0) {
            return $this->sumWithOverflow();
        }

        return self::roundPartials(0.0, $this->partials);
    }

    private function addInt(int $value): void
    {
        $this->sawNonNegativeZero = true;
        if ($this->special !== null) {
            return;
        }

        $low = $value & self::INT_LOW_BITS;
        $high = $value - $low;
        if ($high !== 0) {
            $this->addFinite((float) $high);
        }

        if ($low !== 0) {
            $this->addFinite((float) $low);
        }
    }

    private function addSpecial(float $value): void
    {
        if ($this->special === null) {
            $this->special = $value;
        } elseif ($this->special !== $value) {
            $this->special = NAN;
        }
    }

    private function addFinite(float $x): void
    {
        // A temporary swaps faster than list destructuring, which builds an array on each swap in this hot loop.
        $kept = [];
        foreach ($this->partials as $y) {
            if (abs($x) < abs($y)) {
                $t = $x;
                $x = $y;
                $y = $t;
            }

            $hi = $x + $y;
            if (is_infinite($hi)) {
                $sign = $hi > 0 ? 1.0 : -1.0;
                $this->overflow += (int) $sign;
                $x = ($x - $sign * self::TWO_1023) - $sign * self::TWO_1023;
                if (abs($x) < abs($y)) {
                    $t = $x;
                    $x = $y;
                    $y = $t;
                }

                $hi = $x + $y;
            }

            $lo = $y - ($hi - $x);
            if ($lo !== 0.0) {
                $kept[] = $lo;
            }

            $x = $hi;
        }

        if ($x !== 0.0) {
            $kept[] = $x;
        }

        $this->partials = $kept;
    }

    // Folds the overflow count into the largest partial, working on halved values so the arithmetic itself cannot
    // overflow.
    private function sumWithOverflow(): float
    {
        $rest = $this->partials;
        $top = array_pop($rest) ?? 0.0;
        if ($this->overflow > 1 || $this->overflow < -1 || ($top !== 0.0 && ($this->overflow > 0) === ($top > 0))) {
            return $this->overflow > 0 ? INF : -INF;
        }

        [$hi, $lo] = self::twoSum($this->overflow * self::TWO_1023, $top / 2);
        $lo *= 2;
        if (is_infinite(2 * $hi)) {
            return self::roundAtMax($hi, $lo, $rest);
        }

        // Doubling is exact, so ($hi, $lo) stays a valid twoSum pair and needs no second pass.
        $hi *= 2;
        if ($lo === 0.0) {
            return self::roundPartials($hi, $rest);
        }

        return self::roundHalfway($hi, $lo, $rest);
    }

    /**
     * Resolves a sum within half an ULP of 2^1024. Exactly half an ULP below rounds to even, which is infinity,
     * unless a smaller partial pulls the sum back below the tie.
     *
     * @param list<float> $rest
     */
    private static function roundAtMax(float $hi, float $lo, array $rest): float
    {
        $sign = $hi > 0 ? 1.0 : -1.0;
        $next = end($rest);
        if ($hi === $sign * self::TWO_1023 && $lo === -$sign * self::MAX_ULP / 2 && $next !== false && ($next < 0) !== ($sign < 0)) {
            return $sign * PHP_FLOAT_MAX;
        }

        return $sign * INF;
    }

    /**
     * Adds partials onto $hi from the largest down, stopping at the first inexact addition.
     *
     * @param list<float> $partials
     */
    private static function roundPartials(float $hi, array $partials): float
    {
        $lo = 0.0;
        while ($partials !== []) {
            [$hi, $lo] = self::twoSum($hi, array_pop($partials));
            if ($lo !== 0.0) {
                break;
            }
        }

        return self::roundHalfway($hi, $lo, $partials);
    }

    /**
     * Corrects $hi when its rounding error $lo is exactly half an ULP: round-half-even may have rounded the wrong
     * way if the next smaller partial has the same sign as $lo.
     *
     * @param list<float> $partials
     */
    private static function roundHalfway(float $hi, float $lo, array $partials): float
    {
        $next = end($partials);
        if ($next === false || $lo === 0.0 || ($lo < 0) !== ($next < 0)) {
            return $hi;
        }

        $y = $lo * 2;
        $x = $hi + $y;

        return $x - $hi === $y ? $x : $hi;
    }

    /**
     * The rounded sum and its exact rounding error. Needs $x === 0.0 or |$x| >= |$y|.
     *
     * @return array{float, float}
     */
    private static function twoSum(float $x, float $y): array
    {
        $hi = $x + $y;

        return [$hi, $y - ($hi - $x)];
    }
}
