<?php

declare(strict_types=1);

namespace Fsum\Tests;

use Fsum\Superaccumulator;
use Fsum\Tests\Support\GoldenCorpus;
use Fsum\Tests\Support\Oracle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * White-box tests of the engine on chunk states, including ones no sum of real floats can reach in a test.
 */
final class SuperaccumulatorTest extends TestCase
{
    private const LAST_CHUNK = Superaccumulator::CHUNKS - 1;

    // Zero

    public function testAnEmptySumRoundsToPositiveZero(): void
    {
        self::assertSame('0000000000000000', GoldenCorpus::hex(Superaccumulator::round(Superaccumulator::zero())));
        self::assertSame(Superaccumulator::zero(), Superaccumulator::normalize(Superaccumulator::zero()));
    }

    // Many: random states

    #[Group('oracle')]
    public function testNormalizeKeepsTheValueAndLeavesSignedDigits(): void
    {
        $random = new Randomizer(new Xoshiro256StarStar(67));
        for ($n = 0; $n < 3000; $n++) {
            $chunk = self::randomChunks($random);
            $normalized = Superaccumulator::normalize($chunk);

            self::assertSame(Oracle::chunkUnits($chunk), Oracle::chunkUnits($normalized));
            for ($i = 0; $i < self::LAST_CHUNK; $i++) {
                self::assertGreaterThanOrEqual(-(1 << 31), $normalized[$i]);
                self::assertLessThan(1 << 31, $normalized[$i]);
            }
        }
    }

    #[Group('oracle')]
    public function testRoundMatchesTheExactValue(): void
    {
        $random = new Randomizer(new Xoshiro256StarStar(1075));
        $mismatches = [];
        for ($n = 0; $n < 6000; $n++) {
            $chunk = self::randomChunks($random);
            $mismatch = self::roundMismatch($chunk);
            if ($mismatch !== null) {
                $mismatches[] = $mismatch;
            }
        }

        self::assertSame([], $mismatches);
    }

    // Boundaries

    /**
     * A sum exactly on, just above and just below a rounding tie, with the deciding bit far below the mantissa,
     * for both signs and for an even and an odd mantissa.
     */
    #[Group('oracle')]
    public function testTiesAreDecidedByStickyBitsFarBelow(): void
    {
        $mismatches = [];
        for ($top = 2; $top < self::LAST_CHUNK; $top += 3) {
            for ($shift = 0; $shift < 32; $shift += 5) {
                foreach (self::tieVariants() as [$sticky, $odd, $sign]) {
                    $chunk = Superaccumulator::zero();
                    $chunk[$top] = $sign << $shift;
                    // The mantissa's last bit sits 52 bits below the leading one, the tie bit 53 below.
                    $chunk[$top - 1] += ($sign * $odd << $shift) >> 20;
                    $chunk[$top - 2] += $sign << $shift << 11;
                    $chunk[0] += $sticky * 2;
                    $mismatch = self::roundMismatch($chunk);
                    if ($mismatch !== null) {
                        $mismatches[] = "top {$top} shift {$shift} sticky {$sticky} odd {$odd} sign {$sign}: {$mismatch}";
                    }
                }
            }
        }

        self::assertSame([], $mismatches);
    }

    public function testTheLastChunkAbsorbsCarriesAndRoundsToInfinity(): void
    {
        foreach ([1, -1] as $sign) {
            $chunk = Superaccumulator::zero();
            $special = null;
            Superaccumulator::addFloats($chunk, [1.5], $special);
            $chunk[self::LAST_CHUNK - 1] += $sign << 62;

            $normalized = Superaccumulator::normalize($chunk);
            self::assertSame($sign << 30, $normalized[self::LAST_CHUNK]);
            self::assertSame($sign * INF, Superaccumulator::round($chunk));

            $normalized[self::LAST_CHUNK] -= $sign << 30;
            self::assertSame(1.5, Superaccumulator::round($normalized));
        }
    }

    public function testACarryRunsThroughEmptyChunksAndStopsAtTheFirstThatAbsorbsIt(): void
    {
        $chunk = Superaccumulator::zero();
        $chunk[3] = (1 << 32) + 5;
        $chunk[4] = (1 << 31) - 1;
        $chunk[9] = -7;
        $chunk[20] = 1 << 30;
        $chunk[self::LAST_CHUNK - 1] = 1 << 32;
        $chunk[self::LAST_CHUNK] = 5;

        $normalized = Superaccumulator::normalize($chunk);

        $expected = Superaccumulator::zero();
        [$expected[3], $expected[4], $expected[5], $expected[9], $expected[20]] = [5, -(1 << 31), 1, -7, 1 << 30];
        $expected[self::LAST_CHUNK] = 6;
        self::assertSame($expected, $normalized);
    }

    public function testSpecialValuesGoToTheFlagAndNegativeZerosAreCounted(): void
    {
        $chunk = Superaccumulator::zero();
        $special = null;

        self::assertSame(2, Superaccumulator::addFloats($chunk, [-0.0, 0.0, INF, -0.0, INF], $special));
        self::assertSame(INF, $special);
        self::assertSame(Superaccumulator::zero(), $chunk);

        self::assertSame(0, Superaccumulator::addFloats($chunk, [-INF], $special));
        self::assertNan($special);
        self::assertSame(0, Superaccumulator::addFloats($chunk, [INF], $special));
        self::assertNan($special);
    }

    /**
     * @return list<array{int, int, int}>
     */
    private static function tieVariants(): array
    {
        $variants = [];
        foreach ([0, 1, -1] as $sticky) {
            foreach ([0, 1] as $odd) {
                array_push($variants, [$sticky, $odd, 1], [$sticky, $odd, -1]);
            }
        }

        return $variants;
    }

    /**
     * A few chunks of random width and sign anywhere in the accumulator, the last chunk included.
     *
     * @return array<int, int>
     */
    private static function randomChunks(Randomizer $random): array
    {
        $chunk = Superaccumulator::zero();
        $base = $random->getInt(0, self::LAST_CHUNK);
        for ($n = $random->getInt(1, 5); $n > 0; $n--) {
            $i = min($base + $random->getInt(0, 5), self::LAST_CHUNK);
            // At most 62 bits: the engine never lets a chunk reach 2^63, where PHP ints stop.
            $c = $random->nextInt() >> $random->getInt(1, 62);
            $chunk[$i] = $random->getInt(0, 1) === 0 ? $c : -$c;
        }

        // Units below 2^-1074 never occur: every float is a multiple of it.
        $chunk[0] &= ~1;

        return $chunk;
    }

    /**
     * @param array<int, int> $chunk
     */
    private static function roundMismatch(array $chunk): ?string
    {
        $expected = GoldenCorpus::hex(Oracle::roundChunks($chunk));
        $actual = GoldenCorpus::hex(Superaccumulator::round($chunk));

        return $actual === $expected ? null : "round {$actual}, exact {$expected}, chunks " . json_encode(array_filter($chunk));
    }
}
