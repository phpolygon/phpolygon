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
use PHPolygon\Rendering\Quality\UpscalerDispatch;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * When the renderer talks to the DLSS provider, against the real one: its first
 * query per context blocks for seconds, so a query that ran shows in the clock.
 * Needs a hardware GPU with php-vio's DLSS plugin and NGX next to it.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioUpscalerProbeTest extends TestCase
{
    /** Far below the seconds of a first query, far above a file read. */
    private const NO_QUERY_MS = 500.0;

    private ?\VioContext $ctx = null;
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/phpolygon-upscaler-probe-' . uniqid() . '.json';
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('probe_box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('probe_white', new Material(albedo: new Color(0.9, 0.9, 0.9)));
    }

    protected function tearDown(): void
    {
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
        @unlink($this->cacheFile);
    }

    public function testNoQueryWithoutDlssSelected(): void
    {
        $renderer = $this->renderer(new GraphicsSettings(antiAliasing: AntiAliasing::Taa, upscaler: Upscaler::Fsr3));

        $ms = $this->clock(function () use ($renderer): void {
            $renderer->warmShaders();
            $this->frame($renderer);
            $this->frame($renderer);
        });

        $this->assertFalse($renderer->upscalerProbeDue());
        $this->assertFileDoesNotExist($this->cacheFile, 'nothing asked, nothing stored');
        // The query that stayed out is still ahead: it takes the seconds now.
        $ctx = $this->ctx;
        self::assertNotNull($ctx);
        $first = $this->clock(static fn() => vio_upscaler_supported($ctx, (int) UpscalerDispatch::providerId(Upscaler::Dlss)));
        $this->assertGreaterThan(self::NO_QUERY_MS, $first, "two frames took {$ms} ms");
    }

    /**
     * VIO_FEATURE_UPSCALER_NATIVE means "any provider": without FSR 3 asking for it
     * initialises DLSS on this thread. The capabilities must not get there.
     */
    #[RunInSeparateProcess]
    public function testWithoutFsrNoFrameInitialisesDlss(): void
    {
        ini_set('vio.ffx_path', sys_get_temp_dir());
        $renderer = $this->renderer(new GraphicsSettings(antiAliasing: AntiAliasing::Taa, upscaler: Upscaler::Fsr3));
        $renderer->warmShaders();
        $this->frame($renderer);
        $caps = $renderer->requestGraphicsCapabilities();
        $this->assertFalse($caps->supportsUpscaler(Upscaler::Fsr3), 'FSR 3 really is missing');

        $ctx = $this->ctx;
        self::assertNotNull($ctx);
        $first = $this->clock(static fn() => vio_upscaler_supported($ctx, (int) UpscalerDispatch::providerId(Upscaler::Dlss)));
        $this->assertGreaterThan(self::NO_QUERY_MS, $first, 'the first query is still ahead');
    }

    public function testASettingsScreenIsAnsweredAtOnceAndTheQueryWaitsForTheNextFrame(): void
    {
        $renderer = $this->renderer(new GraphicsSettings());

        $caps = null;
        $ms = $this->clock(function () use ($renderer, &$caps): void {
            $caps = $renderer->requestGraphicsCapabilities();
        });
        $this->assertLessThan(self::NO_QUERY_MS, $ms);
        self::assertNotNull($caps);
        $this->assertTrue($caps->isUpscalerPending(Upscaler::Dlss));
        $this->assertFalse($caps->supportsUpscaler(Upscaler::Dlss));
        $this->assertNotNull($caps->upscalerNote(Upscaler::Dlss));
        $this->assertTrue($renderer->upscalerProbeDue());

        $this->untilSettled($renderer);

        $after = $renderer->requestGraphicsCapabilities();
        $this->assertFalse($after->isUpscalerPending(Upscaler::Dlss));
        $this->assertTrue($after->supportsUpscaler(Upscaler::Dlss));
        $this->assertNull($after->upscalerNote(Upscaler::Dlss));
        $this->assertFileExists($this->cacheFile);
    }

    public function testTheNextContextReadsTheAnswerFromDisk(): void
    {
        $this->renderer(new GraphicsSettings())->graphicsCapabilities();
        $this->assertFileExists($this->cacheFile);
        self::assertNotNull($this->ctx);
        vio_destroy($this->ctx);
        $this->ctx = null;

        // The same process: NGX was shut down with the context, a query would take seconds.
        $renderer = $this->renderer(new GraphicsSettings());
        $caps = null;
        $ms = $this->clock(function () use ($renderer, &$caps): void {
            $caps = $renderer->requestGraphicsCapabilities();
        });

        $this->assertLessThan(self::NO_QUERY_MS, $ms);
        self::assertNotNull($caps);
        $this->assertFalse($caps->isUpscalerPending(Upscaler::Dlss));
        $this->assertTrue($caps->supportsUpscaler(Upscaler::Dlss));
    }

    public function testABrokenCacheFileIsQueriedAgainAndHealed(): void
    {
        file_put_contents($this->cacheFile, '{"format":1,"entries":');

        $caps = $this->renderer(new GraphicsSettings())->graphicsCapabilities();

        $this->assertTrue($caps->supportsUpscaler(Upscaler::Dlss));
        $this->assertIsArray(json_decode((string) file_get_contents($this->cacheFile), true));
    }

    public function testARealProviderPreparesBehindItsStandIn(): void
    {
        if (!function_exists('vio_upscaler_prepare')) {
            $this->markTestSkipped('php-vio without vio_upscaler_prepare');
        }
        $renderer = $this->renderer(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            bloom: false,
            hdr: false,
            antiAliasing: AntiAliasing::Off,
            upscaler: Upscaler::Dlss,
            upscaleQuality: UpscaleQuality::Quality,
        ));
        $renderer->warmShaders();
        // Compiles the temporal pipelines, seconds on a cold process.
        $this->frame($renderer);
        $worst = 0.0;
        $end = hrtime(true) + (int) 20e9;
        do {
            $worst = max($worst, $this->clock(fn() => $this->frame($renderer)));
            $this->assertNotSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame());
            usleep(10000);
        } while (!str_contains((string) $renderer->requestGraphicsCapabilities()->upscalerNote(Upscaler::Dlss), 'Ready') && hrtime(true) < $end);
        $this->assertLessThan(self::NO_QUERY_MS, $worst, 'no frame waited for NGX');

        $renderer->upscalerCutPoint();
        $this->frame($renderer);

        $this->assertSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame());
        $this->assertFileExists($this->cacheFile);
    }

    public function testASelectedDlssRendersItsFirstFrameWithDlss(): void
    {
        if (function_exists('vio_upscaler_prepare')) {
            $this->markTestSkipped('with vio_upscaler_prepare a stand-in renders first');
        }
        $settings = new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            bloom: false,
            hdr: false,
            antiAliasing: AntiAliasing::Off,
            upscaler: Upscaler::Dlss,
            upscaleQuality: UpscaleQuality::Quality,
        );
        foreach ([false, true] as $settledBefore) {
            $renderer = $this->renderer($settings);
            $this->assertTrue($renderer->upscalerProbeDue(), 'the constructor asked nothing yet');
            $renderer->warmShaders();
            if ($settledBefore) {
                // The engine's way: behind the splash, with no frame open.
                $renderer->settleUpscalerProbes();
                $this->assertFalse($renderer->upscalerProbeDue());
            }
            $this->frame($renderer);

            $this->assertSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame(), 'no stand-in frame');
            self::assertNotNull($this->ctx);
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testApplyingDlssOnARunningRendererQueriesAtTheNextFrame(): void
    {
        $renderer = $this->renderer(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            bloom: false,
            hdr: false,
            antiAliasing: AntiAliasing::Off,
        ));
        $renderer->warmShaders();
        $this->frame($renderer);

        $settings = $renderer->getSettings()->with(upscaler: Upscaler::Dlss);
        $ms = $this->clock(static fn() => $renderer->applySettings($settings));
        $this->assertLessThan(self::NO_QUERY_MS, $ms, 'applySettings may run mid-frame');
        $this->assertTrue($renderer->upscalerProbeDue());

        $this->untilSettled($renderer);
        $this->assertSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame());
    }

    /** NGX refuses an id that is no GUID: a real "no" from the provider, with the driver it checked. */
    #[RunInSeparateProcess]
    public function testARefusalOfTheProviderIsStoredAndTheNextContextReadsIt(): void
    {
        ini_set('vio.dlss_project_id', 'x');
        $caps = $this->renderer(new GraphicsSettings())->graphicsCapabilities();
        $this->assertFalse($caps->supportsUpscaler(Upscaler::Dlss));
        $note = $caps->upscalerNote(Upscaler::Dlss);
        $this->assertNotNull($note);
        $this->assertFileExists($this->cacheFile, 'a refusal took the seconds, it must not take them again');
        self::assertNotNull($this->ctx);
        vio_destroy($this->ctx);
        $this->ctx = null;

        $renderer = $this->renderer(new GraphicsSettings());
        $next = null;
        $ms = $this->clock(function () use ($renderer, &$next): void {
            $next = $renderer->requestGraphicsCapabilities();
        });

        $this->assertLessThan(self::NO_QUERY_MS, $ms);
        self::assertNotNull($next);
        $this->assertFalse($next->isUpscalerPending(Upscaler::Dlss));
        $this->assertSame($note, $next->upscalerNote(Upscaler::Dlss));
    }

    #[RunInSeparateProcess]
    public function testARefusalDoesNotOutliveTheProjectIdItWasGivenFor(): void
    {
        $default = (string) ini_get('vio.dlss_project_id');
        ini_set('vio.dlss_project_id', 'x');
        $this->renderer(new GraphicsSettings())->graphicsCapabilities();
        $this->assertFileExists($this->cacheFile);
        self::assertNotNull($this->ctx);
        vio_destroy($this->ctx);
        $this->ctx = null;

        ini_set('vio.dlss_project_id', $default);
        $caps = $this->renderer(new GraphicsSettings())->requestGraphicsCapabilities();

        $this->assertTrue($caps->isUpscalerPending(Upscaler::Dlss), 'another id can get another answer');
    }

    #[RunInSeparateProcess]
    public function testAMissingRuntimeLibraryIsNotStored(): void
    {
        ini_set('vio.dlss_path', sys_get_temp_dir());

        $caps = $this->renderer(new GraphicsSettings())->graphicsCapabilities();

        $this->assertFalse($caps->supportsUpscaler(Upscaler::Dlss));
        $this->assertStringContainsString('nvngx', (string) $caps->upscalerNote(Upscaler::Dlss));
        $this->assertFileDoesNotExist($this->cacheFile, 'the next build may ship it');
    }

    public function testCompleteCapabilitiesMatchTheProvider(): void
    {
        $renderer = $this->renderer(new GraphicsSettings());
        $ctx = $this->ctx;
        self::assertNotNull($ctx);

        $caps = $renderer->graphicsCapabilities();

        $this->assertSame([], $caps->pendingUpscalers);
        foreach ([Upscaler::Fsr3, Upscaler::Dlss] as $upscaler) {
            $this->assertSame(
                vio_upscaler_supported($ctx, (int) UpscalerDispatch::providerId($upscaler)),
                $caps->supportsUpscaler($upscaler),
                $upscaler->value,
            );
        }
    }

    /** One frame blocks for the query; with vio_upscaler_prepare the worker takes its seconds behind them. */
    private function untilSettled(VioRenderer3D $renderer): void
    {
        $end = hrtime(true) + (int) 20e9;
        do {
            $this->frame($renderer);
            usleep(10000);
        } while ($renderer->upscalerProbeDue() && hrtime(true) < $end);
        $this->frame($renderer);
    }

    private function frame(VioRenderer3D $renderer): void
    {
        self::assertNotNull($this->ctx);
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 1.0, 4.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0, 1, 0)),
            Mat4::perspective(deg2rad(55.0), 1.0, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(0.5, 0.5, 0.5), 1.0));
        $list->add(new DrawMesh('probe_box', 'probe_white', Mat4::translation(0.0, 0.0, 0.0)));
        // The engine calls beginFrame() first; the tests of the upscaler pass open
        // the php-vio frame first. A query has to survive both.
        $renderer->beginFrame();
        vio_begin($this->ctx);
        $renderer->render($list);
        $renderer->endFrame();
        vio_end($this->ctx);
    }

    private function renderer(GraphicsSettings $settings): VioRenderer3D
    {
        $ctx = @vio_create('d3d12', ['width' => 64, 'height' => 64, 'headless' => true, 'headless_hardware' => true, 'vsync' => false]);
        if ($ctx === false || vio_backend_name($ctx) !== 'd3d12') {
            $this->markTestSkipped('no hardware D3D12 context');
        }
        $this->ctx = $ctx;
        if (!defined('VIO_UPSCALER_DLSS') || !self::dlssInstalled()) {
            $this->markTestSkipped('no DLSS plugin here');
        }
        return new VioRenderer3D($ctx, 64, 64, $settings, $this->cacheFile);
    }

    /** Looked up in the files: asking php-vio would be the query this test is about. */
    private static function dlssInstalled(): bool
    {
        foreach ([(string) ini_get('vio.dlss_plugin_path'), dirname(PHP_BINARY), (string) ini_get('extension_dir')] as $dir) {
            if ($dir !== '' && is_file($dir . DIRECTORY_SEPARATOR . 'vio_dlss.dll')) {
                return true;
            }
        }
        return false;
    }

    private function clock(callable $work): float
    {
        $start = hrtime(true);
        $work();
        return (hrtime(true) - $start) / 1e6;
    }
}
