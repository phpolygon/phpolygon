<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\Command\SetShaderTexture;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ShaderPath;

/** Declarations, naming rules and the D3D12 rule of one name per stage, no GPU involved. */
final class SnippetUniformRegistryTest extends TestCase
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

    public function testNothingDeclaredLeavesNothingBehind(): void
    {
        self::assertSame('', ProcModeShaderRegistry::spliceGlsl(ProcModeShaderRegistry::UNIFORMS_SENTINEL));
    }

    public function testDeclarationsLandAtTheSentinelOfEveryFamily(): void
    {
        ProcModeShaderRegistry::declareUniform('u_fx_level', 'float');
        self::assertSame(12, ProcModeShaderRegistry::declareSampler('u_fx_map'));

        self::assertSame(
            "uniform float u_fx_level;\nuniform sampler2D u_fx_map;\n",
            ProcModeShaderRegistry::spliceGlsl(ProcModeShaderRegistry::UNIFORMS_SENTINEL),
        );
        self::assertSame(
            "uniform float u_fx_level;\n",
            ProcModeShaderRegistry::spliceGlsl(ProcModeShaderRegistry::UNIFORMS_SENTINEL, ProcModeShaderRegistry::FAMILY_GL),
            'the GL family binds no snippet textures',
        );
    }

    public function testBothMeshFragmentCopiesHoldTheSentinel(): void
    {
        $root = dirname(__DIR__, 2) . self::SHADERS;
        self::assertStringContainsString(ProcModeShaderRegistry::UNIFORMS_SENTINEL, (string) file_get_contents($root . 'vio/mesh3d.frag.glsl'));
        self::assertStringContainsString(ProcModeShaderRegistry::UNIFORMS_SENTINEL, (string) file_get_contents($root . 'mesh3d.frag.glsl'));
    }

    /**
     * The numbers behind the budget come from a D3D12 compile: one more sampler than
     * this ends in error X4610 (see VioSnippetUniformTest for the sampled case).
     */
    public function testAProgramHoldsWhatPhpVioGivesIt(): void
    {
        ProcModeShaderRegistry::defineVariant('lean', [2], [ShaderPath::ProbeGi]);
        self::assertSame(1, ProcModeShaderRegistry::samplerBudget());
        self::assertSame(4, ProcModeShaderRegistry::samplerBudget('lean'));

        ProcModeShaderRegistry::declareSampler('u_fx_a', ['lean']);
        ProcModeShaderRegistry::declareSampler('u_fx_b', ['lean']);
        ProcModeShaderRegistry::declareSampler('u_fx_c');
        ProcModeShaderRegistry::assertSamplerBudget();

        ProcModeShaderRegistry::declareSampler('u_fx_d');
        $this->expectException(\InvalidArgumentException::class);
        ProcModeShaderRegistry::assertSamplerBudget();
    }

    public function testASamplerLandsOnlyInItsPrograms(): void
    {
        ProcModeShaderRegistry::defineVariant('lean', [2], [ShaderPath::ProbeGi]);
        ProcModeShaderRegistry::declareSampler('u_fx_a', ['lean']);
        self::assertSame('', ProcModeShaderRegistry::uniformDeclarations(ProcModeShaderRegistry::FAMILY_VIO, ProcModeShaderRegistry::BASE_VARIANT));
        self::assertSame("uniform sampler2D u_fx_a;\n", ProcModeShaderRegistry::uniformDeclarations(ProcModeShaderRegistry::FAMILY_VIO, 'lean'));
    }

    public function testSamplersGetDistinctSlotsAboveTheEngineOnes(): void
    {
        self::assertSame(12, ProcModeShaderRegistry::declareSampler('u_fx_a'));
        self::assertSame(13, ProcModeShaderRegistry::declareSampler('u_fx_b'));
        self::assertSame(12, ProcModeShaderRegistry::declareSampler('u_fx_a'), 'declaring twice is the same sampler');
        ProcModeShaderRegistry::declareSampler('u_fx_c');
        ProcModeShaderRegistry::declareSampler('u_fx_d');
        $this->expectException(\InvalidArgumentException::class);
        ProcModeShaderRegistry::declareSampler('u_fx_e');
    }

    public function testNamesAreChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProcModeShaderRegistry::declareUniform('level', 'float');
    }

    public function testTypesAreChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProcModeShaderRegistry::declareUniform('u_fx_level', 'dvec3');
    }

    public function testAUniformCannotAlsoBeASampler(): void
    {
        ProcModeShaderRegistry::declareUniform('u_fx_level', 'float');
        $this->expectException(\InvalidArgumentException::class);
        ProcModeShaderRegistry::declareSampler('u_fx_level');
    }

    /**
     * u_grass_shade exists because a uniform in both stages is not set in both on
     * D3D12: a snippet name that repeats an engine one is refused before it compiles.
     */
    public function testANameOfTheEngineIsRefusedInEitherStage(): void
    {
        $root = dirname(__DIR__, 2) . self::SHADERS . 'vio/';
        $vert = (string) file_get_contents($root . 'mesh3d.vert.glsl');
        $frag = (string) file_get_contents($root . 'mesh3d.frag.glsl');

        ProcModeShaderRegistry::declareUniform('u_fx_level', 'float');
        ProcModeShaderRegistry::assertUniqueUniforms($vert, $frag);

        foreach (['u_wind_flutter', 'u_roughness'] as $engineName) {
            ProcModeShaderRegistry::clear();
            ProcModeShaderRegistry::declareUniform($engineName, 'float');
            try {
                ProcModeShaderRegistry::assertUniqueUniforms($vert, $frag);
                self::fail("{$engineName} must be refused");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($engineName, $e->getMessage());
            }
        }
    }

    public function testTheNullRendererKeepsTheCommandsForAssertions(): void
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::identity(), Mat4::identity()));
        $list->add(new SetShaderParameter('u_fx_level', 0.5));
        $list->add(new SetShaderTexture('u_fx_map', 'mask'));
        $renderer = new NullRenderer3D();
        $renderer->render($list);
        self::assertCount(3, $list->getCommands());
    }
}
