<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\NormalPattern;
use PHPolygon\Rendering\SurfacePattern;
use PHPUnit\Framework\TestCase;

/**
 * The pattern ids, their shader codes and the dispatch arms in mesh3d.frag stay in step:
 * a code that no dispatcher handles renders as a flat surface without any error.
 */
final class ProceduralPatternDispatchTest extends TestCase
{
    private string $frag = '';

    protected function setUp(): void
    {
        $this->frag = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/resources/shaders/source/vio/mesh3d.frag.glsl'));
    }

    /** @return array<string, int> constant name => code, every pattern of the class (also those all() leaves out) */
    private static function codes(string $class): array
    {
        $out = [];
        foreach ((new \ReflectionClass($class))->getReflectionConstants() as $const) {
            $value = $const->getValue();
            if (is_string($value)) {
                $out[$const->getName()] = $class::codeFor($value);
            }
        }

        return $out;
    }

    public function testNewPatternCodesAreFrozen(): void
    {
        // Codes are part of the shader contract: they are appended, never renumbered.
        self::assertSame(13, NormalPattern::codeFor(NormalPattern::FORMWORK));
        self::assertSame(14, NormalPattern::codeFor(NormalPattern::AGGREGATE));
        self::assertSame(15, NormalPattern::codeFor(NormalPattern::BRUSHED));
        self::assertSame(16, NormalPattern::codeFor(NormalPattern::HAMMERED_SOFT));
        self::assertSame(17, NormalPattern::codeFor(NormalPattern::WOOD_PLANKS));
        self::assertSame(8, SurfacePattern::codeFor(SurfacePattern::MOTTLE));
        self::assertSame(9, SurfacePattern::codeFor(SurfacePattern::WORN_PAINT_SOFT));
        self::assertSame(10, SurfacePattern::codeFor(SurfacePattern::WOOD_PLANKS));
        // Ids that existed before keep their codes.
        self::assertSame(12, NormalPattern::codeFor(NormalPattern::ROOF_TILES));
        self::assertSame(7, SurfacePattern::codeFor(SurfacePattern::SANDSTONE));
    }

    public function testEveryPatternHasAUniqueNonZeroCode(): void
    {
        foreach ([NormalPattern::class, SurfacePattern::class] as $class) {
            $codes = self::codes($class);
            self::assertNotContains(0, $codes, "{$class}: a pattern without a code");
            self::assertSame(count($codes), count(array_unique($codes)), "{$class}: codes must be unique");
            $sorted = array_values($codes);
            sort($sorted);
            self::assertSame(range(1, count($codes)), $sorted, "{$class}: codes are 1..n without gaps");
        }
    }

    public function testEveryNormalCodeIsDispatchedForRelief(): void
    {
        foreach (self::codes(NormalPattern::class) as $name => $code) {
            self::assertMatchesRegularExpression(
                '/dispatchProceduralNormal\(int code, vec2 uv\) \{.*?if \(code == ' . $code . '\)\s+return np_\w+\(uv\);/s',
                $this->frag,
                "NormalPattern::{$name} (code {$code}) has no arm in dispatchProceduralNormal",
            );
            self::assertMatchesRegularExpression(
                '/float dispatchProceduralHeight\(int code, vec2 uv\) \{.*?if \(code == ' . $code . '\)\s+return nh_\w+\(uv\);/s',
                $this->frag,
                "NormalPattern::{$name} (code {$code}) has no arm in dispatchProceduralHeight",
            );
        }
    }

    public function testEverySurfaceCodeIsDispatched(): void
    {
        foreach (self::codes(SurfacePattern::class) as $name => $code) {
            self::assertMatchesRegularExpression(
                '/vec3 dispatchSurfacePattern\(int code, vec2 uv\) \{.*?if \(code == ' . $code . '\)\s+return sp_\w+\(uv\);/s',
                $this->frag,
                "SurfacePattern::{$name} (code {$code}) has no arm in dispatchSurfacePattern",
            );
        }
    }

    public function testAllKeepsItsListedSets(): void
    {
        // all() is a pinned list (NormalPatternTest / SurfacePatternTest): the kit patterns are not in it.
        self::assertNotContains(NormalPattern::FORMWORK, NormalPattern::all());
        self::assertNotContains(SurfacePattern::MOTTLE, SurfacePattern::all());
    }
}
