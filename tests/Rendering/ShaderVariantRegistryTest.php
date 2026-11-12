<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\ShaderPath;

/**
 * Program variants of the mesh shader, no GPU involved: which snippets land in which
 * program, that a branch ladder survives any selection of modes, and that the base
 * shader is the one of the last release as long as nobody defines anything.
 */
final class ShaderVariantRegistryTest extends TestCase
{
    private const SENTINEL = "    /* PHPOLYGON:PROCMODE_BRANCHES */ { base(); }\n";

    /**
     * sha256 of mesh3d.frag as the preprocessor leaves it without any define, whitespace
     * collapsed, at the release of the shader plan (v0.101.0). A change to the default
     * program is a decision: update this hash in the commit that makes it and say why.
     * Changed for emission behind the albedo texture's alpha (u_emission_mask, glowing
     * text on surfaces): off by default, the frames of other materials stay the same.
     * Changed again for the window grid (u_window_grid, u_emissive_look.z): the lit-window
     * look reads its cell size/origin from uniforms whose defaults are the old constants
     * (2.2 x 2.8 m); the default window image is byte-identical (D3D12/WARP and D3D11/RTX 2080).
     * Changed again for the kit patterns (ShaderPath::KitPatterns, normal codes 13-17, surface 8-10). This
     * hash is the program with nothing omitted; a game without kit materials compiles it without them
     * (automatic omission): measured on the RTX 2080 (2026-11-12) 12 pattern tiles, 7 surface tiles, metal
     * spheres and plain tiles came out byte-identical to the previous shader on D3D12 and D3D11 (without the
     * gating D3D12 differed by 1 LSB on up to 33 pixels per image).
     * Changed again for the opt-in hemisphere specular (PHPOLYGON_HEMI_SPECULAR: compiled only with its define;
     * the no-define text differs by the `envShadow` temporary only).
     * Changed again for the frost fallback inside the refraction path (an automatic path: absent from programs
     * without refraction / frost materials).
     */
    private const VIO_FRAG_RELEASE = '0323f000e02eaff89803e1d4d28cbad3965d962150c12bf7036881309b1f440c';

    protected function setUp(): void
    {
        ProcModeShaderRegistry::clear();
        ProcModeRegistry::clear();
        MaterialRegistry::clear();
    }

    protected function tearDown(): void
    {
        ProcModeShaderRegistry::clear();
        ProcModeRegistry::clear();
        MaterialRegistry::clear();
    }

    private static function registerLadder(): void
    {
        ProcModeShaderRegistry::registerGlsl(2, "float h2;\n", "    if (u_proc_mode == 2) {\n        two();\n        return;\n");
        ProcModeShaderRegistry::registerGlsl(4, "float h4;\n", "    } else if (u_proc_mode == 4) {\n        four();\n");
        ProcModeShaderRegistry::registerGlsl(8, "float h8;\n", "    } else if (u_proc_mode == 8) {\n        eight();\n");
        ProcModeShaderRegistry::registerGlsl(9, '', "    } else if (u_proc_mode == 9) {\n        nine();\n    } else");
        ProcModeShaderRegistry::setOrder(ProcModeShaderRegistry::REGION_GLSL_BRANCHES, [2, 4, 8, 9]);
    }

    public function testACompleteLadderComesBackByteForByte(): void
    {
        self::registerLadder();
        self::assertSame(
            "    if (u_proc_mode == 2) {\n        two();\n        return;\n"
            . "    } else if (u_proc_mode == 4) {\n        four();\n"
            . "    } else if (u_proc_mode == 8) {\n        eight();\n"
            . "    } else if (u_proc_mode == 9) {\n        nine();\n    } else",
            ProcModeShaderRegistry::glslBranches(),
        );
    }

    public function testLeavingOutTheArmThatClosesTheLadderKeepsItClosed(): void
    {
        self::registerLadder();
        ProcModeShaderRegistry::setOrder(ProcModeShaderRegistry::REGION_GLSL_BRANCHES, [2, 4, 8]);
        self::assertSame(
            "    if (u_proc_mode == 2) {\n        two();\n        return;\n"
            . "    } else if (u_proc_mode == 4) {\n        four();\n"
            . "    } else if (u_proc_mode == 8) {\n        eight();\n"
            . "\n    } else\n",
            ProcModeShaderRegistry::glslBranches(),
        );
    }

    public function testLeavingOutTheArmThatOpensTheLadderOpensItWithTheNextOne(): void
    {
        self::registerLadder();
        ProcModeShaderRegistry::setOrder(ProcModeShaderRegistry::REGION_GLSL_BRANCHES, [4, 8, 9]);
        self::assertStringStartsWith('    if (u_proc_mode == 4)', ProcModeShaderRegistry::glslBranches());
    }

    public function testAClosingArmInTheMiddleHandsTheElseToTheEnd(): void
    {
        self::registerLadder();
        ProcModeShaderRegistry::setOrder(ProcModeShaderRegistry::REGION_GLSL_BRANCHES, [9, 2]);
        $ladder = ProcModeShaderRegistry::glslBranches();
        self::assertSame(1, substr_count($ladder, '} else if'), $ladder);
        self::assertStringStartsWith('    if (u_proc_mode == 9)', $ladder);
        self::assertMatchesRegularExpression('/\} else\s*$/', $ladder);
        self::assertStringNotContainsString("} else\n    } else", $ladder);
    }

    public function testVariantsPartitionTheModes(): void
    {
        self::registerLadder();
        ProcModeShaderRegistry::defineVariant('wet', [2, 8], [ShaderPath::NormalPatterns]);

        $base = ProcModeShaderRegistry::glslBranches(ProcModeShaderRegistry::FAMILY_VIO, ProcModeShaderRegistry::BASE_VARIANT);
        self::assertStringNotContainsString('two()', $base);
        self::assertStringNotContainsString('eight()', $base);
        self::assertStringStartsWith('    if (u_proc_mode == 4)', $base);
        self::assertStringContainsString('nine()', $base);

        $wet = ProcModeShaderRegistry::glslBranches(ProcModeShaderRegistry::FAMILY_VIO, 'wet');
        self::assertStringStartsWith('    if (u_proc_mode == 2)', $wet);
        self::assertStringNotContainsString('four()', $wet);
        self::assertMatchesRegularExpression('/\} else\s*$/', $wet);

        $helpers = ProcModeShaderRegistry::glslHelpers(ProcModeShaderRegistry::FAMILY_VIO, 'wet');
        self::assertSame("float h2;\nfloat h8;\n", $helpers);

        self::assertSame(['PHPOLYGON_OMIT_NORMAL_PATTERNS'], ProcModeShaderRegistry::variantDefines('wet'));
        self::assertSame([], ProcModeShaderRegistry::variantDefines());
        self::assertSame('wet', ProcModeShaderRegistry::variantOf(8));
        self::assertSame(ProcModeShaderRegistry::BASE_VARIANT, ProcModeShaderRegistry::variantOf(4));
    }

    public function testTheOneProgramOfTheGlRendererKeepsEveryMode(): void
    {
        self::registerLadder();
        ProcModeShaderRegistry::defineVariant('wet', [2, 8]);
        $gl = ProcModeShaderRegistry::spliceGlsl(self::SENTINEL, ProcModeShaderRegistry::FAMILY_GL);
        foreach (['two()', 'four()', 'eight()', 'nine()'] as $call) {
            self::assertStringContainsString($call, $gl);
        }
    }

    public function testNothingRegisteredLeavesACompilableBase(): void
    {
        self::assertSame(
            "     { base(); }\n",
            ProcModeShaderRegistry::spliceGlsl(self::SENTINEL, ProcModeShaderRegistry::FAMILY_VIO, ProcModeShaderRegistry::BASE_VARIANT),
        );
    }

    public function testAModeBelongsToOneVariant(): void
    {
        ProcModeShaderRegistry::defineVariant('a', [2]);
        $this->expectException(\InvalidArgumentException::class);
        ProcModeShaderRegistry::defineVariant('b', [2]);
    }

    public function testVariantNamesAreSafeForProgramIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProcModeShaderRegistry::defineVariant('Bad Name', [2]);
    }

    public function testOmissionViolationsNameTheMaterialAndThePath(): void
    {
        ProcModeRegistry::map('wet_', 2);
        ProcModeShaderRegistry::defineVariant('wet', [2], [ShaderPath::NormalPatterns]);
        ProcModeShaderRegistry::omitFromBase([ShaderPath::Clearcoat]);
        MaterialRegistry::register('wet_plain', new Material(albedo: new Color(0.1, 0.2, 0.3)));
        MaterialRegistry::register('wet_brick', new Material(normalPattern: 'bricks'));
        MaterialRegistry::register('lacquer', new Material(clearcoat: 0.8));
        MaterialRegistry::register('brick', new Material(normalPattern: 'bricks'));

        self::assertSame(
            ['wet_brick' => [ShaderPath::NormalPatterns], 'lacquer' => [ShaderPath::Clearcoat]],
            ProcModeShaderRegistry::omissionViolations(),
        );
    }

    public function testEveryPathGuardExistsInTheShader(): void
    {
        $frag = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/shaders/source/vio/mesh3d.frag.glsl');
        foreach (ShaderPath::cases() as $path) {
            self::assertStringContainsString('#ifndef ' . $path->define(), $frag);
            self::assertSame(
                substr_count($frag, '#ifndef ' . $path->define()),
                substr_count($frag, '#endif // ' . $path->define()),
                $path->define(),
            );
        }
    }

    /**
     * With no define set, the preprocessor leaves the shader of the last release: the
     * guards add lines, not code. Whitespace is collapsed (`} else {` became `} else` + `{`).
     */
    public function testWithoutDefinesTheShaderIsTheOneOfTheLastRelease(): void
    {
        $frag = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/resources/shaders/source/vio/mesh3d.frag.glsl'));
        self::assertSame(self::VIO_FRAG_RELEASE, hash('sha256', self::preprocess($frag)));
    }

    /**
     * What the GLSL preprocessor keeps of the guards this repository adds, when none
     * of their macros is defined.
     */
    public static function preprocess(string $source): string
    {
        $out = [];
        $stack = [];
        foreach (explode("\n", $source) as $line) {
            if (trim($line) === '// PHPOLYGON:SNIPPET_UNIFORMS') {
                continue;
            }
            if (preg_match('/^\s*#ifndef PHPOLYGON_OMIT_/', $line) === 1) {
                $stack[] = 'keep';
                continue;
            }
            if (preg_match('/^\s*#ifdef PHPOLYGON_(SCENE_DEPTH|INSTANCE_PARAMS|MATERIAL_PARAMS|PRECISE_HASH)\b/', $line) === 1) {
                $stack[] = 'drop';
                continue;
            }
            if (preg_match('/^\s*#else\b/', $line) === 1 && $stack !== [] && end($stack) !== 'other') {
                $stack[count($stack) - 1] = end($stack) === 'keep' ? 'drop' : 'keep';
                continue;
            }
            if (preg_match('/^\s*#endif \/\/ PHPOLYGON_/', $line) === 1) {
                array_pop($stack);
                continue;
            }
            if (preg_match('/^\s*#(if|ifdef|ifndef)\b/', $line) === 1) {
                $stack[] = 'other';
            } elseif (preg_match('/^\s*#endif\b/', $line) === 1 && $stack !== []) {
                array_pop($stack);
            }
            if (!in_array('drop', $stack, true)) {
                $out[] = $line;
            }
        }

        return (string) preg_replace('/\s+/', ' ', implode("\n", $out));
    }
}
