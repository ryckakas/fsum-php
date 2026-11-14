<?php

declare(strict_types=1);

namespace Fsum\Tests;

use ArrayIterator;
use Closure;
use Fsum\Accumulator;
use Fsum\Tests\Support\GoldenCorpus;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use TypeError;

use function Fsum\sum;

final class SumTest extends TestCase
{
    // Simple

    public function testTenTenthsSumToExactlyOne(): void
    {
        self::assertBitsSame(1.0, sum(array_fill(0, 10, 0.1)));
    }

    // Many: every corpus case, through every way in

    /**
     * @param Closure(list<float>): float $sum
     */
    #[DataProvider('entryPoints')]
    public function testEveryCorpusCaseMatches(Closure $sum): void
    {
        $mismatches = [];
        foreach (GoldenCorpus::load() as $case) {
            $expected = GoldenCorpus::hex($case->expected);
            $actual = GoldenCorpus::hex($sum($case->inputs));
            if ($actual !== $expected) {
                $mismatches[] = "{$case->name}: expected {$expected}, got {$actual}";
            }
        }

        self::assertSame([], $mismatches);
    }

    /**
     * @return array<string, array{Closure(list<float>): float}>
     */
    public static function entryPoints(): array
    {
        return [
            'sum' => [sum(...)],
            'generator' => [self::sumGenerated(...)],
            'reversed' => [self::sumReversed(...)],
            'accumulator read midway' => [self::accumulateReadingMidway(...)],
        ];
    }

    // Boundaries: an overflowed sum just past the tie at PHP_FLOAT_MAX + half an ULP

    public function testASumPastTheTieAtMaxRoundsToInfinityEvenWithASmallerPartialBelow(): void
    {
        // Exact sum: 2^1024 - 2^969 - 1, above the tie at 2^1024 - 2^970.
        self::assertBitsSame(INF, sum([2.0 ** 1023, 2.0 ** 1023, -(2.0 ** 969), -1.0]));
        self::assertBitsSame(-INF, sum([-(2.0 ** 1023), -(2.0 ** 1023), 2.0 ** 969, 1.0]));
    }

    // Interfaces: ints are summed exactly, at any size

    public function testIntsAboveTwoToThe53AreNotRoundedBeforeSumming(): void
    {
        self::assertBitsSame(1.0, sum([2 ** 53 + 1, -(2 ** 53)]));
        self::assertBitsSame(-1.0, sum([PHP_INT_MIN, PHP_INT_MAX]));
        self::assertBitsSame(-1.0, sum([-(2 ** 53) - 1, 2 ** 53]));
        self::assertBitsSame(0.0, sum([PHP_INT_MAX, -PHP_INT_MAX]));
    }

    public function testExtremeIntsRoundOnce(): void
    {
        self::assertBitsSame(2.0 ** 63, sum([PHP_INT_MAX]));
        self::assertBitsSame(-(2.0 ** 63), sum([PHP_INT_MIN]));
        self::assertBitsSame(-1.0, sum([PHP_INT_MAX, -(2.0 ** 63)]));
    }

    public function testIntsAndFloatsMix(): void
    {
        self::assertBitsSame(1.5, sum([1, 0.5]));
        self::assertBitsSame(0.0, sum([-0.0, 0]));
        self::assertBitsSame(INF, sum([1, INF, 2]));
        self::assertBitsSame(NAN, sum([INF, 3, -INF]));
    }

    public function testKeysAreIgnored(): void
    {
        self::assertBitsSame(0.6, sum(['a' => 0.1, 'b' => 0.2, 'c' => 0.3]));
        self::assertBitsSame(0.6, sum(new ArrayIterator([7 => 0.3, 3 => 0.2, 5 => 0.1])));
    }

    // Exceptions

    public function testANumericStringIsRejectedNotConverted(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage("the value at key 'b' must be int or float, string given");

        sum(['a' => 1.0, 'b' => '1.5']); // @phpstan-ignore argument.type (the invalid value is the point)
    }

    public function testNullBoolAndObjectsAreRejected(): void
    {
        foreach ([null, true, new stdClass()] as $value) {
            try {
                sum([1.0, $value]); // @phpstan-ignore argument.type (the invalid value is the point)
                self::fail('expected a TypeError for ' . get_debug_type($value));
            } catch (TypeError $error) {
                self::assertStringContainsString('at key 1 must be int or float, ' . get_debug_type($value), $error->getMessage());
            }
        }
    }

    public function testANonScalarKeyIsNamedByItsType(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('the value at key stdClass must be int or float, string given');

        $values = (static function (): Generator {
            yield new stdClass() => 'x';
        })();

        sum($values); // @phpstan-ignore argument.type (the invalid value is the point)
    }

    /**
     * @param list<float> $values
     */
    private static function sumGenerated(array $values): float
    {
        return sum(self::yieldAll($values));
    }

    /**
     * @param list<float> $values
     */
    private static function sumReversed(array $values): float
    {
        return sum(array_reverse($values));
    }

    /**
     * @param list<float> $values
     */
    private static function accumulateReadingMidway(array $values): float
    {
        $accumulator = new Accumulator();
        foreach ($values as $i => $value) {
            $accumulator->add($value);
            if ($i === intdiv(count($values), 2)) {
                $accumulator->sum();
            }
        }

        return $accumulator->sum();
    }

    /**
     * @param list<float> $values
     *
     * @return Generator<int, float>
     */
    private static function yieldAll(array $values): Generator
    {
        yield from $values;
    }

    // Compares bit patterns, so -0.0 differs from 0.0 and a NaN result must be the canonical NAN.
    private static function assertBitsSame(float $expected, float $actual): void
    {
        self::assertSame(GoldenCorpus::hex($expected), GoldenCorpus::hex($actual));
    }
}
