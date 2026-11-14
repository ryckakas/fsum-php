<?php

declare(strict_types=1);

namespace Fsum;

use TypeError;

/**
 * Returns the exact sum of the values, rounded once to the nearest float (ties to even). Keys are ignored.
 *
 * It follows JavaScript's Math.sumPrecise: an empty or all -0.0 input sums to -0.0, any NAN or a mix of INF and
 * -INF gives NAN, any other infinite input gives that infinity, and finite values give ±INF only when their exact
 * sum rounds beyond PHP_FLOAT_MAX. Ints of any size are added exactly.
 *
 * @param iterable<mixed, int|float> $values
 *
 * @throws TypeError when a value is neither int nor float; numeric strings are not converted
 */
function sum(iterable $values): float
{
    $few = Superaccumulator::sumUpToTwo($values);
    if ($few !== null) {
        return $few;
    }

    $accumulator = new Accumulator();
    $floats = [];
    foreach ($values as $key => $value) {
        if (is_float($value)) {
            $floats[] = $value;
            if (count($floats) === Superaccumulator::ADDS_PER_NORMALIZE) {
                $accumulator->addFloats($floats);
                $floats = [];
            }
        } elseif (is_int($value)) {
            $accumulator->add($value);
        } else {
            throw new TypeError(sprintf(
                'Fsum\sum(): the value at key %s must be int or float, %s given',
                is_scalar($key) ? var_export($key, true) : get_debug_type($key),
                get_debug_type($value),
            ));
        }
    }

    if ($floats !== []) {
        $accumulator->addFloats($floats);
    }

    return $accumulator->sum();
}
