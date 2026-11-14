<?php

declare(strict_types=1);

namespace Fsum\Tests;

use Fsum\Accumulator;
use Fsum\Tests\Support\GoldenCorpus;
use Fsum\Tests\Support\Oracle;
use Fsum\Tests\Support\RandomInputs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

use function Fsum\sum;

/**
 * Seeded random inputs against the bcmath oracle: the PHP stand-in for the Go repository's fuzz target.
 */
#[Group('oracle')]
final class DifferentialTest extends TestCase
{
    // Many-partials cases hold about a thousand values each, so fewer of them fit the same time.
    private const SEEDS = ['many-partials' => 5];
    private const DEFAULT_SEEDS = 60;

    public function testTheOracleAgreesWithTheCorpus(): void
    {
        $mismatches = [];
        foreach (GoldenCorpus::load() as $case) {
            $expected = GoldenCorpus::hex($case->expected);
            $actual = GoldenCorpus::hex(Oracle::sum($case->inputs));
            if ($actual !== $expected) {
                $mismatches[] = "{$case->name}: corpus {$expected}, oracle {$actual}";
            }
        }

        self::assertSame([], $mismatches);
    }

    #[DataProvider('classes')]
    public function testRandomInputsMatchTheOracleInAnyOrder(string $class): void
    {
        $mismatches = [];
        for ($seed = 1; $seed <= (self::SEEDS[$class] ?? self::DEFAULT_SEEDS); $seed++) {
            $mismatch = self::check($class, $seed);
            if ($mismatch !== null) {
                $mismatches[] = $mismatch;
            }
        }

        self::assertSame([], $mismatches);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function classes(): array
    {
        return array_combine(RandomInputs::CLASSES, array_map(static fn (string $class): array => [$class], RandomInputs::CLASSES));
    }

    /**
     * Compares sum(), a shuffled sum() and an Accumulator against the oracle; returns a description of any mismatch.
     */
    public static function check(string $class, int $seed): ?string
    {
        $values = (new RandomInputs($seed))->generate($class, $seed % 64 + 1);
        $shuffled = (new Randomizer(new Xoshiro256StarStar($seed)))->shuffleArray($values);
        $accumulator = new Accumulator();
        foreach ($shuffled as $value) {
            $accumulator->add($value);
        }

        $expected = GoldenCorpus::hex(Oracle::sum($values));
        $results = [
            'sum' => GoldenCorpus::hex(sum($values)),
            'shuffled' => GoldenCorpus::hex(sum($shuffled)),
            'accumulator' => GoldenCorpus::hex($accumulator->sum()),
        ];

        $wrong = array_filter($results, static fn (string $actual): bool => $actual !== $expected);

        return $wrong === [] ? null : sprintf('%s seed %d: oracle %s, got %s', $class, $seed, $expected, json_encode($wrong));
    }
}
