<?php

declare(strict_types=1);

namespace Fsum\Tests;

use Fsum\Accumulator;
use Fsum\Tests\Support\GoldenCorpus;
use Fsum\Tests\Support\Oracle;
use Fsum\Tests\Support\RandomInputs;
use Fsum\Tests\Support\Shewchuk;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

use function Fsum\sum;

/**
 * Properties that hold for any engine, checked against the v0.1 one, which shares no code with the superaccumulator
 * and is fast enough for inputs thousands of values long.
 */
final class PropertiesTest extends TestCase
{
    // The engine propagates carries every 2046 adds; these lengths sit on and around the first three passes.
    private const CARRY_LENGTHS = [2045, 2046, 2047, 2048, 4091, 4092, 4093, 6137, 6138, 6139];
    private const CARRY_EXPONENTS = [-992, 0, 992];

    // Many

    public function testNegatingEveryInputNegatesTheResult(): void
    {
        $mismatches = [];
        foreach (GoldenCorpus::load() as $case) {
            // -0.0 and 0.0 do not mirror each other, and NAN has no sign to flip.
            if ($case->expected === 0.0 || is_nan($case->expected)) {
                continue;
            }

            $mismatches[] = self::mismatch("negated {$case->name}", self::negated($case->inputs), -$case->expected);
        }

        self::assertSame([], array_filter($mismatches));
    }

    public function testRandomInputsMatchTheOldEngine(): void
    {
        $mismatches = [];
        foreach (RandomInputs::CLASSES as $class) {
            for ($seed = 1; $seed <= 40; $seed++) {
                $values = (new RandomInputs($seed))->generate($class, $seed % 64 + 1);
                $mismatches[] = self::mismatch("{$class} seed {$seed}", $values, Shewchuk::sum($values));
            }
        }

        self::assertSame([], array_filter($mismatches));
    }

    public function testLongRepeatedInputsMatchTheOldEngine(): void
    {
        $random = new Randomizer(new Xoshiro256StarStar(4097));
        $mismatches = [];
        for ($case = 0; $case < 12; $case++) {
            $values = [];
            while (count($values) < 9000) {
                $x = RandomInputs::fromBits($random->nextInt() << $random->getInt(0, 1));
                array_push($values, ...array_fill(0, $random->getInt(1, 4097), is_finite($x) ? $x : 1.0));
            }

            $values = $random->shuffleArray($values);
            $mismatches[] = self::mismatch("case {$case}", $values, Shewchuk::sum($values));
            $mismatches[] = self::mismatch("case {$case} negated", self::negated($values), Shewchuk::sum(self::negated($values)));
        }

        self::assertSame([], array_filter($mismatches));
    }

    // Boundaries

    public function testSumsAcrossCarryPassesStayExact(): void
    {
        $random = new Randomizer(new Xoshiro256StarStar(2046));
        $mismatches = [];
        foreach (self::CARRY_LENGTHS as $length) {
            foreach (self::CARRY_EXPONENTS as $exponent) {
                $same = array_fill(0, $length, self::carryTop() * 2.0 ** $exponent);
                $mixed = array_map(static fn (float $x): float => $random->getInt(0, 1) === 0 ? $x : -$x, $same);
                $mismatches[] = self::mismatch("{$length} values at 2^{$exponent}", $same, Shewchuk::sum($same));
                $mismatches[] = self::mismatch("{$length} mixed values at 2^{$exponent}", $mixed, Shewchuk::sum($mixed));
            }
        }

        self::assertSame([], array_filter($mismatches));
    }

    #[Group('oracle')]
    public function testSumsAcrossCarryPassesMatchTheOracle(): void
    {
        foreach ([2046, 2047, 4093] as $length) {
            foreach (self::CARRY_EXPONENTS as $exponent) {
                $same = array_fill(0, $length, self::carryTop() * 2.0 ** $exponent);

                self::assertSame(GoldenCorpus::hex(Oracle::sum($same)), GoldenCorpus::hex(sum($same)));
            }
        }
    }

    public function testAnAccumulatorReadAtACarryPassStaysExact(): void
    {
        $readAt = [2045, 2046, 2047, 2048, 4091, 4092, 4093, 4094];
        foreach (self::CARRY_EXPONENTS as $exponent) {
            $x = self::carryTop() * 2.0 ** $exponent;
            $accumulator = new Accumulator();
            $reference = new Shewchuk();
            for ($n = 1; $n <= 4100; $n++) {
                $accumulator->add($x);
                $reference->add($x);
                if (in_array($n, $readAt, true)) {
                    self::assertSame(GoldenCorpus::hex($reference->result()), GoldenCorpus::hex($accumulator->sum()), "exponent {$exponent}, after {$n} values");
                }
            }
        }
    }

    public function testIntsAcrossCarryPassesStayExact(): void
    {
        $mismatches = [];
        foreach ([PHP_INT_MAX, PHP_INT_MIN, (1 << 53) + 1] as $int) {
            foreach ([2046, 2047, 4093] as $length) {
                $values = array_fill(0, $length, $int);
                $mismatches[] = self::mismatch("{$length} times {$int}", $values, Shewchuk::sum($values));

                $values = [...$values, ...array_fill(0, $length, self::carryTop() * 2.0 ** 62), -1, 0.5];
                $mismatches[] = self::mismatch("{$length} times {$int} with floats", $values, Shewchuk::sum($values));
            }
        }

        self::assertSame([], array_filter($mismatches));
    }

    /**
     * A sum of exactly minus one unit of a chunk once lost that chunk in the Go engine: the carry pass folded the
     * sign into the chunk below the tracked range.
     */
    public function testTheDifferenceOfNeighboringFloatsIsExact(): void
    {
        $mismatches = [];
        for ($exponent = 1; $exponent <= 2046; $exponent++) {
            $p = RandomInputs::fromBits($exponent << 52);
            $above = RandomInputs::fromBits(($exponent << 52) + 1);
            $below = RandomInputs::fromBits(($exponent << 52) - 1);
            foreach ([[$p, -$above], [$above, -$p], [$p, -$below], [$p, $p, -$p, -$above]] as $values) {
                $mismatches[] = self::mismatch("exponent {$exponent}", $values, Shewchuk::sum($values));
            }
        }

        self::assertSame([], array_filter($mismatches));
    }

    /**
     * A value, half an ULP that makes a tie, and one bit up to 130 places lower that breaks it, at 64 exponents in
     * a row so the bit lands in every position of every chunk the rounding looks at.
     */
    public function testATieIsBrokenByOneBitAnyDistanceBelow(): void
    {
        $mismatches = [];
        for ($exponent = 1000; $exponent < 1064; $exponent++) {
            $halfUlp = RandomInputs::fromBits(($exponent - 53) << 52);
            foreach ([0, 1] as $odd) {
                $x = RandomInputs::fromBits(($exponent << 52) | $odd);
                for ($distance = 1; $distance <= 130; $distance++) {
                    $bit = $halfUlp * 2.0 ** -$distance;
                    foreach ([[$x, $halfUlp, $bit], [$x, $halfUlp, -$bit], [-$x, -$halfUlp, $bit]] as $values) {
                        $mismatches[] = self::mismatch("exponent {$exponent} odd {$odd} distance {$distance}", $values, Shewchuk::sum($values));
                    }
                }
            }
        }

        self::assertSame([], array_filter($mismatches));
    }

    public function testASumJustPastTheLargestExactRangeIsRounded(): void
    {
        // Below 2^-1021 every sum is a float already. This one is the first that is not: a tie, which goes to even.
        $values = [2.0 ** -1021, PHP_FLOAT_MIN * PHP_FLOAT_EPSILON, 0.0];

        self::assertNull(self::mismatch('first inexact sum', $values, 2.0 ** -1021));
        self::assertNull(self::mismatch('negated', self::negated($values), -(2.0 ** -1021)));
    }

    public function testTwoIntsAreSummedExactlyNotAsTwoRoundedFloats(): void
    {
        // Each int rounds to a float on its own differently than their exact sum does.
        self::assertNull(self::mismatch('two ints', [(1 << 62) + 600, (1 << 62) + 500], 2.0 ** 63 + 2048));
    }

    public function testABlockOfFloatsCountsTowardsTheNextCarryPass(): void
    {
        $x = self::carryTop() * 2.0 ** 64;
        $accumulator = new Accumulator();
        for ($n = 0; $n < 1000; $n++) {
            $accumulator->add($x);
        }

        $accumulator->addFloats(array_fill(0, 1046, $x));
        for ($n = 0; $n < 1000; $n++) {
            $accumulator->add($x);
        }

        self::assertSame(GoldenCorpus::hex(Shewchuk::sum(array_fill(0, 3046, $x))), GoldenCorpus::hex($accumulator->sum()));
    }

    // The largest contribution a value can make to one chunk: a full mantissa at an exponent whose low five bits are
    // all ones.
    private static function carryTop(): float
    {
        return 2.0 - PHP_FLOAT_EPSILON;
    }

    /**
     * @param list<int|float> $values
     *
     * @return list<int|float>
     */
    private static function negated(array $values): array
    {
        return array_map(static fn (int|float $x): int|float => -$x, $values);
    }

    /**
     * Checks sum(), a generator and an Accumulator; returns a description of any that differ from $expected.
     *
     * @param list<int|float> $values
     */
    private static function mismatch(string $name, array $values, float $expected): ?string
    {
        $accumulator = new Accumulator();
        foreach ($values as $value) {
            $accumulator->add($value);
        }

        $results = [
            'sum' => GoldenCorpus::hex(sum($values)),
            'generator' => GoldenCorpus::hex(sum((static fn () => yield from $values)())),
            'accumulator' => GoldenCorpus::hex($accumulator->sum()),
        ];
        $wrong = array_filter($results, static fn (string $actual): bool => $actual !== GoldenCorpus::hex($expected));

        return $wrong === [] ? null : sprintf('%s: expected %s, got %s', $name, GoldenCorpus::hex($expected), json_encode($wrong));
    }
}
