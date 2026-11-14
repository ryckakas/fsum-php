<?php

declare(strict_types=1);

namespace Fsum\Tests\Support;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use RuntimeException;

/**
 * Seeded random inputs in the classes of the Go repository's corpus generator, plus PHP's large ints.
 */
final class RandomInputs
{
    public const CLASSES = ['uniform', 'wide', 'cancel', 'subnormal', 'near-overflow', 'ties', 'specials', 'many-partials', 'ints'];

    private readonly Randomizer $random;

    public function __construct(int $seed)
    {
        $this->random = new Randomizer(new Xoshiro256StarStar($seed));
    }

    /**
     * @return list<int|float>
     */
    public function generate(string $class, int $count): array
    {
        return match ($class) {
            'uniform' => $this->repeat($count, fn (): float => $this->unit()),
            'wide' => $this->repeat($count, fn (): float => $this->float(1, 2046)),
            'cancel' => $this->cancel($count),
            'subnormal' => $this->repeat($count, fn (): float => $this->signed(self::fromBits($this->random->getInt(1, (1 << 40) - 1)))),
            'near-overflow' => $this->nearOverflow($count),
            'ties' => $this->ties($count),
            'specials' => $this->specials($count),
            'many-partials' => $this->manyPartials(),
            'ints' => $this->repeat($count, fn (): int|float => $this->random->getInt(0, 3) === 0 ? $this->float(1000, 1100) : $this->random->nextInt() * ($this->random->getInt(0, 1) * 2 - 1)),
            default => throw new RuntimeException("unknown class {$class}"),
        };
    }

    public static function fromBits(int $bits): float
    {
        $unpacked = unpack('E', pack('J', $bits));
        if ($unpacked === false || !is_float($unpacked[1] ?? null)) {
            throw new RuntimeException('cannot build a float from bits');
        }

        return $unpacked[1];
    }

    /**
     * @template T
     *
     * @param callable(): T $next
     *
     * @return list<T>
     */
    private function repeat(int $count, callable $next): array
    {
        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $values[] = $next();
        }

        return $values;
    }

    private function unit(): float
    {
        return $this->random->getInt(0, (1 << 53) - 1) / 2 ** 53;
    }

    // A random finite float whose biased exponent lies in [$minExponent, $maxExponent], with a random sign.
    private function float(int $minExponent, int $maxExponent): float
    {
        $bits = ($this->random->getInt($minExponent, $maxExponent) << 52) | $this->random->getInt(0, (1 << 52) - 1);

        return $this->signed(self::fromBits($bits));
    }

    private function signed(float $value): float
    {
        return $this->random->getInt(0, 1) === 0 ? $value : -$value;
    }

    /**
     * Large values that cancel exactly, hiding a residual far below their precision.
     *
     * @return list<float>
     */
    private function cancel(int $count): array
    {
        $half = $this->repeat(intdiv($count, 2), fn (): float => $this->float(1023 - 60, 1023 + 60));
        $residual = $this->repeat(max(1, $count - 2 * count($half)), fn (): float => $this->float(1023 - 120, 1023 - 60));

        return $this->random->shuffleArray([...$half, ...array_map(static fn (float $x): float => -$x, $half), ...$residual]);
    }

    /**
     * Half the time the sum overflows for real; otherwise near-cancelling pairs overflow only a naive running sum.
     *
     * @return list<float>
     */
    private function nearOverflow(int $count): array
    {
        if ($this->random->getInt(0, 1) === 0) {
            return $this->repeat($count, fn (): float => $this->signed($this->fractionOfMax(300)));
        }

        $values = [];
        while (count($values) < $count) {
            $x = $this->signed($this->fractionOfMax(500));
            array_push($values, $x, -$x * $this->random->getInt(980, 1000) / 1000);
        }

        $values = array_slice($values, 0, $count);
        usort($values, static fn (float $a, float $b): int => ($a < 0) <=> ($b < 0));

        return $values;
    }

    private function fractionOfMax(int $minPerMille): float
    {
        return PHP_FLOAT_MAX * ($this->random->getInt($minPerMille, 1000) / 1000);
    }

    /**
     * A value, a half-ULP nudge that makes a tie, and tiny values far below that may break it.
     *
     * @return list<float>
     */
    private function ties(int $count): array
    {
        $exponent = $this->random->getInt(1023 - 200, 1023 + 200);
        $halfUlp = self::fromBits(($exponent - 53) << 52);
        $values = [$this->float($exponent, $exponent), $this->signed($halfUlp)];
        while (count($values) < $count) {
            $values[] = $this->signed($halfUlp * 2.0 ** -$this->random->getInt(8, 140));
        }

        return $values;
    }

    /**
     * @return list<float>
     */
    private function specials(int $count): array
    {
        $values = $this->repeat($count, fn (): float => $this->float(1023 - 100, 1023 + 100));
        $specials = [];
        for ($i = $this->random->getInt(1, 3); $i > 0; $i--) {
            $specials[$this->random->getInt(0, $count - 1)] = [NAN, INF, -INF, -0.0, 0.0][$this->random->getInt(0, 4)];
        }

        return array_values(array_replace($values, $specials));
    }

    /**
     * Values spaced a few binades apart, so the running sum keeps hundreds of partials.
     *
     * @return list<float>
     */
    private function manyPartials(): array
    {
        $values = [];
        for ($exponent = 2046; $exponent > 1; $exponent -= $this->random->getInt(1, 3)) {
            $values[] = $this->signed(self::fromBits(($exponent << 52) | $this->random->getInt(0, (1 << 52) - 1) | 1));
        }

        return $this->random->shuffleArray($values);
    }
}
