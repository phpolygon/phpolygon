<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\GlCapabilities;

class GlCapabilitiesTest extends TestCase
{
    /**
     * @return array<string, array{string, int, int}>
     */
    public static function versionStrings(): array
    {
        return [
            'nvidia 4.6'   => ['4.6.0 NVIDIA 550.90.07', 4, 6],
            'mesa 3.1'     => ['3.1 Mesa 21.2.6', 3, 1],
            'mesa 3.0'     => ['3.0 Mesa 20.0.8', 3, 0],
            'apple metal'  => ['4.1 Metal - 89.3', 4, 1],
            'gl es'        => ['OpenGL ES 3.0 Mesa 22.0', 3, 0],
            'plain 3.3'    => ['3.3', 3, 3],
        ];
    }

    #[DataProvider('versionStrings')]
    public function testParsesDriverVersionStrings(string $glVersion, int $major, int $minor): void
    {
        $caps = GlCapabilities::parse($glVersion);
        self::assertSame($major, $caps->major);
        self::assertSame($minor, $caps->minor);
    }

    public function testUnparseableVersionFallsBackToFloor(): void
    {
        $caps = GlCapabilities::parse('no version here');
        self::assertSame(3, $caps->major);
        self::assertSame(0, $caps->minor);
    }

    public function testTierIsMajorTimesTenPlusMinor(): void
    {
        self::assertSame(41, (new GlCapabilities(4, 1))->tier());
        self::assertSame(30, (new GlCapabilities(3, 0))->tier());
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function directiveCases(): array
    {
        return [
            'gl 4.6 → 150 core' => [4, 6, '#version 150 core'],
            'gl 4.1 → 150 core' => [4, 1, '#version 150 core'],
            'gl 3.3 → 150 core' => [3, 3, '#version 150 core'],
            'gl 3.2 → 150 core' => [3, 2, '#version 150 core'],
            'gl 3.1 → 140'      => [3, 1, '#version 140'],
            'gl 3.0 → 130'      => [3, 0, '#version 130'],
        ];
    }

    #[DataProvider('directiveCases')]
    public function testGlslVersionDirectiveMatchesContext(int $major, int $minor, string $expected): void
    {
        self::assertSame($expected, (new GlCapabilities($major, $minor))->glslVersionDirective());
    }

    public function testCoreInstancingRequiresGl33(): void
    {
        self::assertTrue((new GlCapabilities(3, 3))->hasCoreInstancing());
        self::assertTrue((new GlCapabilities(4, 1))->hasCoreInstancing());
        self::assertFalse((new GlCapabilities(3, 2))->hasCoreInstancing());
        self::assertFalse((new GlCapabilities(3, 0))->hasCoreInstancing());
    }

    public function testPostProcessingRequiresGl33(): void
    {
        self::assertTrue((new GlCapabilities(3, 3))->hasPostProcessing());
        self::assertFalse((new GlCapabilities(3, 1))->hasPostProcessing());
    }
}
