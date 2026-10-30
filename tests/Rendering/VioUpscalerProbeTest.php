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

        $this->frame($renderer);

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

    public function testASelectedDlssRendersItsFirstFrameWithDlss(): void
    {
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

        $this->frame($renderer);
        $this->assertSame(Upscaler::Dlss, $renderer->temporalUpscalerThisFrame());
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
