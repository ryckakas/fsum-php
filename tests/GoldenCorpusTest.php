<?php

declare(strict_types=1);

namespace Fsum\Tests;

use Fsum\Tests\Support\GoldenCorpus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GoldenCorpusTest extends TestCase
{
    // The corpus is generated in the Go repository; re-vendor it from there and update testdata/README.md.
    private const SHA256 = '8225c53171fdc3c16206f4a80b258e8d99e9550c692d00aebf2afe079055ee0c';

    public function testTheVendoredCorpusIsByteForByteTheReleasedOne(): void
    {
        self::assertSame(self::SHA256, hash_file('sha256', GoldenCorpus::PATH));
    }

    public function testEveryCaseParses(): void
    {
        $cases = GoldenCorpus::load();

        self::assertCount(1033, $cases);
        self::assertSame('empty', $cases[0]->name);
        self::assertSame([], $cases[0]->inputs);
        self::assertSame([1.5], $cases[1]->inputs);
    }

    public function testBitPatternsRoundTripExactly(): void
    {
        foreach (['0000000000000000', '8000000000000000', '0000000000000001', '7fefffffffffffff', '7ff0000000000000', 'fff0000000000000', '7ff8000000000000'] as $hex) {
            self::assertSame($hex, GoldenCorpus::hex(GoldenCorpus::float($hex)));
        }
    }

    public function testAMalformedBitPatternIsRejected(): void
    {
        $this->expectException(RuntimeException::class);

        GoldenCorpus::float('3ff8');
    }
}
