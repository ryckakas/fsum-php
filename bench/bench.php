<?php

declare(strict_types=1);

/*
 * Times Fsum\sum() against array_sum, a naive loop, Kahan and Neumaier on the Go repository's benchmark classes,
 * and prints a Markdown table. Usage: php bench/bench.php [count, default 1000000]
 * Compare with the JIT: php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M ...
 */

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

use function Fsum\sum;

require __DIR__ . '/../vendor/autoload.php';

/**
 * @param list<float> $values
 */
function naive(array $values): float
{
    $sum = 0.0;
    foreach ($values as $value) {
        $sum += $value;
    }

    return $sum;
}

/**
 * @param list<float> $values
 */
function kahan(array $values): float
{
    $sum = 0.0;
    $compensation = 0.0;
    foreach ($values as $value) {
        $y = $value - $compensation;
        $t = $sum + $y;
        $compensation = ($t - $sum) - $y;
        $sum = $t;
    }

    return $sum;
}

/**
 * @param list<float> $values
 */
function neumaier(array $values): float
{
    $sum = 0.0;
    $compensation = 0.0;
    foreach ($values as $value) {
        $t = $sum + $value;
        $compensation += abs($sum) >= abs($value) ? ($sum - $t) + $value : ($value - $t) + $sum;
        $sum = $t;
    }

    return $sum + $compensation;
}

function randomFloat(Randomizer $random, int $minExponent, int $maxExponent): float
{
    $bits = ($random->getInt($minExponent + 1023, $maxExponent + 1023) << 52) | $random->getInt(0, (1 << 52) - 1);
    $unpacked = unpack('E', pack('J', $bits));
    $value = is_array($unpacked) && is_float($unpacked[1] ?? null) ? $unpacked[1] : 0.0;

    return $random->getInt(0, 1) === 0 ? $value : -$value;
}

/**
 * @return array<string, list<float>>
 */
function datasets(int $count): array
{
    $random = new Randomizer(new Xoshiro256StarStar(20261114));
    $uniform = [];
    $wide = [];
    $full = [];
    $pairs = [];
    for ($i = 0; $i < $count; $i++) {
        $uniform[] = $random->getInt(0, (1 << 53) - 1) / 2 ** 53;
        $wide[] = randomFloat($random, -300, 300);
        $full[] = randomFloat($random, -1022, 1023);
    }

    for ($i = 0; $i < intdiv($count, 2); $i++) {
        $x = randomFloat($random, -60, 60);
        array_push($pairs, $x, -$x);
    }

    return [
        'uniform [0, 1)' => $uniform,
        'canceling pairs, shuffled' => $random->shuffleArray($pairs),
        'exponents ±300' => $wide,
        'full range' => $full,
    ];
}

/**
 * @param callable(list<float>): float $sum
 * @param list<float> $values
 */
function milliseconds(callable $sum, array $values): float
{
    $best = INF;
    for ($run = 0; $run < 3; $run++) {
        $start = hrtime(true);
        $sum($values);
        $best = min($best, (hrtime(true) - $start) / 1e6);
    }

    return $best;
}

function main(int $count): void
{
    $contenders = [
        'array_sum' => static fn (array $values): float => (float) array_sum($values),
        'naive loop' => naive(...),
        'Kahan' => kahan(...),
        'Neumaier' => neumaier(...),
        'Fsum\sum' => sum(...),
    ];
    $status = function_exists('opcache_get_status') ? opcache_get_status() : false;
    $jit = is_array($status) && is_array($status['jit'] ?? null) && ($status['jit']['on'] ?? false) === true;

    printf("PHP %s, JIT %s, n = %d, best of 3\n\n", PHP_VERSION, $jit ? 'on' : 'off', $count);
    echo '| Data | ' . implode(' | ', array_keys($contenders)) . " | Fsum / naive |\n";
    echo '|---|' . str_repeat('---|', count($contenders) + 1) . "\n";
    foreach (datasets($count) as $name => $values) {
        $times = array_map(static fn (callable $sum): float => milliseconds($sum, $values), $contenders);
        $cells = array_map(static fn (float $ms): string => sprintf('%.1f ms', $ms), $times);
        printf("| %s | %s | %.0fx |\n", $name, implode(' | ', $cells), $times['Fsum\sum'] / $times['naive loop']);
    }
}

main(max(1, (int) ($argv[1] ?? 1_000_000)));
