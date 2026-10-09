<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\Quality\UpscaleQuality;
use PHPolygon\Rendering\Quality\Upscaler;
use PHPolygon\Rendering\Quality\UpscalerDispatch;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The native temporal upscalers (AMD FSR 3.1, NVIDIA DLSS through php-vio's
 * vio_upscaler_*) behind VioRenderer3D's temporal resolve: offered only where
 * the provider runs (and why not otherwise), rendering at the provider's size
 * with its jitter cycle, and - through the engine's conventions for jitter and
 * motion - at least as close to a 4x supersampled reference as the engine's
 * own TAAU while the camera turns, without a trail behind a moved object.
 * A provider that fails at runtime is reported once and the chain moves on;
 * the stored setting stays.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioNativeUpscalerTest extends TestCase
{
    private const W = 256;
    private const H = 192;

    private ?\VioContext $ctx = null;
    private string $backend = '';

    /** @return iterable<string, array{0: string}> */
    public static function backends(): iterable
    {
        foreach (['d3d12', 'vulkan'] as $backend) {
            yield $backend => [$backend];
        }
    }

    /** @return iterable<string, array{0: string, 1: Upscaler}> */
    public static function providers(): iterable
    {
        foreach (['d3d12', 'vulkan'] as $backend) {
            foreach ([Upscaler::Fsr3, Upscaler::Dlss] as $upscaler) {
                yield "$backend {$upscaler->value}" => [$backend, $upscaler];
            }
        }
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_UPSCALER_FAIL');
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    #[DataProvider('backends')]
    public function testCapabilitiesOfferWhatTheDeviceRuns(string $backend): void
    {
        $this->prepare($backend);
        $this->open();
        self::assertNotNull($this->ctx);
        $caps = (new VioRenderer3D($this->ctx, self::W, self::H))->graphicsCapabilities();
        foreach ([Upscaler::Fsr3, Upscaler::Dlss] as $upscaler) {
            $provider = UpscalerDispatch::providerId($upscaler);
            self::assertNotNull($provider);
            $supported = vio_upscaler_supported($this->ctx, $provider);
            $this->assertSame($supported, $caps->supportsUpscaler($upscaler), $upscaler->value);
            if ($supported) {
                $this->assertNull($caps->upscalerNote($upscaler));
            } else {
                $this->assertSame(vio_upscaler_info($this->ctx, $provider)['reason'], $caps->upscalerNote($upscaler));
            }
        }
    }

    public function testWithoutAHardwareGpuTheNoteSaysWhy(): void
    {
        $ctx = @vio_create('d3d12', ['width' => 64, 'height' => 64, 'headless' => true, 'vsync' => false]);
        if ($ctx === false || vio_backend_name($ctx) !== 'd3d12') {
            $this->markTestSkipped('no headless D3D12');
        }
        $this->ctx = $ctx;
        $caps = (new VioRenderer3D($ctx, 64, 64))->graphicsCapabilities();
        $this->assertFalse($caps->supportsUpscaler(Upscaler::Fsr3), 'WARP runs no native upscaler');
        $this->assertFalse($caps->supportsUpscaler(Upscaler::Dlss));
        $this->assertNotNull($caps->upscalerNote(Upscaler::Fsr3));
        $this->assertStringContainsString('hardware', (string) $caps->upscalerNote(Upscaler::Fsr3));
    }

    #[DataProvider('providers')]
    public function testRendersAtTheProvidersSizeWithItsJitterCycle(string $backend, Upscaler $upscaler): void
    {
        $this->prepare($backend);
        $this->open();
        $provider = $this->requireProvider($upscaler);
        self::assertNotNull($this->ctx);
        $renderer = $this->renderer($upscaler, UpscaleQuality::Balanced);
        $this->frame($renderer, self::scene(0.0, 1.0));
        $this->frame($renderer, self::scene(0.0, 1.0));

        $expected = vio_upscaler_render_size($this->ctx, $provider, 2, self::W, self::H);
        $this->assertIsArray($expected);
        $frame = $renderer->temporalFrame();
        $this->assertNotNull($frame);
        $this->assertSame([$expected['width'], $expected['height']], [$frame->renderWidth, $frame->renderHeight]);
        $this->assertSame([self::W, self::H], [$frame->displayWidth, $frame->displayHeight]);
        $this->assertSame($upscaler, $renderer->temporalUpscalerThisFrame());
        $phases = (int) (8.0 * (self::W / $expected['width']) ** 2);
        $this->assertSame(max(1, $phases), $frame->jitter->phaseCount, 'the provider names the cycle');
    }

    #[DataProvider('providers')]
    public function testStaticViewConvergesToTheSupersampledReference(string $backend, Upscaler $upscaler): void
    {
        $this->prepare($backend);
        $scene = self::scene(0.0, 1.0);
        $reference = $this->supersampled($scene);
        $this->open();
        $this->requireProvider($upscaler);
        $renderer = $this->renderer($upscaler, UpscaleQuality::Quality);
        for ($i = 0; $i < 32; $i++) {
            $this->frame($renderer, $scene);
        }
        $ssim = self::ssim($this->resolved($renderer), $reference);
        fprintf(STDERR, "[native %s %s] static SSIM (quality) %.4f\n", $backend, $upscaler->value, $ssim);
        $this->assertGreaterThanOrEqual(0.95, $ssim);
    }

    #[DataProvider('providers')]
    public function testRotatingCameraAtLeastMatchesTaau(string $backend, Upscaler $upscaler): void
    {
        $this->prepare($backend);
        $reference = $this->supersampled(self::scene(29 * 0.4, 1.0));
        $this->open();
        $this->requireProvider($upscaler);

        $taau = $this->renderer(Upscaler::Taau, UpscaleQuality::Performance);
        for ($i = 0; $i < 30; $i++) {
            $this->frame($taau, self::scene($i * 0.4, 1.0));
        }
        $ssimTaau = self::ssim($this->resolved($taau), $reference);

        $native = $this->renderer($upscaler, UpscaleQuality::Performance);
        for ($i = 0; $i < 30; $i++) {
            $this->frame($native, self::scene($i * 0.4, 1.0));
        }
        $this->assertSame($upscaler, $native->temporalUpscalerThisFrame());
        $ssimNative = self::ssim($this->resolved($native), $reference);
        fprintf(STDERR, "[native %s %s] rotating SSIM (performance) native %.4f, TAAU %.4f\n",
            $backend, $upscaler->value, $ssimNative, $ssimTaau);
        $this->assertGreaterThanOrEqual(0.95, $ssimNative);
        $this->assertGreaterThanOrEqual($ssimTaau, $ssimNative, 'the native upscaler keeps up at least as well as TAAU');
    }

    /**
     * Vertical camera motion: the motion vectors' Y convention (texture rows,
     * mv_scale) decides whether the history lands where it belongs.
     */
    #[DataProvider('providers')]
    public function testPitchingCameraAtLeastMatchesTaau(string $backend, Upscaler $upscaler): void
    {
        $this->prepare($backend);
        $pitch = static fn(int $i): float => -15.0 + $i * 1.2;
        $reference = $this->supersampled(self::scene(0.0, 1.0, pitch: $pitch(29)));
        $this->open();
        $this->requireProvider($upscaler);
        $ssim = [];
        foreach ([Upscaler::Taau, $upscaler] as $which) {
            $renderer = $this->renderer($which, UpscaleQuality::Performance);
            for ($i = 0; $i < 30; $i++) {
                $this->frame($renderer, self::scene(0.0, 1.0, pitch: $pitch($i)));
            }
            $ssim[$which->value] = self::ssim($this->resolved($renderer), $reference);
        }
        // The same provider on a camera that holds the final pose: what it
        // reaches without any motion to follow.
        $still = $this->renderer($upscaler, UpscaleQuality::Performance);
        for ($i = 0; $i < 30; $i++) {
            $this->frame($still, self::scene(0.0, 1.0, pitch: $pitch(29)));
        }
        $ssimStill = self::ssim($this->resolved($still), $reference);
        fprintf(STDERR, "[native %s %s] pitching SSIM (performance) native %.4f, TAAU %.4f, still %.4f\n",
            $backend, $upscaler->value, $ssim[$upscaler->value], $ssim['taau'], $ssimStill);
        $this->assertGreaterThanOrEqual($ssim['taau'], $ssim[$upscaler->value]);
        // Misread vertical motion costs ~0.02 here, followed motion < 0.01.
        $this->assertGreaterThan($ssimStill - 0.015, $ssim[$upscaler->value], 'the history follows the vertical motion');
    }

    /**
     * Still camera at half resolution: the reconstruction only beats the
     * engine's resolve when the jitter it is told matches the one the scene
     * was rendered with (both axes).
     */
    #[DataProvider('providers')]
    public function testStaticHalfResolutionAtLeastMatchesTaau(string $backend, Upscaler $upscaler): void
    {
        $this->prepare($backend);
        $scene = self::scene(0.0, 1.0);
        $reference = $this->supersampled($scene);
        $this->open();
        $this->requireProvider($upscaler);
        $ssim = [];
        foreach ([Upscaler::Taau, $upscaler] as $which) {
            $renderer = $this->renderer($which, UpscaleQuality::Performance);
            for ($i = 0; $i < 40; $i++) {
                $this->frame($renderer, $scene);
            }
            $ssim[$which->value] = self::ssim($this->resolved($renderer), $reference);
        }
        fprintf(STDERR, "[native %s %s] static SSIM (performance) native %.4f, TAAU %.4f\n",
            $backend, $upscaler->value, $ssim[$upscaler->value], $ssim['taau']);
        $this->assertGreaterThanOrEqual($ssim['taau'], $ssim[$upscaler->value]);
    }

    #[DataProvider('providers')]
    public function testMovedObjectLeavesNoTrailAfterEightFrames(string $backend, Upscaler $upscaler): void
    {
        $this->prepare($backend);
        [$before] = $this->supersampledRgb(self::scene(0.0, 1.0));
        [$after, $afterRgb] = $this->supersampledRgb(self::scene(0.0, -1.6));
        [$empty] = $this->supersampledRgb(self::scene(0.0, 99.0));
        $this->open();
        $this->requireProvider($upscaler);
        $renderer = $this->renderer($upscaler, UpscaleQuality::Quality);
        for ($i = 0; $i < 16; $i++) {
            $this->frame($renderer, self::scene(0.0, 1.0));
        }
        $this->frame($renderer, self::scene(0.0, -1.6, prevBoxX: 1.0));
        for ($i = 0; $i < 7; $i++) {
            $this->frame($renderer, self::scene(0.0, -1.6));
        }
        $resolvedRgb = $this->resolvedRgb($renderer);

        $sum = 0.0;
        $n = 0;
        foreach ($after as $i => $v) {
            if (abs($before[$i] - $v) > 30.0 && abs($empty[$i] - $v) < 4.0) {
                [$r, , $b] = $resolvedRgb[$i];
                [$er, , $eb] = $afterRgb[$i];
                $sum += max(0.0, ($r - $b) - ($er - $eb));
                $n++;
            }
        }
        $this->assertGreaterThan(100, $n, 'the box vacated a region');
        $ghost = $sum / $n / 255.0;
        fprintf(STDERR, "[native %s %s] ghosting after 8 frames %.3f%% over %d px\n", $backend, $upscaler->value, $ghost * 100.0, $n);
        $this->assertLessThan(0.02, $ghost);
    }

    #[DataProvider('backends')]
    public function testARuntimeFailureFallsDownTheChainOnce(string $backend): void
    {
        $this->prepare($backend);
        $this->open();
        $this->requireProvider(Upscaler::Dlss);
        $this->requireProvider(Upscaler::Fsr3);
        putenv('PHPOLYGON_VIO_UPSCALER_FAIL=dispatch');
        $renderer = $this->renderer(Upscaler::Dlss, UpscaleQuality::Quality);
        for ($i = 0; $i < 6; $i++) {
            $this->frame($renderer, self::scene(0.0, 1.0));
            $this->assertNotNull($renderer->temporalOutputTarget(), "frame $i still resolves");
        }
        $failures = $renderer->nativeUpscalerFailures();
        $this->assertSame([Upscaler::Dlss->value, Upscaler::Fsr3->value], array_keys($failures), 'each provider fails once, then the next one');
        $caps = $renderer->graphicsCapabilities();
        $this->assertFalse($caps->supportsUpscaler(Upscaler::Dlss));
        $this->assertFalse($caps->supportsUpscaler(Upscaler::Fsr3));
        $this->assertNotNull($caps->upscalerNote(Upscaler::Dlss));
        $this->assertSame(Upscaler::Taau, $renderer->temporalUpscalerThisFrame());
        $this->assertSame(Upscaler::Dlss, $renderer->getSettings()->upscaler, 'the stored setting stays');
    }

    #[DataProvider('backends')]
    public function testAFailedCreateFallsBackToo(string $backend): void
    {
        $this->prepare($backend);
        $this->open();
        $this->requireProvider(Upscaler::Fsr3);
        putenv('PHPOLYGON_VIO_UPSCALER_FAIL=create');
        $renderer = $this->renderer(Upscaler::Fsr3, UpscaleQuality::Quality);
        for ($i = 0; $i < 4; $i++) {
            $this->frame($renderer, self::scene(0.0, 1.0));
        }
        $this->assertArrayHasKey(Upscaler::Fsr3->value, $renderer->nativeUpscalerFailures());
        $this->assertSame(Upscaler::Taau, $renderer->temporalUpscalerThisFrame());
        $this->assertNotNull($renderer->temporalOutputTarget());
    }

    #[DataProvider('providers')]
    public function testSizeAndQualityChangesRecreateTheUpscaler(string $backend, Upscaler $upscaler): void
    {
        $this->prepare($backend);
        $this->open();
        $provider = $this->requireProvider($upscaler);
        self::assertNotNull($this->ctx);
        $renderer = $this->renderer($upscaler, UpscaleQuality::Quality);
        $this->frame($renderer, self::scene(0.0, 1.0));
        $live = vio_upscaler_info($this->ctx, $provider)['live'];
        foreach ([UpscaleQuality::Performance, UpscaleQuality::NativeAa, UpscaleQuality::Quality] as $quality) {
            $renderer->applySettings($renderer->getSettings()->with(upscaleQuality: $quality));
            $this->frame($renderer, self::scene(0.0, 1.0));
            $this->frame($renderer, self::scene(0.0, 1.0));
            $expected = vio_upscaler_render_size($this->ctx, $provider, UpscalerDispatch::qualityMode($quality, 1.0), self::W, self::H);
            $this->assertIsArray($expected);
            $frame = $renderer->temporalFrame();
            $this->assertNotNull($frame);
            $this->assertSame($expected['width'], $frame->renderWidth, $quality->value);
            $this->assertSame($live, vio_upscaler_info($this->ctx, $provider)['live'], 'the old upscaler is gone');
        }
        $renderer->applySettings($renderer->getSettings()->with(upscaler: Upscaler::Taau));
        $this->frame($renderer, self::scene(0.0, 1.0));
        $this->assertSame($live - 1, vio_upscaler_info($this->ctx, $provider)['live'], 'leaving the provider destroys it');
    }

    /** An off-screen image (thumbnails, captures) between frames leaves the upscaler alone. */
    #[DataProvider('backends')]
    public function testRenderToImageKeepsTheUpscaler(string $backend): void
    {
        $this->prepare($backend);
        $this->open();
        $provider = $this->requireProvider(Upscaler::Fsr3);
        self::assertNotNull($this->ctx);
        $renderer = $this->renderer(Upscaler::Fsr3, UpscaleQuality::Quality);
        $this->frame($renderer, self::scene(0.0, 1.0));
        $live = vio_upscaler_info($this->ctx, $provider)['live'];
        $renderer->renderToImage(self::scene(0.0, 1.0), 64, 48);
        $this->assertSame($live, vio_upscaler_info($this->ctx, $provider)['live'], 'not destroyed by renderToImage');
        $this->frame($renderer, self::scene(0.0, 1.0));
        $this->assertSame(Upscaler::Fsr3, $renderer->temporalUpscalerThisFrame());
    }

    /** Custom: the render-scale slider decides, the provider takes that size. */
    #[DataProvider('providers')]
    public function testCustomPresetRendersAtTheSliderScale(string $backend, Upscaler $upscaler): void
    {
        $this->prepare($backend);
        $this->open();
        $this->requireProvider($upscaler);
        $renderer = $this->renderer($upscaler, UpscaleQuality::Custom);
        $renderer->applySettings($renderer->getSettings()->with(renderScale: 0.6));
        for ($i = 0; $i < 3; $i++) {
            $this->frame($renderer, self::scene(0.0, 1.0));
        }
        $frame = $renderer->temporalFrame();
        $this->assertNotNull($frame);
        $this->assertSame([(int) round(self::W * 0.6), (int) round(self::H * 0.6)], [$frame->renderWidth, $frame->renderHeight]);
        $this->assertSame($upscaler, $renderer->temporalUpscalerThisFrame());
    }

    /** FP16 scene target (HDR on D3D): linear colour goes in, the result matches TAAU's. */
    #[DataProvider('providers')]
    public function testHdrSceneTargetResolvesLikeTaau(string $backend, Upscaler $upscaler): void
    {
        $this->prepare($backend);
        $this->open();
        $this->requireProvider($upscaler);
        $images = [];
        foreach ([Upscaler::Taau, $upscaler] as $which) {
            $renderer = $this->renderer($which, UpscaleQuality::Quality);
            $renderer->applySettings($renderer->getSettings()->with(hdr: true));
            for ($i = 0; $i < 24; $i++) {
                $this->frame($renderer, self::scene(0.0, 1.0));
            }
            $this->assertSame($which, $renderer->temporalUpscalerThisFrame());
            $images[$which->value] = $this->resolved($renderer);
        }
        $ssim = self::ssim($images[$upscaler->value], $images['taau']);
        $meanNative = array_sum($images[$upscaler->value]) / count($images['taau']);
        $meanTaau = array_sum($images['taau']) / count($images['taau']);
        fprintf(STDERR, "[native %s %s] HDR vs TAAU SSIM %.4f, mean luma %.1f / %.1f\n", $backend, $upscaler->value, $ssim, $meanNative, $meanTaau);
        $this->assertGreaterThanOrEqual(0.95, $ssim);
        $this->assertEqualsWithDelta($meanTaau, $meanNative, 3.0, 'no exposure shift');
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function prepare(string $backend): void
    {
        $ctx = @vio_create($backend, $this->options(self::W, self::H));
        $ok = $ctx !== false && vio_backend_name($ctx) === $backend;
        if ($ctx !== false) {
            vio_destroy($ctx);
        }
        if (!$ok) {
            $this->markTestSkipped("$backend: no headless context on this machine");
        }
        $this->backend = $backend;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('ground', PlaneMesh::generate(80.0, 80.0, 4));
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MeshRegistry::register('pole', BoxMesh::generate(0.12, 3.0, 0.12));
        MeshRegistry::register('wall', BoxMesh::generate(80.0, 40.0, 0.2));
        MaterialRegistry::register('ground', new Material(albedo: new Color(0.25, 0.3, 0.22), roughness: 0.9));
        MaterialRegistry::register('white', new Material(albedo: new Color(0.9, 0.9, 0.88)));
        MaterialRegistry::register('orange', new Material(albedo: new Color(0.95, 0.45, 0.1)));
        MaterialRegistry::register('wall', new Material(albedo: new Color(0.35, 0.45, 0.6), roughness: 0.9));
    }

    /** @return array<string, mixed> */
    private function options(int $w, int $h): array
    {
        // Native upscalers refuse WARP: headless contexts take the real GPU.
        return ['width' => $w, 'height' => $h, 'headless' => true, 'vsync' => false, 'headless_hardware' => true];
    }

    private function open(): void
    {
        $ctx = vio_create($this->backend, $this->options(self::W, self::H));
        $this->assertNotFalse($ctx);
        $this->ctx = $ctx;
    }

    private function requireProvider(Upscaler $upscaler): int
    {
        self::assertNotNull($this->ctx);
        $provider = UpscalerDispatch::providerId($upscaler);
        self::assertNotNull($provider);
        if (!function_exists('vio_upscaler_supported') || !vio_upscaler_supported($this->ctx, $provider)) {
            $reason = function_exists('vio_upscaler_info') ? vio_upscaler_info($this->ctx, $provider)['reason'] : 'php-vio without native upscalers';
            $this->markTestSkipped("{$upscaler->value} on {$this->backend}: {$reason}");
        }
        return $provider;
    }

    private function renderer(Upscaler $upscaler, UpscaleQuality $quality): VioRenderer3D
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->warmShaders();
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            bloom: false,
            hdr: false,
            antiAliasing: AntiAliasing::Off,
            upscaler: $upscaler,
            upscaleQuality: $quality,
            upscaleSharpness: 0.0,
        ));
        return $renderer;
    }

    private function frame(VioRenderer3D $renderer, RenderCommandList $list): void
    {
        self::assertNotNull($this->ctx);
        vio_begin($this->ctx);
        $renderer->beginFrame();
        $renderer->render($list);
        $renderer->endFrame();
        vio_end($this->ctx);
    }

    private static function scene(float $yaw, float $boxX, ?float $prevBoxX = null, bool $cut = false, float $pitch = 0.0): RenderCommandList
    {
        $list = new RenderCommandList();
        $eye = new Vec3(0.0, 1.8, 6.0);
        $r = deg2rad($yaw);
        $list->add(new SetCamera(
            Mat4::lookAt($eye, new Vec3(sin($r) * 6.0, 1.0 + 6.0 * tan(deg2rad($pitch)), $eye->z - cos($r) * 6.0), new Vec3(0, 1, 0)),
            Mat4::perspective(deg2rad(55.0), self::W / self::H, 0.1, 200.0),
            $cut,
        ));
        $list->add(new SetAmbientLight(new Color(0.55, 0.6, 0.7), 0.5));
        $list->add(new SetDirectionalLight(new Vec3(-0.4, -1.0, -0.5), new Color(1.0, 0.95, 0.85), 1.2));
        $list->add(new DrawMesh('wall', 'wall', Mat4::translation(0.0, 0.0, -12.0)));
        $list->add(new DrawMesh('ground', 'ground', Mat4::identity()));
        for ($i = -5; $i <= 5; $i++) {
            $list->add(new DrawMesh('pole', 'white', Mat4::translation($i * 0.55, 1.5, -1.0)));
        }
        for ($i = 0; $i < 4; $i++) {
            $list->add(new DrawMesh('box', 'white', Mat4::translation(-3.0 + $i * 2.0, 0.5, -4.0)->multiply(Mat4::rotationY(0.5 + $i * 0.35))));
        }
        $prev = $prevBoxX !== null ? Mat4::translation($prevBoxX, 0.7, 1.5) : null;
        $list->add(new DrawMesh('box', 'orange', Mat4::translation($boxX, 0.7, 1.5), prevModelMatrix: $prev));
        return $list;
    }

    /** @return list<float> */
    private function supersampled(RenderCommandList $scene): array
    {
        return $this->supersampledRgb($scene)[0];
    }

    /** @return array{0: list<float>, 1: list<array{0: float, 1: float, 2: float}>} */
    private function supersampledRgb(RenderCommandList $scene): array
    {
        $ctx = vio_create($this->backend, $this->options(self::W * 2, self::H * 2));
        $this->assertNotFalse($ctx);
        try {
            $renderer = new VioRenderer3D($ctx, self::W * 2, self::H * 2);
            $renderer->warmShaders();
            $renderer->applySettings(new GraphicsSettings(
                shadowQuality: ShadowQuality::Off,
                bloom: false,
                hdr: false,
                antiAliasing: AntiAliasing::Off,
            ));
            $renderer->renderToImage($scene, self::W * 2, self::H * 2);
            $big = $renderer->renderToImage($scene, self::W * 2, self::H * 2);
        } finally {
            vio_destroy($ctx);
        }
        $this->assertSame(self::W * self::H * 16, strlen($big));
        $luma = [];
        $rgb = [];
        $row = self::W * 2 * 4;
        for ($y = 0; $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                $o = 2 * $y * $row + 2 * $x * 4;
                $c = [0.0, 0.0, 0.0];
                foreach ([$o, $o + 4, $o + $row, $o + $row + 4] as $k) {
                    for ($ch = 0; $ch < 3; $ch++) {
                        $c[$ch] += ord($big[$k + $ch]) / 4.0;
                    }
                }
                $rgb[] = $c;
                $luma[] = 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
            }
        }
        return [$luma, $rgb];
    }

    /** @return list<float> */
    private function resolved(VioRenderer3D $renderer): array
    {
        $out = [];
        foreach ($this->resolvedRgb($renderer) as [$r, $g, $b]) {
            $out[] = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        }
        return $out;
    }

    /** @return list<array{0: float, 1: float, 2: float}> */
    private function resolvedRgb(VioRenderer3D $renderer): array
    {
        $target = $renderer->temporalOutputTarget();
        $this->assertNotNull($target, 'the temporal resolve ran');
        $raw = vio_read_render_target($target, -1, 0, ['raw' => true]);
        $this->assertIsString($raw);
        $this->assertSame(self::W * self::H * 8, strlen($raw));
        $h = array_values(unpack('v*', $raw) ?: []);
        $out = [];
        for ($i = 0, $n = count($h); $i < $n; $i += 4) {
            $out[] = [
                min(1.0, max(0.0, self::half($h[$i]))) * 255.0,
                min(1.0, max(0.0, self::half($h[$i + 1]))) * 255.0,
                min(1.0, max(0.0, self::half($h[$i + 2]))) * 255.0,
            ];
        }
        return $out;
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function ssim(array $a, array $b): float
    {
        $c1 = (0.01 * 255) ** 2;
        $c2 = (0.03 * 255) ** 2;
        $sum = 0.0;
        $count = 0;
        for ($y = 0; $y + 8 <= self::H; $y += 4) {
            for ($x = 0; $x + 8 <= self::W; $x += 4) {
                $ma = $mb = $va = $vb = $cov = 0.0;
                for ($j = 0; $j < 8; $j++) {
                    for ($i = 0; $i < 8; $i++) {
                        $k = ($y + $j) * self::W + $x + $i;
                        $ma += $a[$k];
                        $mb += $b[$k];
                    }
                }
                $ma /= 64.0;
                $mb /= 64.0;
                for ($j = 0; $j < 8; $j++) {
                    for ($i = 0; $i < 8; $i++) {
                        $k = ($y + $j) * self::W + $x + $i;
                        $da = $a[$k] - $ma;
                        $db = $b[$k] - $mb;
                        $va += $da * $da;
                        $vb += $db * $db;
                        $cov += $da * $db;
                    }
                }
                $va /= 63.0;
                $vb /= 63.0;
                $cov /= 63.0;
                $sum += ((2 * $ma * $mb + $c1) * (2 * $cov + $c2)) / (($ma * $ma + $mb * $mb + $c1) * ($va + $vb + $c2));
                $count++;
            }
        }
        return $sum / $count;
    }

    private static function half(int $h): float
    {
        $sign = ($h & 0x8000) !== 0 ? -1.0 : 1.0;
        $exp = ($h >> 10) & 0x1f;
        $mant = $h & 0x3ff;
        if ($exp === 0) {
            return $sign * $mant * 2.0 ** -24;
        }
        if ($exp === 31) {
            return $mant === 0 ? $sign * INF : NAN;
        }
        return $sign * (1.0 + $mant / 1024.0) * 2.0 ** ($exp - 15);
    }
}
