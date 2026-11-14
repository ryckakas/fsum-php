<?php

declare(strict_types=1);

/*
 * The README's examples, run by CI as a smoke test of the installed autoloader: it exits non-zero if any result
 * differs from what the README promises. Usage: php examples/example.php
 */

use Fsum\Accumulator;

use function Fsum\sum;

require __DIR__ . '/../vendor/autoload.php';

$accumulator = new Accumulator();
foreach ([1e16, 1, 1e-16] as $value) {
    $accumulator->add($value);
}

$examples = [
    'sum of ten 0.1' => [sum(array_fill(0, 10, 0.1)), 1.0],
    'cancellation' => [sum([1e100, 1, -1e100]), 1.0],
    'keys are ignored' => [sum(['alice' => 0.1, 'bob' => 0.2, 'carol' => 0.3]), 0.6],
    'big ints are exact' => [sum([2 ** 53 + 1, -(2.0 ** 53)]), 1.0],
    'accumulator' => [$accumulator->sum(), 1.0000000000000002e16],
    'intermediate overflow' => [sum([PHP_FLOAT_MAX, PHP_FLOAT_MAX, -PHP_FLOAT_MAX]), PHP_FLOAT_MAX],
];

$failed = false;
foreach ($examples as $name => [$actual, $expected]) {
    $ok = $actual === $expected;
    $failed = $failed || !$ok;
    printf("%-22s %s %s\n", $name, var_export($actual, true), $ok ? 'ok' : 'expected ' . var_export($expected, true));
}

exit($failed ? 1 : 0);
