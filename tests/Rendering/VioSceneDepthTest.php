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
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ShaderPath;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * A transparent surface reads the opaque depth behind it, forward and MRT. Copying
 * leaves the scene target's colour and depth alone, and without a consumer there is
 * no copy at all.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioSceneDepthTest extends TestCase
{
    private const SIZE = 64;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $feature = defined('VIO_FEATURE_RENDER_TARGET_DEPTH_SAMPLE') && vio_supports_feature($ctx, (int) constant('VIO_FEATURE_RENDER_TARGET_DEPTH_SAMPLE'));
        if (!$feature) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend cannot sample a colour target\'s depth');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MeshRegistry::register('wall', BoxMesh::generate(40.0, 40.0, 0.1));
        MeshRegistry::register('pane', BoxMesh::generate(1.0, 1.0, 0.01));
        MeshRegistry::register('post', BoxMesh::generate(0.6, 3.0, 0.25));
        MaterialRegistry::register('opaque_red', new Material(albedo: new Color(0.9, 0.05, 0.05), emission: new Color(0.9, 0.05, 0.05)));
        MaterialRegistry::register('depth_view', new Material(albedo: new Color(1.0, 1.0, 1.0), alpha: 0.5));
        MaterialRegistry::register('occluder', new Material(albedo: new Color(0.05, 0.9, 0.05), emission: new Color(0.05, 0.9, 0.05)));
        ProcModeRegistry::map('depth_view', 3);
        ProcModeShaderRegistry::registerGlsl(
            3,
            '',
            "    if (u_proc_mode == 3) {\n"
            . "        frag_color = outputColor(vec3(0.0, 0.0, clamp(sceneDepthDelta() / 10.0, 0.0, 1.0)), 1.0);\n"
            . "        return;\n"
            . "    } else",
        );
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_MRT');
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    private static function scene(float $paneZ, float $wallZ): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::identity(), Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 200.0)));
        $list->add(new DrawMesh('wall', 'opaque_red', Mat4::translation(0.0, 0.0, $wallZ)));
        // Nearer than the pane: it must still hide the pane after the target came back.
        $list->add(new DrawMesh('post', 'occluder', Mat4::translation(0.5, 0.0, $paneZ + 1.0)));
        $list->add(new DrawMesh('pane', 'depth_view', Mat4::translation(0.0, 0.0, $paneZ)->multiply(Mat4::scaling(40.0, 40.0, 1.0))));
        return $list;
    }

    private function renderer(): VioRenderer3D
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Medium,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        return $renderer;
    }

    /** @return list<int> */
    private static function px(string $rgba, int $x, int $y): array
    {
        return array_values((array) unpack('C4', substr($rgba, ($y * self::SIZE + $x) * 4, 4)));
    }

    /** The world distance a blue value stands for: the snippet's colour after ACES and gamma. */
    private static function blueToDelta(int $blue): float
    {
        $v = $blue / 255.0;
        $linear = pow($v, 2.2);
        // Invert the Narkowicz ACES curve numerically.
        $lo = 0.0;
        $hi = 1.0;
        for ($i = 0; $i < 40; $i++) {
            $mid = ($lo + $hi) / 2.0;
            $y = ($mid * (2.51 * $mid + 0.03)) / ($mid * (2.43 * $mid + 0.59) + 0.14);
            if ($y < $linear) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        return $lo * 10.0;
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return ['forward' => ['0'], 'mrt' => ['1']];
    }

    #[DataProvider('paths')]
    public function testATransparentSurfaceReadsTheOpaqueDepthBehindIt(string $mrt): void
    {
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        ProcModeShaderRegistry::requireSceneDepth();
        $renderer = $this->renderer();

        $near = self::px($renderer->renderToImage(self::scene(-5.0, -8.0), self::SIZE, self::SIZE), 8, 32);
        if ($mrt === '1' && !$renderer->mrtActive()) {
            $this->markTestSkipped('the MRT path needs G-buffer consumers, which are Direct3D only');
        }
        self::assertFalse($mrt === '0' && $renderer->mrtActive());
        $far = $renderer->renderToImage(self::scene(-5.0, -11.0), self::SIZE, self::SIZE);

        self::assertEqualsWithDelta(3.0, self::blueToDelta($near[2]), 0.35, 'wall 3 m behind the pane');
        self::assertEqualsWithDelta(6.0, self::blueToDelta(self::px($far, 8, 32)[2]), 0.5, 'wall 6 m behind the pane');

        // The target came back with colour and depth: the near post still hides the pane.
        $post = self::px($far, 39, 32);
        self::assertGreaterThan($post[2] + 60, $post[1], 'green post in front of the pane, no pane colour over it');
    }

    public function testAProgramCanReadItAlone(): void
    {
        ProcModeShaderRegistry::defineVariant('soft', [3], [ShaderPath::ProbeGi]);
        ProcModeShaderRegistry::requireSceneDepth(['soft']);
        self::assertContains('PHPOLYGON_SCENE_DEPTH', ProcModeShaderRegistry::variantDefines('soft'));
        self::assertNotContains('PHPOLYGON_SCENE_DEPTH', ProcModeShaderRegistry::variantDefines());
        self::assertSame(3, ProcModeShaderRegistry::samplerBudget('soft'));
        self::assertSame(1, ProcModeShaderRegistry::samplerBudget());

        $near = self::px($this->renderer()->renderToImage(self::scene(-5.0, -8.0), self::SIZE, self::SIZE), 8, 32);
        self::assertEqualsWithDelta(3.0, self::blueToDelta($near[2]), 0.35);
    }

    public function testNoConsumerNoCopy(): void
    {
        ProcModeShaderRegistry::clear();
        MaterialRegistry::register('depth_view', new Material(albedo: new Color(0.0, 0.0, 1.0), alpha: 0.5));
        $renderer = $this->renderer();
        $renderer->renderToImage(self::scene(-5.0, -8.0), self::SIZE, self::SIZE);
        $copy = new \ReflectionProperty(VioRenderer3D::class, 'sceneDepthCopy');
        self::assertNull($copy->getValue($renderer), 'no target without a consumer');
        self::assertFalse(ProcModeShaderRegistry::sceneDepthRequired());
    }
}
