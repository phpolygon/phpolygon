<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\NormalPattern;
use PHPUnit\Framework\TestCase;

class NormalPatternTest extends TestCase
{
    public function testNullResolvesToZeroDisablingNormalMapping(): void
    {
        $this->assertSame(0, NormalPattern::codeFor(null));
    }

    public function testUnknownPatternResolvesToZero(): void
    {
        $this->assertSame(0, NormalPattern::codeFor('does_not_exist'));
    }

    public function testKnownPatternsResolveToNonZeroCode(): void
    {
        foreach (NormalPattern::all() as $id) {
            $this->assertGreaterThan(
                0,
                NormalPattern::codeFor($id),
                "Pattern '{$id}' must map to a non-zero shader code",
            );
        }
    }

    public function testCodesAreUnique(): void
    {
        $codes = array_map(NormalPattern::codeFor(...), NormalPattern::all());
        $this->assertSame(
            count($codes),
            count(array_unique($codes)),
            'Pattern codes must be unique - the shader dispatcher relies on this.',
        );
    }

    public function testAllReturnsExpectedSet(): void
    {
        $expected = [
            NormalPattern::BRICKS,
            NormalPattern::BUMPS,
            NormalPattern::ORANGE_PEEL,
            NormalPattern::HAMMERED,
            NormalPattern::HEXAGONS,
            NormalPattern::WOOD_GRAIN,
            NormalPattern::SCRATCHES,
            NormalPattern::CRACKED,
            NormalPattern::NOISE,
        ];
        $this->assertSame($expected, NormalPattern::all());
    }
}
