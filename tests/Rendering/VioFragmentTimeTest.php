<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The fragment stage of the mesh programs sees the animation time. u_time is declared
 * in both stages of mesh3d; php-vio's cbuffer backends (D3D11, D3D12, Vulkan, Metal)
 * write such a name into the vertex stage only, so a snippet animating with u_time
 * stood still there. A snippet paints fract(u_time / 10) as grey and is compared with
 * a snippet that paints the expected grey as a constant - on the base program, a
 * shader variant and the MRT path.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioFragmentTimeTest extends TestCase
{
    private const SIZE = 32;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('wall', BoxMesh::generate(40.0, 40.0, 0.1));
        $modes = ['clock_base' => 3, 'clock_variant' => 5, 'grey_base' => 6, 'grey_variant' => 7];
        foreach ($modes as $material => $mode) {
            MaterialRegistry::register($material, new Material(albedo: new Color(0.5, 0.5, 0.5)));
            ProcModeRegistry::map($material, $mode);
        }
        $branch = static fn (int $mode, string $grey): string => "    if (u_proc_mode == {$mode}) {\n"
            . "        frag_color = outputColor(vec3({$grey}), alpha);\n        return;\n    } else";
        ProcModeShaderRegistry::registerGlsl(3, '', $branch(3, 'fract(u_time * 0.1)'));
        ProcModeShaderRegistry::registerGlsl(5, '', $branch(5, 'fract(u_time * 0.1)'));
        ProcModeShaderRegistry::registerGlsl(6, '', $branch(6, 'u_expected_grey'));
        ProcModeShaderRegistry::registerGlsl(7, '', $branch(7, 'u_expected_grey'));
        ProcModeShaderRegistry::declareUniform('u_expected_grey', 'float');
        ProcModeShaderRegistry::defineVariant('clock', [5, 7]);
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_MRT');
        putenv('PHPOLYGON_SSAO');
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    /** @return iterable<string, array{string}> */
    public static function programs(): iterable
    {
        yield 'base program' => ['base'];
        yield 'shader variant' => ['variant'];
    }

    #[DataProvider('programs')]
    public function testSnippetReadsTheAnimationTimeInTheFragmentStage(string $program): void
    {
        putenv('PHPOLYGON_VIO_MRT=0');
        $renderer = $this->renderer(new GraphicsSettings(shadowQuality: ShadowQuality::Off, bloom: false));
        $this->assertClockFollowsTime($renderer, $program);
    }

    public function testSnippetReadsTheAnimationTimeOnTheMrtPath(): void
    {
        self::assertNotNull($this->ctx);
        if (vio_backend_name($this->ctx) === 'opengl' || !defined('VIO_FEATURE_MRT') || !vio_supports_feature($this->ctx, VIO_FEATURE_MRT)) {
            $this->markTestSkipped('backend has no MRT scene path');
        }
        // SSAO puts the frame on the MRT path (see VioRendererMrtTest).
        putenv('PHPOLYGON_SSAO=1');
        putenv('PHPOLYGON_VIO_MRT=1');
        $renderer = $this->renderer(new GraphicsSettings(shadowQuality: ShadowQuality::Off, ambientOcclusion: ScreenSpaceAO::Medium, bloom: false));
        $this->assertClockFollowsTime($renderer, 'base', mrt: true);
        $this->assertClockFollowsTime($renderer, 'variant', mrt: true);
    }

    private function assertClockFollowsTime(VioRenderer3D $renderer, string $program, bool $mrt = false): void
    {
        self::assertNotNull($this->ctx);
        $backend = vio_backend_name($this->ctx);
        foreach ([3.0, 6.0] as $t) {
            $clock = $this->centre($renderer, "clock_{$program}", $t, 0.0);
            $expected = $this->centre($renderer, "grey_{$program}", $t, fmod($t * 0.1, 1.0));
            self::assertSame($mrt, $renderer->mrtActive(), 'render path');
            self::assertGreaterThan(20, $expected, 'the expected grey is visible');
            self::assertEqualsWithDelta($expected, $clock, 2, "{$backend}, {$program}, t = {$t}: fract(u_time / 10) in the fragment stage");
        }
        self::assertLessThan(3, $this->centre($renderer, "clock_{$program}", 0.0, 0.0), "{$backend}, {$program}: black at t = 0");
    }

    private function renderer(GraphicsSettings $settings): VioRenderer3D
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings($settings);
        return $renderer;
    }

    /** Red channel (0..255) of the centre pixel of the wall drawn with $material at time $t. */
    private function centre(VioRenderer3D $renderer, string $material, float $t, float $expectedGrey): int
    {
        $renderer->setAnimationTime($t);
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::translation(0.0, 0.0, -3.0), Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 100.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.3));
        $list->add(new SetShaderParameter('u_expected_grey', $expectedGrey));
        $list->add(new DrawMesh('wall', $material, Mat4::identity()));
        // The second frame is read: the OpenGL backend reads back black on a renderer's first.
        $renderer->renderToImage($list, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0, 1.0));
        $rgba = $renderer->renderToImage($list, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0, 1.0));
        $half = self::SIZE >> 1;
        return ord($rgba[($half * self::SIZE + $half) * 4]);
    }
}
