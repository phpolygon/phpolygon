<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat3;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\Effect2D\Effect2D;
use PHPolygon\Rendering\Effect2D\Effect2DRegistry;
use PHPolygon\Rendering\Effects\EffectDefinition;
use PHPolygon\Rendering\Effects\EffectFallback;
use PHPolygon\Rendering\Effects\EffectRegistry;
use PHPolygon\Rendering\Effects\EffectTier;
use PHPolygon\Rendering\Effects\FlashClass;
use PHPolygon\Rendering\Effects\ReducedMotion;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShaderQuality;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ScaledRenderer2D;
use PHPolygon\Rendering\Texture;
use PHPolygon\Rendering\VioRenderer2D;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\UI\UIScale;

/**
 * 2D effects in a running VioRenderer2D: nothing registered or switched off leaves the
 * frame byte for byte, an effect lands at its place in the draw order with v_uv (0, 0)
 * at the top left, reads parameters, textures and the time, follows transform and
 * scale, gives way to its fallback where it cannot run, and draws over a 3D frame.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioEffect2DTest extends TestCase
{
    private const SIZE = 64;

    private const HEAD = "#version 410 core\nin vec2 v_uv;\nuniform vec2 u_resolution;\nuniform vec4 u_rect;\nuniform float u_time;\nuniform float u_motion;\nout vec4 frag_color;\n";

    private ?\VioContext $ctx = null;
    private ?VioRenderer2D $renderer = null;
    private string $dir = '';

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        Effect2DRegistry::clear();
        EffectRegistry::clear();
        $this->dir = sys_get_temp_dir() . '/phpolygon-effect2d-' . getmypid();
        @mkdir($this->dir);
        $this->renderer = new VioRenderer2D($ctx);
    }

    protected function tearDown(): void
    {
        Effect2DRegistry::clear();
        EffectRegistry::clear();
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

    private function fragment(string $name, string $main, string $extra = ''): string
    {
        $path = "{$this->dir}/{$name}.frag.glsl";
        file_put_contents($path, self::HEAD . $extra . "void main() {\n{$main}\n}\n");
        return $path;
    }

    private function gradient(string $name = 'gradient', float $padding = 0.0, ?\Closure $fallback = null, array $features = []): void
    {
        Effect2DRegistry::register(new Effect2D(
            $name,
            $this->fragment($name, '    frag_color = vec4(v_uv.x, v_uv.y, u_fx_blue, 1.0);', "uniform float u_fx_blue;\n"),
            padding: $padding,
            features: $features,
            fallback: $fallback,
        ));
    }

    /** @param \Closure(Renderer2DInterface): void $draw */
    private function image(\Closure $draw, float $time = 0.0): string
    {
        self::assertNotNull($this->ctx);
        self::assertNotNull($this->renderer);
        for ($i = 0; $i < 3; $i++) {
            $this->renderer->setAnimationTime($time);
            $this->renderer->beginFrame();
            $draw($this->renderer);
            $this->renderer->endFrame();
        }
        $pixels = vio_read_pixels($this->ctx);
        self::assertIsString($pixels);
        return $pixels;
    }

    /** @return list<int> */
    private static function at(string $rgba, int $x, int $y): array
    {
        return array_values((array) unpack('C4', substr($rgba, ($y * self::SIZE + $x) * 4, 4)));
    }

    private static function background(Renderer2DInterface $r): void
    {
        $r->drawRect(0, 0, self::SIZE, self::SIZE, new Color(1.0, 0.0, 0.0, 1.0));
    }

    public function testNothingRegisteredOrSwitchedOffLeavesTheFrameByteForByte(): void
    {
        $scene = function (Renderer2DInterface $r): void {
            self::background($r);
            $r->drawRoundedRect(8, 8, 40, 30, 6, new Color(0.1, 0.6, 0.9, 0.7));
            Effect2DRegistry::draw($r, 'gradient', 16, 16, 32, 32, ['u_fx_blue' => 1.0]);
            $r->drawCircle(40, 44, 10, new Color(0.9, 0.9, 0.1, 1.0));
        };
        $reference = $this->image($scene);

        $this->gradient();
        self::assertNotNull($this->renderer);
        $this->renderer->applyEffectSettings((new GraphicsSettings())->with(effects: ['gradient' => false]));
        self::assertSame(md5($reference), md5($this->image($scene)), 'registered but off');

        $this->renderer->applyEffectSettings(new GraphicsSettings());
        self::assertNotSame(md5($reference), md5($this->image($scene)), 'on: the effect is drawn');
    }

    public function testAnEffectLandsAtItsPlaceInTheDrawOrder(): void
    {
        $this->gradient(padding: 4.0);
        $px = $this->image(function (Renderer2DInterface $r): void {
            self::background($r);
            Effect2DRegistry::draw($r, 'gradient', 20, 20, 24, 24, ['u_fx_blue' => 0.5]);
            $r->drawRect(40, 40, 8, 8, new Color(0.0, 0.0, 1.0, 1.0));
        });

        self::assertSame([255, 0, 0, 255], self::at($px, 4, 4), 'drawn before: still there outside the effect');
        self::assertSame([0, 0, 255, 255], self::at($px, 44, 44), 'drawn after: on top of the effect');
        // Padded quad 16..48: top left dark, top right red, bottom left green.
        $tl = self::at($px, 17, 17);
        $tr = self::at($px, 38, 17);
        $bl = self::at($px, 17, 38);
        self::assertLessThan(20, $tl[0]);
        self::assertLessThan(20, $tl[1]);
        self::assertGreaterThan(150, $tr[0], 'v_uv.x grows to the right');
        self::assertLessThan(20, $tr[1]);
        self::assertGreaterThan(150, $bl[1], 'v_uv.y grows downwards');
        self::assertEqualsWithDelta(128, $tl[2], 3, 'parameter reached the shader');
        self::assertSame([255, 0, 0, 255], self::at($px, 14, 30), 'nothing outside the padding');
    }

    public function testTheRectangleInsideThePaddingAndTheResolution(): void
    {
        Effect2DRegistry::register(new Effect2D('rect', $this->fragment('rect', implode("\n", [
            '    float inside = step(u_rect.x, v_uv.x) * step(v_uv.x, u_rect.z) * step(u_rect.y, v_uv.y) * step(v_uv.y, u_rect.w);',
            '    frag_color = vec4(inside, u_resolution.x / 64.0, 0.0, 1.0);',
        ])), padding: 8.0));
        $px = $this->image(function (Renderer2DInterface $r): void {
            Effect2DRegistry::draw($r, 'rect', 24, 24, 16, 16);
        });
        self::assertGreaterThan(200, self::at($px, 32, 32)[0], 'inside the rectangle');
        self::assertLessThan(50, self::at($px, 19, 32)[0], 'in the padding');
        self::assertEqualsWithDelta(128, self::at($px, 32, 32)[1], 3, 'u_resolution = 32 pixels of padded quad');
    }

    public function testAnEffectSamplesATexture(): void
    {
        self::assertNotNull($this->ctx);
        self::assertNotNull($this->renderer);
        // Left half green, right half blue.
        $data = '';
        for ($y = 0; $y < 4; $y++) {
            $data .= str_repeat(pack('C4', 0, 255, 0, 255), 2) . str_repeat(pack('C4', 0, 0, 255, 255), 2);
        }
        $texture = vio_texture($this->ctx, ['data' => $data, 'width' => 4, 'height' => 4]);
        self::assertInstanceOf(\VioTexture::class, $texture);
        $this->renderer->registerVioTexture(9001, $texture);
        Effect2DRegistry::register(new Effect2D('swap', $this->fragment('swap', '    vec4 c = texture(u_image, v_uv);' . "\n" . '    frag_color = vec4(c.b, c.g, 0.0, 1.0);', "uniform sampler2D u_image;\n"), samplers: ['u_image']));

        $px = $this->image(function (Renderer2DInterface $r): void {
            Effect2DRegistry::draw($r, 'swap', 0, 0, 64, 64, [], ['u_image' => new Texture(9001, 4, 4)]);
        });
        self::assertSame([0, 255, 0, 255], self::at($px, 16, 32), 'green stays green');
        self::assertSame([255, 0, 0, 255], self::at($px, 48, 32), 'blue became red');

        // Without a texture the sampler reads white.
        $px = $this->image(function (Renderer2DInterface $r): void {
            Effect2DRegistry::draw($r, 'swap', 0, 0, 64, 64);
        });
        self::assertSame([255, 255, 0, 255], self::at($px, 32, 32));
    }

    public function testTimeAndReducedMotion(): void
    {
        $fallbacks = 0;
        Effect2DRegistry::register(new Effect2D('pulse', $this->fragment('pulse', '    frag_color = vec4(fract(u_time), u_motion, 0.0, 1.0);'), fallback: function (Renderer2DInterface $r, float $x, float $y, float $w, float $h) use (&$fallbacks): void {
            $fallbacks++;
            $r->drawRect($x, $y, $w, $h, new Color(0.0, 0.0, 1.0, 1.0));
        }));
        $draw = fn (Renderer2DInterface $r) => Effect2DRegistry::draw($r, 'pulse', 0, 0, 64, 64);

        $px = self::at($this->image($draw, 2.5), 32, 32);
        self::assertEqualsWithDelta(128, $px[0], 2, 'u_time follows the animation clock');
        self::assertSame(255, $px[1]);

        EffectRegistry::setReducedMotion(true, 0.25);
        EffectRegistry::register(new EffectDefinition('pulse', EffectTier::Low, null, EffectFallback::Same, EffectFallback::Off, ReducedMotion::Frozen, FlashClass::Gentle));
        self::assertLessThan(2, self::at($this->image($draw, 2.5), 32, 32)[0], 'frozen: u_time held at 0');

        EffectRegistry::clear();
        EffectRegistry::setReducedMotion(true, 0.25);
        EffectRegistry::register(new EffectDefinition('pulse', EffectTier::Low, null, EffectFallback::Same, EffectFallback::Off, ReducedMotion::Reduced, FlashClass::Gentle));
        $px = self::at($this->image($draw, 2.5), 32, 32);
        self::assertEqualsWithDelta(128, $px[0], 2, 'reduced: time runs');
        self::assertEqualsWithDelta(64, $px[1], 2, 'reduced: u_motion is the game\'s scale');

        EffectRegistry::clear();
        EffectRegistry::setReducedMotion(true);
        EffectRegistry::register(new EffectDefinition('pulse', EffectTier::Low, null, EffectFallback::Same, EffectFallback::Off, ReducedMotion::Off, FlashClass::Gentle));
        self::assertSame([0, 0, 255, 255], self::at($this->image($draw, 2.5), 32, 32), 'off: the fallback draws');
        self::assertGreaterThan(0, $fallbacks);
    }

    public function testGatesHandTheRectangleToTheFallback(): void
    {
        $fallback = function (Renderer2DInterface $r, float $x, float $y, float $w, float $h): void {
            $r->drawRect($x, $y, $w, $h, new Color(0.0, 1.0, 1.0, 1.0));
        };
        $this->gradient('needs_feature', fallback: $fallback, features: ['VIO_FEATURE_NOT_A_FEATURE']);
        Effect2DRegistry::register(new Effect2D('broken', $this->fragment('broken', '    frag_color = vec4(not_declared, 1.0);'), fallback: $fallback));
        self::assertNotNull($this->renderer);
        self::assertFalse($this->renderer->effectAvailable('needs_feature'));
        self::assertFalse(@$this->renderer->effectAvailable('broken'));

        foreach (['needs_feature', 'broken'] as $name) {
            $px = @$this->image(function (Renderer2DInterface $r) use ($name): void {
                self::background($r);
                Effect2DRegistry::draw($r, $name, 16, 16, 32, 32);
            });
            self::assertSame([0, 255, 255, 255], self::at($px, 32, 32), "{$name}: fallback in its place");
            self::assertSame([255, 0, 0, 255], self::at($px, 4, 4));
        }

        // Shader quality Unlit takes out an effect whose entry does not run there as it is.
        $this->gradient('unlit_off', fallback: $fallback);
        EffectRegistry::register(new EffectDefinition('unlit_off', EffectTier::Low, null, EffectFallback::Off, EffectFallback::Off, ReducedMotion::Static, FlashClass::None));
        $this->renderer->applyEffectSettings(new GraphicsSettings(shaderQuality: ShaderQuality::Unlit));
        $px = $this->image(fn (Renderer2DInterface $r) => Effect2DRegistry::draw($r, 'unlit_off', 0, 0, 64, 64));
        self::assertSame([0, 255, 255, 255], self::at($px, 32, 32));
    }

    public function testTransformAndScaleMoveTheEffect(): void
    {
        $this->gradient();
        $px = $this->image(function (Renderer2DInterface $r): void {
            self::background($r);
            $r->pushTransform(Mat3::translation(32, 32));
            Effect2DRegistry::draw($r, 'gradient', 0, 0, 16, 16, ['u_fx_blue' => 1.0]);
            $r->popTransform();
        });
        self::assertSame([255, 0, 0, 255], self::at($px, 20, 20), 'not at the untransformed place');
        self::assertGreaterThan(200, self::at($px, 40, 40)[2], 'moved by the transform');

        $scale = new UIScale(2.0);
        self::assertNotNull($this->renderer);
        $scaled = new ScaledRenderer2D($this->renderer, $scale);
        $px = $this->image(function () use ($scaled): void {
            self::background($scaled);
            Effect2DRegistry::draw($scaled, 'gradient', 8, 8, 8, 8, ['u_fx_blue' => 1.0]);
        });
        self::assertSame([255, 0, 0, 255], self::at($px, 12, 12), 'logical 8..16 is physical 16..32');
        self::assertGreaterThan(200, self::at($px, 24, 24)[2]);
    }

    public function testAnEffectDrawsOverA3dFrame(): void
    {
        self::assertNotNull($this->ctx);
        self::assertNotNull($this->renderer);
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MeshRegistry::register('floor', BoxMesh::generate(10.0, 0.2, 10.0));
        MaterialRegistry::register('grey', new Material(albedo: new Color(0.5, 0.5, 0.5), roughness: 0.8));
        MaterialRegistry::register('red', new Material(albedo: new Color(0.8, 0.1, 0.1), roughness: 0.4));
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::lookAt(new Vec3(3.0, 2.5, 4.0), new Vec3(0.0, 0.3, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 100.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.3));
        $list->add(new SetDirectionalLight(new Vec3(-0.4, -1.0, -0.3), new Color(1.0, 1.0, 1.0), 1.0));
        $list->add(new DrawMesh('floor', 'grey', Mat4::translation(0.0, -0.1, 0.0)));
        $list->add(new DrawMesh('box', 'red', Mat4::translation(0.0, 0.5, 0.0)));
        $this->gradient();

        // Straight into the swapchain, and through the offscreen chain (FXAA) whose
        // present leaves the swapchain bound before the 2D batch.
        foreach ([AntiAliasing::Off, AntiAliasing::Fxaa] as $aa) {
            $r3d = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
            $r3d->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: $aa, bloom: false));
            $r3d->deferSceneTargetBind();
            $frame = function (bool $effect) use ($r3d, $list): string {
                self::assertNotNull($this->renderer);
                for ($i = 0; $i < 3; $i++) {
                    $r3d->beginFrame();
                    $this->renderer->beginFrame();
                    $r3d->frameOpened();
                    $r3d->render($list);
                    if ($effect) {
                        Effect2DRegistry::draw($this->renderer, 'gradient', 32, 0, 32, 32, ['u_fx_blue' => 1.0]);
                    }
                    $r3d->endFrame();
                    $this->renderer->endFrame();
                }
                return (string) vio_read_pixels($this->ctx);
            };
            $plain = $frame(false);
            $with = $frame(true);
            if ($aa === AntiAliasing::Off) {
                $box = self::at($plain, 30, 36);
                self::assertGreaterThan($box[1] + 40, $box[0], 'the red box of the 3D frame');
            }
            self::assertSame(self::at($plain, 30, 36), self::at($with, 30, 36), "{$aa->value}: the 3D frame outside the effect stays");
            self::assertSame(self::at($plain, 8, 56), self::at($with, 8, 56));
            self::assertGreaterThan(200, self::at($with, 48, 16)[2], "{$aa->value}: the effect is on top of the 3D frame");
        }
    }
}
