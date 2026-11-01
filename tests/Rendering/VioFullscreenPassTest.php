<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\PostProcess\FullscreenPass;
use PHPolygon\Rendering\PostProcess\FullscreenPassRegistry;
use PHPolygon\Rendering\PostProcess\PostStage;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Fullscreen passes in the running chain: nothing registered or switched off leaves
 * the frame untouched, a pass changes it at its stage, passes chain in order, and a
 * pass whose gate fails drops out of the chain.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioFullscreenPassTest extends TestCase
{
    private const SIZE = 48;

    private const HEAD = "#version 410 core\nin vec2 v_uv;\nuniform sampler2D u_source;\nout vec4 frag_color;\n";

    private ?\VioContext $ctx = null;
    private ?VioRenderer3D $renderer = null;
    private string $dir = '';

    protected function setUp(): void
    {
        $ctx = @vio_create('auto', [
            'width' => self::SIZE, 'height' => self::SIZE, 'headless' => true, 'vsync' => false,
            'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache',
        ]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        FullscreenPassRegistry::clear();
        MeshRegistry::register('wall', BoxMesh::generate(40.0, 40.0, 0.1));
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('grey', new Material(albedo: new Color(0.3, 0.3, 0.3), roughness: 1.0));
        $this->dir = sys_get_temp_dir() . '/phpolygon-pass-' . getmypid();
        @mkdir($this->dir);
        $this->renderer = new VioRenderer3D($ctx, self::SIZE, self::SIZE);
        $this->renderer->applySettings(self::settings());
    }

    protected function tearDown(): void
    {
        FullscreenPassRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::clear();
        foreach ((array) glob($this->dir . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->dir);
        $this->renderer = null;
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    private static function settings(?AntiAliasing $aa = null): GraphicsSettings
    {
        return new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: $aa ?? AntiAliasing::Fxaa, bloom: false);
    }

    private function fragment(string $name, string $main, string $extra = ''): string
    {
        $path = "{$this->dir}/{$name}.frag.glsl";
        file_put_contents($path, self::HEAD . $extra . "void main() {\n    vec3 c = texture(u_source, v_uv).rgb;\n{$main}\n}\n");
        return $path;
    }

    /** @return list<int> centre pixel of the last of three presented frames */
    private function frame(?RenderCommandList $list = null): array
    {
        return self::centre($this->image($list));
    }

    private function image(?RenderCommandList $list = null): string
    {
        self::assertNotNull($this->ctx);
        self::assertNotNull($this->renderer);
        $list ??= self::scene();
        for ($i = 0; $i < 3; $i++) {
            vio_begin($this->ctx);
            $this->renderer->beginFrame();
            $this->renderer->render($list);
            $this->renderer->endFrame();
            vio_end($this->ctx);
        }
        $pixels = vio_read_pixels($this->ctx);
        self::assertIsString($pixels);
        return $pixels;
    }

    private static function scene(): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::translation(0.0, 0.0, -3.0), Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 100.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.3));
        $list->add(new SetDirectionalLight(new Vec3(0.0, 0.0, -1.0), new Color(1.0, 1.0, 1.0), 0.5));
        $list->add(new DrawMesh('wall', 'grey', Mat4::identity()));
        return $list;
    }

    /** @return list<int> */
    private static function centre(string $rgba): array
    {
        return array_values((array) unpack('C4', substr($rgba, ((self::SIZE >> 1) * self::SIZE + (self::SIZE >> 1)) * 4, 4)));
    }

    public function testAPassThatIsOffLeavesTheFrameByteForByte(): void
    {
        $reference = $this->image();

        FullscreenPassRegistry::register(new FullscreenPass('red', PostStage::Final, $this->fragment('red', '    frag_color = vec4(1.0, 0.0, 0.0, 1.0);'), default: false));
        self::assertSame(md5($reference), md5($this->image()), 'registered but off');
    }

    public function testEachStageChangesTheFrame(): void
    {
        $this->image();
        foreach ([PostStage::Scene, PostStage::Display, PostStage::Final] as $stage) {
            FullscreenPassRegistry::clear();
            $name = 'blue_' . $stage->value;
            FullscreenPassRegistry::register(new FullscreenPass($name, $stage, $this->fragment($name, '    frag_color = vec4(0.0, 0.0, 1.0, 1.0);')));
            $px = $this->frame();
            self::assertGreaterThan(100, $px[2], "{$stage->value} reached the screen");
            self::assertLessThan(60, $px[0], "{$stage->value} replaced the image");
        }
    }

    public function testPassesChainInOrderAndReadParameters(): void
    {
        FullscreenPassRegistry::register(new FullscreenPass('second', PostStage::Final, $this->fragment('second', '    frag_color = vec4(c.g, 0.0, u_fx_blue, 1.0);', "uniform float u_fx_blue;\n"), order: 2));
        FullscreenPassRegistry::register(new FullscreenPass('first', PostStage::Final, $this->fragment('first', '    frag_color = vec4(0.0, 0.8, 0.0, 1.0);'), order: 1));
        $list = self::scene();
        $list->add(new SetShaderParameter('u_fx_blue', 0.5));
        $px = $this->frame($list);
        self::assertEqualsWithDelta(204, $px[0], 3, 'the second pass read what the first wrote');
        self::assertSame(0, $px[1]);
        self::assertEqualsWithDelta(128, $px[2], 3, 'parameter from the command list');
    }

    public function testAPassReadsTheSceneDepth(): void
    {
        FullscreenPassRegistry::register(new FullscreenPass(
            'depth_view',
            PostStage::Scene,
            $this->fragment('depth_view', '    frag_color = vec4(vec3(step(texture(u_scene_depth_raw, v_uv).r, 0.99995)), 1.0);', "uniform sampler2D u_scene_depth_raw;\n"),
            depth: true,
        ));
        self::assertNotNull($this->renderer);
        self::assertGreaterThan(200, $this->frame()[1], 'the wall is nearer than the far plane');

        $empty = new RenderCommandList();
        $empty->add(new SetCamera(Mat4::translation(0.0, 0.0, -3.0), Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 100.0)));
        self::assertLessThan(10, $this->frame($empty)[1], 'nothing drawn: far plane');
    }

    public function testGatesTakeAPassOutOfTheChain(): void
    {
        $reference = $this->image();

        $red = $this->fragment('red2', '    frag_color = vec4(1.0, 0.0, 0.0, 1.0);');
        FullscreenPassRegistry::register(new FullscreenPass('needs_feature', PostStage::Final, $red, features: ['VIO_FEATURE_NOT_A_FEATURE']));
        self::assertNotNull($this->renderer);
        self::assertFalse($this->renderer->fullscreenPassAvailable('needs_feature'));
        self::assertSame(md5($reference), md5($this->image()), 'missing feature');

        FullscreenPassRegistry::clear();
        FullscreenPassRegistry::register(new FullscreenPass('missing_file', PostStage::Final, $this->dir . '/nothing.glsl'));
        self::assertFalse($this->renderer->fullscreenPassAvailable('missing_file'));
        self::assertSame(md5($reference), md5($this->image()), 'a pass that cannot compile');
    }
}
