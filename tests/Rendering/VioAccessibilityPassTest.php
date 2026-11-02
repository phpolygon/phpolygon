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
use PHPolygon\Rendering\Command\SetWind;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\Effects\EffectDefinition;
use PHPolygon\Rendering\Effects\EffectFallback;
use PHPolygon\Rendering\Effects\EffectRegistry;
use PHPolygon\Rendering\Effects\EffectTier;
use PHPolygon\Rendering\Effects\FlashClass;
use PHPolygon\Rendering\Effects\ReducedMotion;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\PostProcess\FullscreenPass;
use PHPolygon\Rendering\PostProcess\FullscreenPassRegistry;
use PHPolygon\Rendering\PostProcess\PostStage;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
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
        EffectRegistry::clear();
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
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

    /** @param (\Closure(int, RenderCommandList, VioRenderer3D): void)|null $frame adds commands or sets the clock per frame */
    private function image(GraphicsSettings $settings, int $frames = 3, ?\Closure $frame = null): string
    {
        self::assertNotNull($this->ctx);
        self::assertNotNull($this->renderer);
        $this->renderer->applySettings($settings);
        for ($i = 0; $i < $frames; $i++) {
            $list = self::scene();
            if ($frame !== null) {
                $frame($i, $list, $this->renderer);
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

    public function testContrastSpreadsTonesAndOutlinesEdges(): void
    {
        $this->registerPattern();
        $luma = static fn (array $e): float => 0.2126 * $e[0] + 0.7152 * $e[1] + 0.0722 * $e[2];
        $plain = $this->image(self::settings());
        $strong = $this->image(self::settings(new AccessibilitySettings(contrast: 1.0)));

        [$l0, $r0] = self::halves($plain);
        [$l1, $r1] = self::halves($strong);
        $red0 = $l0[0] - $l0[1];
        $red1 = $l1[0] - $l1[1];
        self::assertGreaterThan($red0 + 0.15, $red1, 'channels spread apart');

        // The seam between the halves gets a dark line on both sides.
        $mid = self::SIZE >> 1;
        $edge = min($luma(self::pixel($strong, $mid - 1, $mid)), $luma(self::pixel($strong, $mid, $mid)));
        $plainEdge = min($luma(self::pixel($plain, $mid - 1, $mid)), $luma(self::pixel($plain, $mid, $mid)));
        self::assertLessThan(0.6 * min($luma($l1), $luma($r1)), $edge, 'outline is darker than both sides');
        fwrite(STDERR, sprintf("\n[a11y] contrast 1: red-green channel spread %.3f -> %.3f, luminance at the seam %.3f -> %.3f (sides %.3f / %.3f)\n", $red0, $red1, $plainEdge, $edge, $luma($l1), $luma($r1)));
    }

    public function testStrengthBlendsTheCorrection(): void
    {
        $this->registerPattern();
        $mode = ColorVisionMode::Protanopia;
        [$left] = self::halves($this->image(self::settings(new AccessibilitySettings(colorVision: $mode, colorVisionStrength: 0.5))));
        self::assertColour(ColorVision::encode(ColorVision::correct($mode, ColorVision::decode(self::RED), 0.5)), $left, 'half strength');
    }

    public function testReducedMotionHoldsTheTimeOfRegisteredPassesAndVariants(): void
    {
        $entry = static fn (string $name, ReducedMotion $m): EffectDefinition => new EffectDefinition($name, EffectTier::Low, null, EffectFallback::Same, EffectFallback::Off, $m, FlashClass::None);

        // A Final pass that shows fract(u_time / 10) as grey.
        $path = "{$this->dir}/clock.frag.glsl";
        file_put_contents($path, "#version 410 core\nin vec2 v_uv;\nuniform sampler2D u_source;\nuniform float u_time;\nout vec4 frag_color;\nvoid main() {\n"
            . "    frag_color = vec4(texture(u_source, v_uv).rgb * 0.0 + vec3(fract(u_time * 0.1)), 1.0);\n}\n");
        FullscreenPassRegistry::register(new FullscreenPass('clock', PostStage::Final, $path));
        $at = static fn (float $t): \Closure => static function (int $i, RenderCommandList $l, VioRenderer3D $r) use ($t): void {
            $r->setAnimationTime($t);
        };
        $grey = fn (float $t): float => self::pixel($this->image(self::settings(), 3, $at($t)), 8, 8)[0];

        self::assertEqualsWithDelta(0.3, $grey(3.0), 2 / 255, 'engine time without an entry');
        EffectRegistry::register($entry('clock', ReducedMotion::Frozen));
        self::assertEqualsWithDelta(0.3, $grey(3.0), 2 / 255, 'engine time while reduced motion is off');
        EffectRegistry::setReducedMotion(true, 0.5);
        self::assertEqualsWithDelta(0.0, $grey(3.0), 2 / 255, 'Frozen: held');
        EffectRegistry::clear();
        EffectRegistry::register($entry('clock', ReducedMotion::Reduced));
        EffectRegistry::setReducedMotion(true, 0.5);
        self::assertEqualsWithDelta(0.15, $grey(3.0), 2 / 255, 'Reduced: half speed');

        // Mesh programs: a variant follows its entry, the base program keeps running. The
        // fragment stage of the mesh shader does not see u_time on every backend, so the
        // clock is read from vertex animation: cloth banners sway with u_time.
        FullscreenPassRegistry::clear();
        MeshRegistry::register('banner', BoxMesh::generate(0.5, 2.4, 0.05));
        MaterialRegistry::register('banner_base', new Material(albedo: new Color(0.8, 0.8, 0.8), cloth: true, clothStrength: 0.3));
        MaterialRegistry::register('banner_variant', new Material(albedo: new Color(0.8, 0.8, 0.8), cloth: true, clothStrength: 0.3));
        ProcModeRegistry::map('banner_base', 3);
        ProcModeRegistry::map('banner_variant', 5);
        ProcModeShaderRegistry::registerGlsl(3, '', "    if (u_proc_mode == 3) {\n        frag_color = outputColor(vec3(1.0), alpha);\n        return;\n    } else");
        ProcModeShaderRegistry::registerGlsl(5, '', "    if (u_proc_mode == 5) {\n        frag_color = outputColor(vec3(1.0), alpha);\n        return;\n    } else");
        ProcModeShaderRegistry::defineVariant('sway', [5]);
        EffectRegistry::clear();
        EffectRegistry::register($entry('sway', ReducedMotion::Frozen));
        self::assertNotNull($this->ctx);
        // The mesh programs take their snippets when the renderer is built.
        $this->renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $draw = static fn (float $t): \Closure => static function (int $i, RenderCommandList $l, VioRenderer3D $r) use ($t): void {
            $r->setAnimationTime($t);
            $l->add(new SetWind(new Vec3(1.0, 0.0, 0.0), 1.0));
            $l->add(new DrawMesh('banner', 'banner_base', Mat4::translation(-0.9, 0.0, 0.5)));
            $l->add(new DrawMesh('banner', 'banner_variant', Mat4::translation(0.9, 0.0, 0.5)));
        };
        // Centre column of the white pixels of a half, in a row near the free (bottom) end.
        $centre = static function (string $rgba, bool $left): float {
            [$sum, $n] = [0.0, 0];
            for ($x = $left ? 0 : self::SIZE >> 1; $x < ($left ? self::SIZE >> 1 : self::SIZE); $x++) {
                if (self::pixel($rgba, $x, 52)[0] > 0.9) {
                    [$sum, $n] = [$sum + $x, $n + 1];
                }
            }
            return $n > 0 ? $sum / $n : -1.0;
        };
        foreach ([false, true] as $reduced) {
            EffectRegistry::setReducedMotion($reduced, 0.5);
            $early = $this->image(self::settings(), 3, $draw(2.0));
            $late = $this->image(self::settings(), 3, $draw(5.0));
            $base = [$centre($early, true), $centre($late, true)];
            $variant = [$centre($early, false), $centre($late, false)];
            self::assertGreaterThan(0.0, min($base[0], $variant[0]), 'both banners are on screen');
            self::assertNotEqualsWithDelta($base[0], $base[1], 0.75, 'the base program keeps the engine time');
            if ($reduced) {
                self::assertEqualsWithDelta($variant[0], $variant[1], 0.01, 'the Frozen variant stands still');
            } else {
                self::assertNotEqualsWithDelta($variant[0], $variant[1], 0.75, 'the variant runs while reduced motion is off');
            }
            fwrite(STDERR, sprintf("\n[a11y] reduced motion %s: base banner at column %.1f -> %.1f, variant %.1f -> %.1f (t 2 -> 5)\n", $reduced ? 'on' : 'off', ...[...$base, ...$variant]));
        }
    }
}
