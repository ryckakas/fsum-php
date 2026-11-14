<?php

declare(strict_types=1);

/*
 * Runs the differential properties on random seeds until the time budget runs out, the long-run stand-in for Go's
 * `make fuzz`. A failure prints the class and seed, which DifferentialTest::check() replays exactly.
 * Usage: php tools/soak.php [seconds, default 60]
 */

use Fsum\Tests\DifferentialTest;
use Fsum\Tests\Support\RandomInputs;

require __DIR__ . '/../vendor/autoload.php';

function soak(int $seconds): int
{
    $deadline = microtime(true) + $seconds;
    $cases = 0;
    while (microtime(true) < $deadline) {
        $class = RandomInputs::CLASSES[random_int(0, count(RandomInputs::CLASSES) - 1)];
        $mismatch = DifferentialTest::check($class, random_int(1, PHP_INT_MAX));
        $cases++;
        if ($mismatch !== null) {
            fwrite(STDERR, "soak FAILED after {$cases} cases: {$mismatch}\n");

            return 1;
        }
    }

    printf("soak passed: %d random cases in %d s, no mismatch.\n", $cases, $seconds);

    return 0;
}

exit(soak(max(1, (int) ($argv[1] ?? 60))));
