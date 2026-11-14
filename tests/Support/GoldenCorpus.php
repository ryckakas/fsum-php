<?php

declare(strict_types=1);

namespace Fsum\Tests\Support;

use RuntimeException;

/**
 * Reads testdata/golden.txt, vendored from github.com/ryckakas/fsum (see testdata/README.md).
 */
final class GoldenCorpus
{
    public const PATH = __DIR__ . '/../../testdata/golden.txt';

    /**
     * @return array<string, array{GoldenCase}>
     */
    public static function provide(): array
    {
        $cases = [];
        foreach (self::load() as $case) {
            $cases[$case->name] = [$case];
        }

        return $cases;
    }

    /**
     * @return list<GoldenCase>
     */
    public static function load(): array
    {
        $lines = file(self::PATH, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('cannot read ' . self::PATH);
        }

        $cases = [];
        foreach ($lines as $line) {
            if ($line !== '' && !str_starts_with($line, '#')) {
                $cases[] = self::parse($line);
            }
        }

        return $cases;
    }

    public static function float(string $hex): float
    {
        $bytes = hex2bin($hex);
        $value = $bytes === false || strlen($bytes) !== 8 ? false : unpack('E', $bytes);
        if ($value === false || !is_float($value[1] ?? null)) {
            throw new RuntimeException("not a 16-digit float64 bit pattern: {$hex}");
        }

        return $value[1];
    }

    public static function hex(float $value): string
    {
        return bin2hex(pack('E', $value));
    }

    private static function parse(string $line): GoldenCase
    {
        $fields = explode("\t", $line);
        if (count($fields) !== 3) {
            throw new RuntimeException("expected 3 tab-separated fields: {$line}");
        }

        [$name, $expected, $inputs] = $fields;

        return new GoldenCase(
            $name,
            self::float($expected),
            $inputs === '' ? [] : array_map(self::float(...), explode(' ', $inputs)),
        );
    }
}
