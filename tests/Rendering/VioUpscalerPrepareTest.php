<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\Quality\UpscaleQuality;
use PHPolygon\Rendering\Quality\Upscaler;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * vio_upscaler_prepare behind the renderer: frames keep running while a stand-in
 * renders, the provider takes over at a cut. Driven by php-vio's test plugin
 * (tests/upscale_plugin/vio_test_upscaler.c), which registers as DLSS and sleeps
 * in prepare_run, so it needs no RTX GPU. PHPOLYGON_TEST_UPSCALER_PLUGIN names the
 * library; it skips without it and without vio_upscaler_prepare.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioUpscalerPrepareTest extends TestCase
{
    private const PREPARE_MS = 700;
    /** Outlasts the first frame of a cold process. */
    private const LONG_PREPARE_MS = 6000;
    /** A frame of this scene is far below it, the plugin's sleep far above. */
    private const STALL_MS = 300.0;

    private ?\VioContext $ctx = null;
    private string $cacheFile;

    protected function setUp(): void
    {
        $plugin = getenv('PHPOLYGON_TEST_UPSCALER_PLUGIN');
        if (!function_exists('vio_upscaler_prepare') || !is_string($plugin) || !is_file($plugin)) {
            $this->markTestSkipped('needs vio_upscaler_prepare and PHPOLYGON_TEST_UPSCALER_PLUGIN');
        }
        ini_set('vio.dlss_plugin_path', $plugin);
        putenv('VIO_TEST_UPSCALER_PREPARE_MS=' . self::PREPARE_MS);
        $this->cacheFile = sys_get_temp_dir() . '/phpolygon-prepare-' . uniqid() . '.json';
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('white', new Material(albedo: new Color(0.9, 0.9, 0.9)));
    }

    protected function tearDown(): void
    {
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
        if (isset($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    #[RunInSeparateProcess]
    public function testAStoredDlssStartsOnAStandInAndTakesOverAtTheCut(): void
    {
        putenv('VIO_TEST_UPSCALER_PREPARE_MS=' . self::LONG_PREPARE_MS);
        $renderer = $this->renderer(self::dlss());
        $renderer->settleUpscalerProbes();
        // Compiles the temporal pipelines, seconds on a cold process.
        $this->frame($renderer);
        $this->assertTrue($renderer->upscalerProbeDue());

        $worst = 0.0;
        $frames = 0;
        do {
            $worst = max($worst, $this->clock(fn() => $this->frame($renderer)));
            $frames++;
            $this->assertNotSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame(), 'waits for the cut');
            $this->assertNotNull($renderer->temporalUpscalerThisFrame(), 'a temporal stand-in');
            usleep(5000);
        } while (!$this->isReady($renderer));
        $this->assertLessThan(self::STALL_MS, $worst, "{$frames} frames kept running");
        $this->assertGreaterThan(20, $frames);

        $caps = $renderer->requestGraphicsCapabilities();
        $this->assertTrue($caps->isUpscalerPending(Upscaler::Dlss), 'ready, not active');
        $this->assertStringContainsString('next scene', (string) $caps->upscalerNote(Upscaler::Dlss));

        $renderer->upscalerCutPoint();
        $this->frame($renderer);

        $this->assertSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame());
        $frame = $renderer->temporalFrame();
        $this->assertNotNull($frame);
        $this->assertFalse($frame->historyValid, 'the first dispatch resets');
        $this->assertSame([32, 32], [$frame->renderWidth, $frame->renderHeight], 'the provider\'s render size');
        self::assertNotNull($this->ctx);
        $this->assertSame(1, vio_upscaler_info($this->ctx, VIO_UPSCALER_DLSS, false)['live'], 'the stand-in is gone');
    }

    #[RunInSeparateProcess]
    public function testACameraCutCounts(): void
    {
        $renderer = $this->renderer(self::dlss());
        $this->frame($renderer);
        $this->untilReady($renderer);
        $this->assertNotSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame());

        $this->frame($renderer, cut: true);
        $this->frame($renderer);

        $this->assertSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame());
    }

    #[RunInSeparateProcess]
    public function testACutBeforeTheProviderIsReadyIsNotSaved(): void
    {
        putenv('VIO_TEST_UPSCALER_PREPARE_MS=' . self::LONG_PREPARE_MS);
        $renderer = $this->renderer(self::dlss());
        $this->frame($renderer);
        $renderer->upscalerCutPoint();
        $this->frame($renderer);

        $this->untilReady($renderer);

        $this->assertNotSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame());
    }

    #[RunInSeparateProcess]
    public function testNothingOnScreenYetNeedsNoCut(): void
    {
        $renderer = $this->renderer(self::dlss());
        $renderer->settleUpscalerProbes();
        usleep((self::PREPARE_MS + 400) * 1000);

        $this->frame($renderer);

        $this->assertSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame());
    }

    #[RunInSeparateProcess]
    public function testAFailureStaysOnTheStandInAndSaysWhy(): void
    {
        putenv('VIO_TEST_UPSCALER_PREPARE_FAIL=1');
        $renderer = $this->renderer(self::dlss());
        $this->frame($renderer);

        $this->untilSettled($renderer);
        $renderer->upscalerCutPoint();
        $this->frame($renderer);

        $this->assertNotSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame());
        $caps = $renderer->requestGraphicsCapabilities();
        $this->assertFalse($caps->isUpscalerPending(Upscaler::Dlss));
        $this->assertFalse($caps->supportsUpscaler(Upscaler::Dlss));
        $this->assertStringContainsString('failed', (string) $caps->upscalerNote(Upscaler::Dlss));
    }

    #[RunInSeparateProcess]
    public function testARefusedPreparationIsAFailure(): void
    {
        putenv('VIO_TEST_UPSCALER_PREPARE_REFUSE=1');
        $renderer = $this->renderer(self::dlss());
        $this->frame($renderer);

        $this->assertFalse($renderer->upscalerProbeDue());
        $this->assertStringContainsString('refuses', (string) $renderer->requestGraphicsCapabilities()->upscalerNote(Upscaler::Dlss));
    }

    #[RunInSeparateProcess]
    public function testAnOldPluginTakesTheBlockingPath(): void
    {
        putenv('VIO_TEST_UPSCALER_OLD=1');
        $renderer = $this->renderer(self::dlss());

        $this->frame($renderer);

        $this->assertSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame(), 'first frame, as ever');
    }

    #[RunInSeparateProcess]
    public function testTheMenuNeverBlocksAndGetsTheListAfterwards(): void
    {
        $renderer = $this->renderer(self::temporal());
        $this->frame($renderer);

        $caps = $renderer->requestGraphicsCapabilities();
        $this->assertTrue($caps->isUpscalerPending(Upscaler::Dlss));
        $this->assertLessThan(self::STALL_MS, $this->clock(fn() => $this->frame($renderer)), 'prepare in the background');
        $this->assertTrue($renderer->requestGraphicsCapabilities()->isUpscalerPending(Upscaler::Dlss));

        $this->untilSettled($renderer);

        $this->assertTrue($renderer->requestGraphicsCapabilities()->supportsUpscaler(Upscaler::Dlss));
        $this->assertFileExists($this->cacheFile, 'the list comes from the disk next time');
    }

    #[RunInSeparateProcess]
    public function testChoosingDlssInTheMenuSwitchesWhenReadyWithoutACut(): void
    {
        $renderer = $this->renderer(self::temporal());
        $this->frame($renderer);

        $renderer->applySettings($renderer->getSettings()->with(upscaler: Upscaler::Dlss, upscaleQuality: UpscaleQuality::Performance));
        $this->assertLessThan(self::STALL_MS, $this->clock(fn() => $this->frame($renderer)));
        $this->assertNotSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame());
        $this->assertSame('Checking this device ...', $renderer->requestGraphicsCapabilities()->upscalerNote(Upscaler::Dlss));

        $this->untilSettled($renderer);

        $this->assertSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame());
    }

    #[RunInSeparateProcess]
    public function testAStoredYesStillPreparesAndTheStandInHolds(): void
    {
        $first = $this->renderer(self::temporal());
        $first->requestGraphicsCapabilities();
        $this->frame($first);
        $this->untilSettled($first);
        $this->assertFileExists($this->cacheFile);
        self::assertNotNull($this->ctx);
        vio_destroy($this->ctx);
        $this->ctx = null;

        $renderer = $this->renderer(self::temporal());
        $caps = $renderer->requestGraphicsCapabilities();
        $this->assertTrue($caps->supportsUpscaler(Upscaler::Dlss), 'offered from the disk');
        $this->frame($renderer);

        $renderer->applySettings($renderer->getSettings()->with(upscaler: Upscaler::Dlss, upscaleQuality: UpscaleQuality::Performance));
        $this->assertSame('Preparing ...', $renderer->requestGraphicsCapabilities()->upscalerNote(Upscaler::Dlss));
        $this->assertLessThan(self::STALL_MS, $this->clock(fn() => $this->frame($renderer)));
        $this->assertNotSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame());

        $this->untilSettled($renderer);
        $this->assertSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame());
    }

    #[RunInSeparateProcess]
    public function testCompleteCapabilitiesWaitForTheWorkerInsteadOfTakingItsNo(): void
    {
        $renderer = $this->renderer(self::temporal());
        $renderer->requestGraphicsCapabilities();
        $this->frame($renderer);

        $caps = $renderer->graphicsCapabilities();

        $this->assertSame([], $caps->pendingUpscalers);
        $this->assertTrue($caps->supportsUpscaler(Upscaler::Dlss));
    }

    #[RunInSeparateProcess]
    public function testWithoutFsrAStoredDlssStartsOnTaauAndStillPrepares(): void
    {
        ini_set('vio.ffx_path', sys_get_temp_dir());
        putenv('VIO_TEST_UPSCALER_PREPARE_MS=' . self::LONG_PREPARE_MS);
        $renderer = $this->renderer(self::dlss());
        $renderer->settleUpscalerProbes();
        self::assertNotNull($this->ctx);

        $this->assertSame('preparing', vio_upscaler_info($this->ctx, VIO_UPSCALER_DLSS, false)['state']);
        $this->frame($renderer);
        $this->assertSame(Upscaler::Taau, $renderer->temporalUpscalerThisFrame());

        $this->untilReady($renderer);
        $renderer->upscalerCutPoint();
        $this->frame($renderer);
        $this->assertSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame());
    }

    #[RunInSeparateProcess]
    public function testNoFrameLooksAtThePluginAfterADecisionOutsideDlss(): void
    {
        $renderer = $this->renderer(self::temporal());

        $this->frame($renderer);
        $this->frame($renderer);

        $this->assertFalse($renderer->upscalerProbeDue());
        self::assertNotNull($this->ctx);
        $this->assertSame('idle', vio_upscaler_info($this->ctx, VIO_UPSCALER_DLSS, false)['state'], 'never started');
    }

    private static function temporal(): GraphicsSettings
    {
        return new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            bloom: true,
            hdr: true,
            antiAliasing: AntiAliasing::Taa,
        );
    }

    private static function dlss(): GraphicsSettings
    {
        return self::temporal()->with(upscaler: Upscaler::Dlss, upscaleQuality: UpscaleQuality::Performance);
    }

    private function renderer(GraphicsSettings $settings): VioRenderer3D
    {
        $ctx = @vio_create('d3d12', ['width' => 64, 'height' => 64, 'headless' => true, 'headless_hardware' => true, 'vsync' => false]);
        if ($ctx === false || vio_backend_name($ctx) !== 'd3d12') {
            $this->markTestSkipped('no hardware D3D12 context');
        }
        $this->ctx = $ctx;
        $renderer = new VioRenderer3D($ctx, 64, 64, null, $this->cacheFile);
        $renderer->warmShaders();
        $renderer->applySettings($settings);
        return $renderer;
    }

    private function frame(VioRenderer3D $renderer, bool $cut = false): void
    {
        self::assertNotNull($this->ctx);
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 1.0, 4.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0, 1, 0)),
            Mat4::perspective(deg2rad(55.0), 1.0, 0.1, 100.0),
            $cut,
        ));
        $list->add(new SetAmbientLight(new Color(0.5, 0.5, 0.5), 1.0));
        $list->add(new DrawMesh('box', 'white', Mat4::translation(0.0, 0.0, 0.0)));
        $renderer->beginFrame();
        vio_begin($this->ctx);
        $renderer->render($list);
        $renderer->endFrame();
        vio_end($this->ctx);
    }

    /** Frames until the worker is done and the throttled look has seen it. */
    private function untilSettled(VioRenderer3D $renderer): void
    {
        $end = hrtime(true) + (int) 10e9;
        do {
            $this->frame($renderer);
            usleep(10000);
        } while ($renderer->upscalerProbeDue() && hrtime(true) < $end);
        $this->frame($renderer);
    }

    /** Frames past the worker's end, for a provider held back behind its cut. */
    private function untilReady(VioRenderer3D $renderer): void
    {
        $end = hrtime(true) + (int) 20e9;
        while (!$this->isReady($renderer) && hrtime(true) < $end) {
            $this->frame($renderer);
            usleep(10000);
        }
        $this->assertTrue($this->isReady($renderer));
    }

    private function isReady(VioRenderer3D $renderer): bool
    {
        return str_contains((string) $renderer->requestGraphicsCapabilities()->upscalerNote(Upscaler::Dlss), 'Ready');
    }

    private function clock(callable $work): float
    {
        $start = hrtime(true);
        $work();
        return (hrtime(true) - $start) / 1e6;
    }
}
