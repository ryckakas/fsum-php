<?php

declare(strict_types=1);

namespace Fsum\Tests;

use Fsum\Accumulator;
use Fsum\Tests\Support\GoldenCorpus;
use PHPUnit\Framework\TestCase;

final class AccumulatorTest extends TestCase
{
    // Simple

    public function testItSumsWhatWasAdded(): void
    {
        $accumulator = new Accumulator();
        foreach ([1e16, 1.0, 1e-16] as $value) {
            $accumulator->add($value);
        }

        self::assertBitsSame(1.0000000000000002e16, $accumulator->sum());
    }

    // Zero

    public function testANewAccumulatorIsAnEmptySum(): void
    {
        self::assertBitsSame(-0.0, (new Accumulator())->sum());
    }

    // Many

    public function testReadingTheSumDoesNotChangeIt(): void
    {
        $accumulator = new Accumulator();
        $accumulator->add(PHP_FLOAT_MAX);
        $accumulator->add(PHP_FLOAT_MAX);

        self::assertBitsSame(INF, $accumulator->sum());
        self::assertBitsSame(INF, $accumulator->sum());

        $accumulator->add(-PHP_FLOAT_MAX);
        self::assertBitsSame(PHP_FLOAT_MAX, $accumulator->sum());
    }

    // Boundaries

    public function testResetForgetsEveryKindOfState(): void
    {
        $accumulator = new Accumulator();
        foreach ([PHP_FLOAT_MAX, PHP_FLOAT_MAX, 1.0, NAN] as $value) {
            $accumulator->add($value);
        }

        $accumulator->reset();
        self::assertBitsSame(-0.0, $accumulator->sum());

        $accumulator->add(0.5);
        self::assertBitsSame(0.5, $accumulator->sum());
    }

    // Interfaces

    public function testACloneIsIndependent(): void
    {
        $original = new Accumulator();
        $original->add(1.0);

        $copy = clone $original;
        $copy->add(2.0);
        $original->add(1e100);
        $original->add(-1e100);

        self::assertBitsSame(1.0, $original->sum());
        self::assertBitsSame(3.0, $copy->sum());
    }

    public function testIntsAddExactly(): void
    {
        $accumulator = new Accumulator();
        $accumulator->add(2 ** 53 + 1);
        $accumulator->add(-(2.0 ** 53));

        self::assertBitsSame(1.0, $accumulator->sum());
    }

    private static function assertBitsSame(float $expected, float $actual): void
    {
        self::assertSame(GoldenCorpus::hex($expected), GoldenCorpus::hex($actual));
    }
}
