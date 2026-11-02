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
use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\Command\SetShaderTexture;
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
use PHPolygon\Rendering\VioTextureManager;

/**
 * A snippet reads a declared uniform and two declared samplers, in the forward
 * pipeline, the MRT pipeline and the MRT + motion pipeline. The samplers carry
 * different textures, so a slot mix-up shows.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioSnippetUniformTest extends TestCase
{
    private const SIZE = 32;

    private ?\VioContext $ctx = null;
    private string $dir = '';

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
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MeshRegistry::register('wall', BoxMesh::generate(40.0, 40.0, 0.1));
        MaterialRegistry::register('painted', new Material(albedo: new Color(1.0, 1.0, 1.0)));
        ProcModeRegistry::map('painted', 3);
        ProcModeShaderRegistry::declareUniform('u_fx_level', 'float');
        ProcModeShaderRegistry::declareUniform('u_fx_tint', 'vec3');
        // Two samplers fit a program that gave up the probe fields.
        ProcModeShaderRegistry::defineVariant('fx', [3], [ShaderPath::ProbeGi]);
        ProcModeShaderRegistry::declareSampler('u_fx_map_a', ['fx']);
        ProcModeShaderRegistry::declareSampler('u_fx_map_b', ['fx']);
        ProcModeShaderRegistry::registerGlsl(
            3,
            '',
            "    if (u_proc_mode == 3) {\n"
            . "        vec3 fxA = texture(u_fx_map_a, vec2(0.5)).rgb;\n"
            . "        vec3 fxB = texture(u_fx_map_b, vec2(0.5)).rgb;\n"
            . "        frag_color = outputColor(u_fx_tint * u_fx_level + fxA * vec3(0.5, 0.0, 0.0) + fxB * vec3(0.0, 0.0, 0.5), 1.0);\n"
            . "        return;\n"
            . "    } else",
        );
        $this->dir = sys_get_temp_dir() . '/phpolygon-snippet-' . getmypid();
        @mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_MRT');
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::clear();
        foreach ((array) glob($this->dir . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->dir);
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    private static function scene(float $level, ?string $mapA = null, ?string $mapB = null): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::translation(0.0, 0.0, -3.0), Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 100.0)));
        $list->add(new SetShaderParameter('u_fx_level', $level));
        $list->add(new SetShaderParameter('u_fx_tint', [0.0, 1.0, 0.0]));
        $list->add(new SetShaderTexture('u_fx_map_a', $mapA));
        $list->add(new SetShaderTexture('u_fx_map_b', $mapB));
        $list->add(new DrawMesh('wall', 'painted', Mat4::identity()));
        return $list;
    }

    private function renderer(AntiAliasing $aa = AntiAliasing::Off): VioRenderer3D
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->setTextureManager($this->textures());
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Medium,
            antiAliasing: $aa,
            bloom: false,
        ));
        return $renderer;
    }

    private function textures(): VioTextureManager
    {
        self::assertNotNull($this->ctx);
        $manager = new VioTextureManager($this->ctx);
        $image = imagecreatetruecolor(4, 4);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagepng($image, $this->dir . '/white.png');
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 0, 0, 0));
        imagepng($image, $this->dir . '/black.png');
        $manager->load($this->dir . '/white.png');
        $manager->load($this->dir . '/black.png');
        return $manager;
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return ['forward' => ['0'], 'mrt' => ['1']];
    }

    #[DataProvider('paths')]
    public function testValuesReachTheSnippet(string $mrt): void
    {
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        $renderer = $this->renderer();
        $white = $this->dir . '/white.png';
        $black = $this->dir . '/black.png';

        $a = self::centre($renderer->renderToImage(self::scene(0.0, $white, $black), self::SIZE, self::SIZE));
        if ($mrt === '1' && !$renderer->mrtActive()) {
            $this->markTestSkipped('the MRT path needs G-buffer consumers, which are Direct3D only');
        }
        self::assertFalse($mrt === '0' && $renderer->mrtActive());
        $b = self::centre($renderer->renderToImage(self::scene(0.0, $black, $white), self::SIZE, self::SIZE));
        $c = self::centre($renderer->renderToImage(self::scene(1.0, $black, $black), self::SIZE, self::SIZE));
        $d = self::centre($renderer->renderToImage(self::scene(0.0, null, null), self::SIZE, self::SIZE));

        self::assertGreaterThan(60, $a[0], 'first sampler is its own texture');
        self::assertLessThan(10, $a[2]);
        self::assertLessThan(10, $b[0], 'second sampler is its own texture');
        self::assertGreaterThan(60, $b[2]);
        self::assertGreaterThan(100, $c[1], 'uniform reaches the snippet');
        self::assertLessThan(10, $c[0]);
        self::assertGreaterThan(60, $d[0], 'an unbound sampler reads white');
        self::assertGreaterThan(60, $d[2], 'an unbound sampler reads white');
    }

    public function testMotionPipelineTakesThemToo(): void
    {
        self::assertNotNull($this->ctx);
        $supported = defined('VIO_FEATURE_RENDER_TARGET_DEPTH_SAMPLE') && vio_supports_feature($this->ctx, (int) constant('VIO_FEATURE_RENDER_TARGET_DEPTH_SAMPLE'));
        if (!$supported) {
            $this->markTestSkipped('no temporal path on this backend');
        }
        $renderer = $this->renderer(AntiAliasing::Taa);
        $list = self::scene(1.0, $this->dir . '/black.png', $this->dir . '/black.png');
        for ($i = 0; $i < 2; $i++) {
            vio_begin($this->ctx);
            $renderer->beginFrame();
            $renderer->render($list);
            $renderer->endFrame();
            vio_end($this->ctx);
        }
        self::assertNotNull($renderer->temporalFrame(), 'the frame took the motion pipeline');
        $pixels = vio_read_pixels($this->ctx);
        self::assertIsString($pixels);
        $px = self::centre($pixels);
        self::assertGreaterThan(100, $px[1], 'uniform reaches the snippet in the motion pipeline');
        self::assertLessThan(20, $px[0]);
    }

    /** @return list<int> */
    private static function centre(string $rgba): array
    {
        return array_values((array) unpack('C4', substr($rgba, ((self::SIZE >> 1) * self::SIZE + (self::SIZE >> 1)) * 4, 4)));
    }
}
