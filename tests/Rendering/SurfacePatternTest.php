<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\SurfacePattern;
use PHPUnit\Framework\TestCase;

final class SurfacePatternTest extends TestCase
{
    public function testNullResolvesToZero(): void
    {
        $this->assertSame(0, SurfacePattern::codeFor(null));
    }

    public function testUnknownPatternResolvesToZero(): void
    {
        $this->assertSame(0, SurfacePattern::codeFor('does_not_exist'));
    }

    public function testKnownPatternsResolveToNonZeroAndUniqueCodes(): void
    {
        $codes = [];
        foreach (SurfacePattern::all() as $id) {
            $code = SurfacePattern::codeFor($id);
            $this->assertGreaterThan(0, $code, "Pattern '{$id}' must have a non-zero code");
            $codes[] = $code;
        }
        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    public function testAllReturnsExpectedSet(): void
    {
        $this->assertSame([
            SurfacePattern::WORN_PAINT,
            SurfacePattern::RUST,
            SurfacePattern::BRUSHED_METAL,
            SurfacePattern::POLISHED_RINGS,
            SurfacePattern::SKIN,
        ], SurfacePattern::all());
    }
}
