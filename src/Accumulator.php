<?php

declare(strict_types=1);

namespace Fsum;

/**
 * An exact running sum with the same semantics as {@see sum()}. Use `clone` for an independent copy.
 */
final class Accumulator
{
    /** @var array<int, int> */
    private array $chunk;
    /** @var list<float> floats not in the chunks yet: reading their bits a block at a time is what keeps add() cheap */
    private array $pending = [];
    // Values added since the chunks were last normalized, pending ones included.
    private int $adds = 0;
    private ?float $special = null;
    // False while every value so far was -0.0, which is exactly when the sum itself is -0.0.
    private bool $sawNonNegativeZero = false;

    public function __construct()
    {
        $this->chunk = Superaccumulator::zero();
    }

    /**
     * Adds a value to the running sum. Ints of any size are added exactly. Unlike sum(), it relies on the native
     * int|float type: without strict_types, PHP first converts numeric strings ("1e400" becomes INF) and bools
     * (true adds 1). Anything else throws a TypeError.
     */
    public function add(int|float $value): void
    {
        if ($this->adds === Superaccumulator::ADDS_PER_NORMALIZE) {
            $this->normalize();
        }

        $this->adds++;
        if (is_float($value)) {
            $this->pending[] = $value;

            return;
        }

        $this->sawNonNegativeZero = true;
        Superaccumulator::addInt($this->chunk, $value);
    }

    /**
     * add() for a block of floats, without a call per value.
     *
     * @internal for Fsum\sum()
     *
     * @param non-empty-list<float> $floats at most Superaccumulator::ADDS_PER_NORMALIZE of them
     */
    public function addFloats(array $floats): void
    {
        if ($this->adds + count($floats) > Superaccumulator::ADDS_PER_NORMALIZE) {
            $this->normalize();
        }

        $this->adds += count($floats);
        $this->put($floats);
    }

    /**
     * Returns the exact sum of every value added so far, rounded once to the nearest float. It does not change
     * the value of the accumulator, so add() can continue afterwards.
     */
    public function sum(): float
    {
        $this->flush();
        if ($this->special !== null) {
            return is_nan($this->special) ? NAN : $this->special;
        }

        if (!$this->sawNonNegativeZero) {
            return -0.0;
        }

        return Superaccumulator::round($this->chunk);
    }

    public function reset(): void
    {
        $this->chunk = Superaccumulator::zero();
        $this->pending = [];
        $this->adds = 0;
        $this->special = null;
        $this->sawNonNegativeZero = false;
    }

    private function normalize(): void
    {
        $this->flush();
        $this->chunk = Superaccumulator::normalize($this->chunk);
        $this->adds = 0;
    }

    private function flush(): void
    {
        if ($this->pending !== []) {
            $pending = $this->pending;
            $this->pending = [];
            $this->put($pending);
        }
    }

    /**
     * @param non-empty-list<float> $floats
     */
    private function put(array $floats): void
    {
        // Emptying the property first leaves the local array the only owner, so the adds do not copy it.
        $chunk = $this->chunk;
        $this->chunk = [];
        if (Superaccumulator::addFloats($chunk, $floats, $this->special) < count($floats)) {
            $this->sawNonNegativeZero = true;
        }

        $this->chunk = $chunk;
    }
}
