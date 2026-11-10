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
use PHPolygon\Rendering\Command\SetImageLook;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\PostProcess\AutoExposure;
use PHPolygon\Rendering\PostProcess\ColorLut;
use PHPolygon\Rendering\PostProcess\ColorLutRegistry;
use PHPolygon\Rendering\PostProcess\FullscreenPass;
use PHPolygon\Rendering\PostProcess\FullscreenPassRegistry;
use PHPolygon\Rendering\PostProcess\ImageLook;
use PHPolygon\Rendering\PostProcess\PostStage;
use PHPolygon\Rendering\PostProcess\Tonemapper;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The image look on a real context: a grey wall with a small emissive square in front of
 * it, HDR scene target, FXAA. Read back after the frame and compared with the frame of the
 * default look or with the CPU model of the colour table.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioImageLookTest extends TestCase
{
    private const SIZE = 96;

    private ?\VioContext $ctx = null;
    private ?VioRenderer3D $renderer = null;
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
        FullscreenPassRegistry::clear();
        ColorLutRegistry::clear();
        MeshRegistry::register('wall', BoxMesh::generate(40.0, 40.0, 0.1));
        MeshRegistry::register('lamp', BoxMesh::generate(0.3, 0.3, 0.1));
        MaterialRegistry::register('grey', new Material(albedo: new Color(0.3, 0.3, 0.3), roughness: 1.0));
        MaterialRegistry::register('glow', new Material(albedo: new Color(0.0, 0.0, 0.0), emission: new Color(30.0, 30.0, 30.0), roughness: 1.0));
        $this->dir = sys_get_temp_dir() . '/phpolygon-look-' . getmypid();
        @mkdir($this->dir);
        $this->renderer = new VioRenderer3D($ctx, self::SIZE, self::SIZE);
    }

    protected function tearDown(): void
    {
        FullscreenPassRegistry::clear();
        ColorLutRegistry::clear();
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

    private static function settings(bool $bloom = false): GraphicsSettings
    {
        return new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Fxaa, bloom: $bloom, hdr: true);
    }

    /**
     * @param \Closure(int, RenderCommandList): void|null $frame
     */
    private function image(GraphicsSettings $settings, ?ImageLook $look, int $frames = 3, float $ambient = 0.3, ?\Closure $frame = null): string
    {
        self::assertNotNull($this->ctx);
        self::assertNotNull($this->renderer);
        $this->renderer->applySettings($settings);
        for ($i = 0; $i < $frames; $i++) {
            $list = new RenderCommandList();
            // Each image starts with a cut: the auto exposure takes the scene at once.
            $list->add(new SetCamera(Mat4::translation(0.0, 0.0, -3.0), Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 100.0), cut: $i === 0));
            $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), $ambient));
            $list->add(new SetDirectionalLight(new Vec3(0.0, 0.0, -1.0), new Color(1.0, 1.0, 1.0), 0.5));
            $list->add(new DrawMesh('wall', 'grey', Mat4::identity()));
            $list->add(new DrawMesh('lamp', 'glow', Mat4::translation(0.0, 0.0, 0.2)));
            if ($look !== null) {
                $list->add(new SetImageLook($look));
            }
            if ($frame !== null) {
                $frame($i, $list);
            }
            vio_begin($this->ctx);
            $this->renderer->beginFrame();
            $this->renderer->clear(new Color(0.0, 0.0, 0.0, 1.0));
            $this->renderer->render($list);
            $this->renderer->endFrame();
            vio_end($this->ctx);
        }
        $pixels = vio_read_pixels($this->ctx);
        self::assertIsString($pixels);
        return $pixels;
    }

    /** @return array{float, float, float} encoded 0..1 */
    private static function pixel(string $rgba, int $x, int $y): array
    {
        $p = array_map(intval(...), array_values((array) unpack('C4', substr($rgba, ($y * self::SIZE + $x) * 4, 4))));
        return [$p[0] / 255, $p[1] / 255, $p[2] / 255];
    }

    private static function luma(string $rgba, int $x, int $y): float
    {
        $p = self::pixel($rgba, $x, $y);
        return 0.2126 * $p[0] + 0.7152 * $p[1] + 0.0722 * $p[2];
    }

    public function testTheDefaultLookLeavesTheFrameByteForByte(): void
    {
        $none = $this->image(self::settings(true), null);
        self::assertSame(md5($none), md5($this->image(self::settings(true), new ImageLook())), 'SetImageLook(default)');
    }

    public function testAnInvertingTableInvertsTheFinishedImage(): void
    {
        ColorLutRegistry::register('same', ColorLut::identity(16));
        ColorLutRegistry::register('invert', ColorLut::fromFunction(static fn (float $r, float $g, float $b): array => [1.0 - $r, 1.0 - $g, 1.0 - $b], 16));
        $same = $this->image(self::settings(), new ImageLook(lut: 'same'));
        $inverted = $this->image(self::settings(), new ImageLook(lut: 'invert'));
        foreach ([[10, 10], [48, 48], [80, 30]] as [$x, $y]) {
            $a = self::pixel($same, $x, $y);
            $b = self::pixel($inverted, $x, $y);
            for ($c = 0; $c < 3; $c++) {
                self::assertEqualsWithDelta(1.0 - $a[$c], $b[$c], 3 / 255, "pixel {$x},{$y} channel {$c}");
            }
        }
        // Half strength lands in the middle.
        $half = $this->image(self::settings(), new ImageLook(lut: 'invert', lutStrength: 0.5));
        self::assertEqualsWithDelta(0.5, self::pixel($half, 10, 10)[0], 3 / 255);
    }

    /** @return iterable<string, array{GraphicsSettings}> */
    public static function presentPaths(): iterable
    {
        yield 'temporal present' => [new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Taa, bloom: true, hdr: true)];
        yield 'LDR scene without AA (no offscreen path before)' => [new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false, hdr: false)];
        yield 'LDR scene with bloom' => [new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Fxaa, bloom: true, hdr: false)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('presentPaths')]
    public function testTheTableReachesTheImageOnEveryPresentPath(GraphicsSettings $settings): void
    {
        ColorLutRegistry::register('same', ColorLut::identity(16));
        ColorLutRegistry::register('invert', ColorLut::fromFunction(static fn (float $r, float $g, float $b): array => [1.0 - $r, 1.0 - $g, 1.0 - $b], 16));
        $same = $this->image($settings, new ImageLook(lut: 'same'), 6);
        $inverted = $this->image($settings, new ImageLook(lut: 'invert'), 6);
        // A flat part of the wall: no edge for an AA to move.
        $a = self::pixel($same, 12, 12);
        $b = self::pixel($inverted, 12, 12);
        self::assertGreaterThan(0.1, $a[0], 'the wall is visible');
        for ($c = 0; $c < 3; $c++) {
            self::assertEqualsWithDelta(1.0 - $a[$c], $b[$c], 4 / 255, "channel {$c}");
        }
    }

    public function testTheIdentityTableKeepsTheImageOfTheChain(): void
    {
        ColorLutRegistry::register('same', ColorLut::identity(32));
        $plain = $this->image(self::settings(), new ImageLook(deband: 0.0001));
        $lut = $this->image(self::settings(), new ImageLook(lut: 'same'));
        foreach ([[10, 10], [48, 48], [80, 30]] as [$x, $y]) {
            $a = self::pixel($plain, $x, $y);
            $b = self::pixel($lut, $x, $y);
            for ($c = 0; $c < 3; $c++) {
                self::assertEqualsWithDelta($a[$c], $b[$c], 2 / 255, "pixel {$x},{$y} channel {$c}");
            }
        }
    }

    /**
     * Exposure, the tonemappers and bloom above its linear threshold act on the FP16 scene
     * path. The OpenGL family renders an LDR scene by design (forward surrogates, see
     * VioRenderer3D::screenSpaceEffectsSupported()): its colours come tonemapped from the
     * mesh shader and never exceed 1.
     */
    private function requireHdrScene(): void
    {
        self::assertNotNull($this->ctx);
        if (vio_backend_name($this->ctx) === 'opengl') {
            $this->markTestSkipped('OpenGL renders an LDR scene: exposure, tonemap and bloom above the threshold need the HDR scene path');
        }
    }

    public function testExposureBrightensAndTheSigmoidKeepsMidGrey(): void
    {
        $this->requireHdrScene();
        $base =$this->image(self::settings(), new ImageLook(deband: 0.0001));
        $up = $this->image(self::settings(), new ImageLook(exposureEv: 1.0));
        self::assertGreaterThan(self::luma($base, 10, 10) + 0.05, self::luma($up, 10, 10));
        $sigmoid = $this->image(self::settings(), new ImageLook(tonemapper: Tonemapper::Sigmoid));
        // Both curves put mid grey at about the same place: the wall stays within a few percent.
        self::assertEqualsWithDelta(self::luma($base, 10, 10), self::luma($sigmoid, 10, 10), 0.06);
    }

    public function testAutoExposureBringsADarkAndABrightSceneCloser(): void
    {
        $this->requireHdrScene();
        $fixedDark = self::luma($this->image(self::settings(), new ImageLook(deband: 0.0001), ambient: 0.05), 10, 10);
        $fixedBright = self::luma($this->image(self::settings(), new ImageLook(deband: 0.0001), ambient: 1.2), 10, 10);
        $auto = new ImageLook(autoExposure: new AutoExposure(reference: 0.1, minEv: -4.0, maxEv: 4.0));
        $autoDark = self::luma($this->image(self::settings(), $auto, ambient: 0.05), 10, 10);
        $autoBright = self::luma($this->image(self::settings(), $auto, ambient: 1.2), 10, 10);
        self::assertLessThan(($fixedBright - $fixedDark) * 0.5, abs($autoBright - $autoDark), 'the two scenes come closer');
        self::assertLessThan($autoBright, $autoDark, 'without changing places');
        // A higher reference brightens: the exposure follows reference / mean.
        $brighter = new ImageLook(autoExposure: new AutoExposure(reference: 0.4, minEv: -4.0, maxEv: 4.0));
        self::assertGreaterThan($autoDark + 0.05, self::luma($this->image(self::settings(), $brighter, ambient: 0.05), 10, 10));
    }

    public function testTheBloomChainSpreadsFurtherThanTheSingleBlur(): void
    {
        $this->requireHdrScene();
        $none = $this->image(self::settings(false), new ImageLook(deband: 0.0001));
        $single = $this->image(self::settings(true), new ImageLook(deband: 0.0001));
        $chain = $this->image(self::settings(true), new ImageLook(bloomLevels: 5, bloomScatter: 0.8));
        // 30 px from the lamp: the single half-resolution blur hardly reaches, the chain does.
        // 20 px from the lamp (measured on WARP: single blur +0, chain +3/255): only the chain reaches.
        $far = [48 + 20, 48];
        $base = self::luma($none, ...$far);
        self::assertEqualsWithDelta($base, self::luma($single, ...$far), 1.5 / 255);
        self::assertGreaterThan(2 / 255, self::luma($chain, ...$far) - $base);
        // Next to the lamp both glow.
        self::assertGreaterThan(self::luma($none, 48 + 9, 48) + 0.02, self::luma($chain, 48 + 9, 48));
    }

    public function testARunTimeSwitchedPassLeavesTheFrameAsWithoutIt(): void
    {
        $path = "{$this->dir}/red.frag.glsl";
        file_put_contents($path, "#version 410 core\nin vec2 v_uv;\nuniform sampler2D u_source;\nout vec4 frag_color;\n"
            . "void main() { frag_color = vec4(texture(u_source, v_uv).rgb * 0.0 + vec3(1.0, 0.0, 0.0), 1.0); }\n");
        $without = $this->image(self::settings(), null);
        FullscreenPassRegistry::register(new FullscreenPass('red', PostStage::Final, $path));
        $with = $this->image(self::settings(), null);
        self::assertEqualsWithDelta(1.0, self::pixel($with, 10, 10)[0], 1 / 255);
        FullscreenPassRegistry::setActive('red', false);
        self::assertSame(md5($without), md5($this->image(self::settings(), null)));
        FullscreenPassRegistry::setActive('red', true);
        self::assertSame(md5($with), md5($this->image(self::settings(), null)));
    }
}
