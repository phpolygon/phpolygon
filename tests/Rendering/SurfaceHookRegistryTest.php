<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\SurfaceEffects;

/** The surface hook as text: where it lands, what is left without it, no GPU involved. */
final class SurfaceHookRegistryTest extends TestCase
{
    private const SHADERS = '/resources/shaders/source/';

    protected function setUp(): void
    {
        ProcModeShaderRegistry::clear();
    }

    protected function tearDown(): void
    {
        ProcModeShaderRegistry::clear();
    }

    public function testWithoutAHookBothSentinelsVanish(): void
    {
        $source = ProcModeShaderRegistry::SURFACE_HELPERS_SENTINEL . "\nX\n" . ProcModeShaderRegistry::SURFACE_SENTINEL;
        self::assertSame("\nX\n", ProcModeShaderRegistry::spliceGlsl($source));
        self::assertSame("\nX\n", ProcModeShaderRegistry::spliceGlsl($source, ProcModeShaderRegistry::FAMILY_GL));
    }

    public function testHelpersAndCallLandAtTheirOwnSentinel(): void
    {
        ProcModeShaderRegistry::registerSurfaceGlsl("float fx() { return 1.0; }\n", "    albedo *= fx();\n");
        $source = ProcModeShaderRegistry::SURFACE_HELPERS_SENTINEL . "\n|\n" . ProcModeShaderRegistry::SURFACE_SENTINEL;

        self::assertSame("float fx() { return 1.0; }\n\n|\n    albedo *= fx();\n", ProcModeShaderRegistry::spliceGlsl($source));
        self::assertSame(
            ProcModeShaderRegistry::spliceGlsl($source),
            ProcModeShaderRegistry::spliceGlsl($source, ProcModeShaderRegistry::FAMILY_GL),
            'the GL family falls back to the VIO text',
        );
    }

    public function testAnEmptyGlCallLeavesTheNativeGlProgramAlone(): void
    {
        ProcModeShaderRegistry::registerSurfaceGlsl('', "    discard;\n", '', '');

        self::assertSame("    discard;\n", ProcModeShaderRegistry::spliceGlsl(ProcModeShaderRegistry::SURFACE_SENTINEL));
        self::assertSame('', ProcModeShaderRegistry::spliceGlsl(ProcModeShaderRegistry::SURFACE_SENTINEL, ProcModeShaderRegistry::FAMILY_GL));
    }

    public function testClearForgetsTheHook(): void
    {
        ProcModeShaderRegistry::registerSurfaceGlsl('h', 'c');
        ProcModeShaderRegistry::clear();

        self::assertSame('', ProcModeShaderRegistry::surfaceHelpers());
        self::assertSame('', ProcModeShaderRegistry::surfaceCall(ProcModeShaderRegistry::FAMILY_GL));
    }

    /**
     * Both copies carry the hook once, after the proc_mode helpers (so hooks may use
     * them) and before lighting, and add the hook's emission once.
     */
    public function testBothMeshFragmentCopiesHoldTheHookInOrder(): void
    {
        $root = dirname(__DIR__, 2) . self::SHADERS;
        foreach (['vio/mesh3d.frag.glsl' => 'localAcc += u_emission + surfaceEmission;', 'mesh3d.frag.glsl' => 'color += u_emission + surfaceEmission;'] as $file => $emission) {
            $src = str_replace("\r\n", "\n", (string) file_get_contents($root . $file));
            $helpers = strpos($src, ProcModeShaderRegistry::SURFACE_HELPERS_SENTINEL . "\n");
            $call = strpos($src, '    ' . ProcModeShaderRegistry::SURFACE_SENTINEL . "\n");
            self::assertNotFalse($helpers, $file);
            self::assertNotFalse($call, $file);
            self::assertSame(1, substr_count($src, '    ' . ProcModeShaderRegistry::SURFACE_SENTINEL . "\n"), $file);
            self::assertGreaterThan((int) strpos($src, ProcModeShaderRegistry::HELPERS_SENTINEL), $helpers, $file);
            self::assertGreaterThan((int) strpos($src, ProcModeShaderRegistry::BRANCHES_SENTINEL), $call, $file);
            self::assertLessThan((int) strpos($src, 'PBR Lighting'), $call, $file);
            self::assertSame(1, substr_count($src, $emission), $file);
        }
    }

    public function testTheLibraryOnlyDefinesPrefixedFunctions(): void
    {
        $glsl = SurfaceEffects::glsl();
        preg_match_all('/^(?:float|vec2|vec3|void)\s+(\w+)\s*\(/m', $glsl, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $name) {
            self::assertStringStartsWith('sfx_', $name);
        }
        self::assertStringNotContainsString('u_', $glsl, 'no engine uniform');
        self::assertStringNotContainsString('v_', $glsl, 'no varying');
        self::assertStringNotContainsString('sin(', $glsl, 'sine-free hash');
    }
}
