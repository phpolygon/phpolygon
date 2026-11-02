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
use PHPolygon\Rendering\Accessibility\AccessibilitySettings;
use PHPolygon\Rendering\Accessibility\ColorVision;
use PHPolygon\Rendering\Accessibility\ColorVisionMode;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
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
 * The accessibility pass on a real context. A Final-stage test pass paints known
 * colours, the accessibility pass runs after it, and the read-back is compared with
 * the CPU model in Accessibility\ColorVision.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioAccessibilityPassTest extends TestCase
{
    private const SIZE = 64;

    /** Encoded (screen) colours of the test pattern: status red left, status green right. */
    private const RED = [0.85, 0.2, 0.2];
    private const GREEN = [0.2, 0.65, 0.2];

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
        MeshRegistry::register('wall', BoxMesh::generate(40.0, 40.0, 0.1));
        MaterialRegistry::register('grey', new Material(albedo: new Color(0.3, 0.3, 0.3), roughness: 1.0));
        $this->dir = sys_get_temp_dir() . '/phpolygon-a11y-' . getmypid();
        @mkdir($this->dir);
        $this->renderer = new VioRenderer3D($ctx, self::SIZE, self::SIZE);
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

    private static function settings(AccessibilitySettings $a = new AccessibilitySettings()): GraphicsSettings
    {
        return new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Fxaa, bloom: false, accessibility: $a);
    }

    /** A Final-stage pass that paints the left half RED and the right half GREEN. */
    private function registerPattern(): void
    {
        $path = "{$this->dir}/pattern.frag.glsl";
        file_put_contents($path, sprintf(
            "#version 410 core\nin vec2 v_uv;\nuniform sampler2D u_source;\nout vec4 frag_color;\nvoid main() {\n"
            . "    vec3 c = texture(u_source, v_uv).rgb * 0.0;\n"
            . "    frag_color = vec4(c + (v_uv.x < 0.5 ? vec3(%F, %F, %F) : vec3(%F, %F, %F)), 1.0);\n}\n",
            ...self::RED,
            ...self::GREEN,
        ));
        FullscreenPassRegistry::register(new FullscreenPass('pattern', PostStage::Final, $path));
    }

    private function image(GraphicsSettings $settings, int $frames = 3): string
    {
        self::assertNotNull($this->ctx);
        self::assertNotNull($this->renderer);
        $this->renderer->applySettings($settings);
        $list = self::scene();
        for ($i = 0; $i < $frames; $i++) {
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

    private static function scene(): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::translation(0.0, 0.0, -3.0), Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 100.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.3));
        $list->add(new SetDirectionalLight(new Vec3(0.0, 0.0, -1.0), new Color(1.0, 1.0, 1.0), 0.5));
        $list->add(new DrawMesh('wall', 'grey', Mat4::identity()));
        return $list;
    }

    /** @return array{float, float, float} encoded colour 0..1 of a pixel */
    private static function pixel(string $rgba, int $x, int $y): array
    {
        $p = array_map(intval(...), array_values((array) unpack('C4', substr($rgba, ($y * self::SIZE + $x) * 4, 4))));
        return [$p[0] / 255, $p[1] / 255, $p[2] / 255];
    }

    /** @return array{0: array{float, float, float}, 1: array{float, float, float}} left and right sample, encoded */
    private static function halves(string $rgba): array
    {
        return [self::pixel($rgba, self::SIZE >> 2, self::SIZE >> 1), self::pixel($rgba, (self::SIZE * 3) >> 2, self::SIZE >> 1)];
    }

    /**
     * @param array{float, float, float} $expected
     * @param array{float, float, float} $actual
     */
    private static function assertColour(array $expected, array $actual, string $what): void
    {
        for ($i = 0; $i < 3; $i++) {
            self::assertEqualsWithDelta($expected[$i], $actual[$i], 3 / 255, "{$what}, channel {$i}");
        }
    }

    public function testDefaultsRunNoPass(): void
    {
        $this->registerPattern();
        $plain = $this->image(self::settings());
        [$left, $right] = self::halves($plain);
        self::assertColour(self::RED, $left, 'pattern reaches the screen untouched');
        self::assertColour(self::GREEN, $right, 'pattern reaches the screen untouched');

        // A simulation flag without a mode changes nothing, so it runs no pass either.
        $same = $this->image(self::settings(new AccessibilitySettings(colorVisionSimulate: true)));
        self::assertSame(md5($plain), md5($same));
    }

    public function testDeuteranopiaCorrectionSeparatesRedAndGreenForADeuteranope(): void
    {
        $this->registerPattern();
        $mode = ColorVisionMode::Deuteranopia;
        [$left, $right] = self::halves($this->image(self::settings(new AccessibilitySettings(colorVision: $mode))));

        $red = ColorVision::decode(self::RED);
        $green = ColorVision::decode(self::GREEN);
        self::assertColour(ColorVision::encode(ColorVision::correct($mode, $red)), $left, 'red as the CPU model corrects it');
        self::assertColour(ColorVision::encode(ColorVision::correct($mode, $green)), $right, 'green as the CPU model corrects it');

        $before = ColorVision::deltaE(ColorVision::simulate($mode, $red), ColorVision::simulate($mode, $green));
        $after = ColorVision::deltaE(ColorVision::simulate($mode, ColorVision::decode($left)), ColorVision::simulate($mode, ColorVision::decode($right)));
        fwrite(STDERR, sprintf("\n[a11y] deuteranope sees red/green apart by dE %.1f uncorrected, %.1f corrected\n", $before, $after));
        self::assertLessThan(10.0, $before, 'uncorrected, a deuteranope hardly tells them apart');
        self::assertGreaterThan(4.0 * $before, $after, 'corrected, they are clearly apart');
    }

    public function testSimulationShowsWhatTheViewerSees(): void
    {
        $this->registerPattern();
        foreach ([ColorVisionMode::Protanopia, ColorVisionMode::Deuteranopia, ColorVisionMode::Tritanopia] as $mode) {
            [$left, $right] = self::halves($this->image(self::settings(new AccessibilitySettings(colorVision: $mode, colorVisionSimulate: true))));
            self::assertColour(ColorVision::encode(ColorVision::simulate($mode, ColorVision::decode(self::RED))), $left, "{$mode->value} red");
            self::assertColour(ColorVision::encode(ColorVision::simulate($mode, ColorVision::decode(self::GREEN))), $right, "{$mode->value} green");
        }
    }

    public function testWorldSaturationKeepsLuminance(): void
    {
        $this->registerPattern();
        $luma = static fn (array $lin): float => 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
        $red = ColorVision::decode(self::RED);

        [$grey] = self::halves($this->image(self::settings(new AccessibilitySettings(worldSaturation: 0.0))));
        self::assertEqualsWithDelta($grey[0], $grey[1], 2 / 255, 'grey');
        self::assertEqualsWithDelta($grey[1], $grey[2], 2 / 255, 'grey');
        self::assertEqualsWithDelta($luma($red), $luma(ColorVision::decode($grey)), 0.01, 'same luminance');

        [$half] = self::halves($this->image(self::settings(new AccessibilitySettings(worldSaturation: 0.5))));
        $y = $luma($red);
        $expected = ColorVision::encode([$y + ($red[0] - $y) * 0.5, $y + ($red[1] - $y) * 0.5, $y + ($red[2] - $y) * 0.5]);
        self::assertColour($expected, $half, 'half saturation');
        fwrite(STDERR, sprintf("\n[a11y] saturation: red %.3f/%.3f/%.3f -> %.3f/%.3f/%.3f at 0.5, grey %.3f at 0\n", ...[...self::RED, ...$half, $grey[0]]));
    }

    public function testStrengthBlendsTheCorrection(): void
    {
        $this->registerPattern();
        $mode = ColorVisionMode::Protanopia;
        [$left] = self::halves($this->image(self::settings(new AccessibilitySettings(colorVision: $mode, colorVisionStrength: 0.5))));
        self::assertColour(ColorVision::encode(ColorVision::correct($mode, ColorVision::decode(self::RED), 0.5)), $left, 'half strength');
    }
}
