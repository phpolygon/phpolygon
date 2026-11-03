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
 * shader is the v0.92.0 shader as long as nobody defines anything.
 */
final class ShaderVariantRegistryTest extends TestCase
{
    private const SENTINEL = "    /* PHPOLYGON:PROCMODE_BRANCHES */ { base(); }\n";

    /** sha256 of mesh3d.frag at v0.92.0 with whitespace collapsed. */
    private const VIO_FRAG_V092 = '3f45aaaee65df657b11d60ba86f0640d4d928ed32890e3bcced9ffae8a24625e';

    /**
     * Intended changes since v0.96.1, as [new text, old text]: the reactive mask of opaque
     * surfaces (Material::$reactive, VioReactiveMaterialTest) and the albedo filter of a material
     * (Material::$mipBias / $anisotropy, VioMaterialTextureFilterTest; its sampling function is cut
     * out between {@see ALBEDO_FILTER_START} and the next blank line).
     *
     * @var list<array{0: string, 1: string}>
     */
    private const ALBEDO_FILTER_START = 'uniform float u_material_mip_bias;';

    private const SINCE_V0961 = [
        [
            "//                      (transparent pass, additive), or Material::\$reactive of an\n"
            . "//                      opaque surface whose content moves (opaque pass, only in\n"
            . "//                      frames that draw one; the others write 0 then)\n",
            "//                      (transparent pass, additive)\n",
        ],
        ["uniform float u_material_reactive; // Material::\$reactive\n", ''],
        [
            "#ifdef PHPOLYGON_MOTION\n"
            . "// The reactive mask: a transparent surface's coverage, an opaque one's own value\n"
            . "// (Material::\$reactive), whichever is larger.\n"
            . "float reactiveValue(float coverage) {\n"
            . "    return clamp(max(u_material_reactive, u_alpha >= 1.0 ? 0.0 : coverage), 0.0, 1.0);\n"
            . "}\n"
            . "#endif\n",
            '',
        ],
        ['float reactiveAlpha = reactiveValue(alpha);', 'float reactiveAlpha = clamp(alpha, 0.0, 1.0);'],
        ['float reactive = reactiveValue(u_alpha);', 'float reactive = clamp(u_alpha, 0.0, 1.0);'],
        [
            "        float mipScale = exp2(u_mip_bias + u_material_mip_bias);\n"
            . "#else\n"
            . "        float mipScale = exp2(u_material_mip_bias);\n"
            . "#endif\n"
            . "        texAlbedo *= sampleAlbedoTexture(patternUv, uvDx * mipScale, uvDy * mipScale);\n",
            "        float mipScale = exp2(u_mip_bias);\n"
            . "        texAlbedo *= textureGrad(u_albedo_texture, patternUv, uvDx * mipScale, uvDy * mipScale).rgb;\n"
            . "#else\n"
            . "        texAlbedo *= textureGrad(u_albedo_texture, patternUv, uvDx, uvDy).rgb;\n"
            . "#endif\n",
        ],
    ];    /** The stage-local animation time of mesh3d.frag (v0.96.1), replacing `uniform float u_time;`. */
    private const FRAGMENT_TIME = "// Animation time of this stage. The vertex stage declares u_time too, and php-vio's\n"
        . "// cbuffer backends (D3D11, D3D12, Vulkan, Metal) set a name declared in both stages\n"
        . "// in the vertex stage only - the fragment copy read 0. The renderer uploads the same\n"
        . "// value under this stage-local name; fragment code and snippets keep writing u_time.\n"
        . "uniform float u_frag_time;\n"
        . "#define u_time u_frag_time\n";

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
     * With no define set, the preprocessor leaves the v0.92.0 shader: the guards add
     * lines, not code. Whitespace is collapsed (`} else {` became `} else` + `{`).
     */
    public function testWithoutDefinesTheShaderIsTheOneOfTheLastRelease(): void
    {
        $frag = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/resources/shaders/source/vio/mesh3d.frag.glsl'));
        // The one intended change since: the fragment stage reads the time under its own
        // name (VioFragmentTimeTest). Undo exactly that block.
        self::assertStringContainsString(self::FRAGMENT_TIME, $frag);
        $frag = str_replace(self::FRAGMENT_TIME, "uniform float u_time;\n", $frag);
        $start = strpos($frag, self::ALBEDO_FILTER_START);
        self::assertNotFalse($start);
        $end = strpos($frag, "}\n\n", $start);
        self::assertNotFalse($end);
        $frag = substr($frag, 0, $start) . substr($frag, $end + 3);
        foreach (self::SINCE_V0961 as [$now, $before]) {
            self::assertStringContainsString($now, $frag);
            $frag = str_replace($now, $before, $frag);
        }
        self::assertSame(self::VIO_FRAG_V092, hash('sha256', self::preprocess($frag)));
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
            if (preg_match('/^\s*#ifdef PHPOLYGON_(SCENE_DEPTH|INSTANCE_PARAMS|MATERIAL_PARAMS)\b/', $line) === 1) {
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
