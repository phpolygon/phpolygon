<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\ShaderPath;

/** The Lite tier's guards in vio/mesh3d.frag and the names the renderer builds its program from. */
final class ShaderQualityLiteSourceTest extends TestCase
{
    private static function frag(): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/resources/shaders/source/vio/mesh3d.frag.glsl'));
    }

    public function testEveryLiteGuardIsClosedWithTheMacroInTheComment(): void
    {
        $frag = self::frag();
        $opened = preg_match_all('/^\s*#if(n?def)\s+PHPOLYGON_LITE\b/m', $frag);
        $closed = preg_match_all('/^\s*#endif\s*\/\/\s*PHPOLYGON_LITE\b/m', $frag);
        self::assertGreaterThan(0, $opened);
        self::assertSame($opened, $closed);
    }

    public function testTheLiteLightingBranchKeepsTheThreeAccumulators(): void
    {
        $frag = self::frag();
        $start = strpos($frag, "#ifdef PHPOLYGON_LITE\n    // ---- Lite lighting");
        self::assertIsInt($start);
        $end = strpos($frag, '#else', $start);
        self::assertIsInt($end);
        $lite = substr($frag, $start, $end - $start);
        foreach (['vec3 ambientAcc', 'vec3 sunAcc', 'vec3 localAcc'] as $declaration) {
            self::assertStringContainsString($declaration, $lite);
        }
        self::assertStringNotContainsString('sampleEnvironment', $lite, 'no IBL in Lite');
        self::assertStringNotContainsString('cookTorranceSpecular', $lite, 'no GGX in Lite');
        self::assertStringNotContainsString('pointShadowFor', $lite, 'no point-light shadows in Lite');
    }

    public function testTheLiteOmissionsAreRealPathGuards(): void
    {
        $frag = self::frag();
        $defines = array_map(static fn (ShaderPath $p): string => $p->define(), ShaderPath::cases());
        foreach (ShaderPath::liteOmissions() as $define) {
            self::assertContains($define, $defines);
            self::assertStringContainsString('#ifndef ' . $define, $frag);
        }
    }

    public function testTheLiteVariantNamesCannotBeTakenByAGame(): void
    {
        foreach ([
            ProcModeShaderRegistry::LITE_VARIANT,
            ProcModeShaderRegistry::LITE_CUT_VARIANT,
            ProcModeShaderRegistry::NOGRID_VARIANT,
            ProcModeShaderRegistry::NOGRID_CUT_VARIANT,
            ProcModeShaderRegistry::GRID_VARIANT,
            ProcModeShaderRegistry::GRID_CUT_VARIANT,
        ] as $name) {
            try {
                ProcModeShaderRegistry::defineVariant($name, [99]);
                self::fail("a game defined the variant '{$name}'");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
