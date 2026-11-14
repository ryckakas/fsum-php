<?php

declare(strict_types=1);

namespace Fsum\Tests\Support;

final class GoldenCase
{
    /**
     * @param list<float> $inputs
     */
    public function __construct(
        public readonly string $name,
        public readonly float $expected,
        public readonly array $inputs,
    ) {
    }
}
