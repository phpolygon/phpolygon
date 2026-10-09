<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\UpscaleQuality;
use PHPolygon\Rendering\Quality\Upscaler;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The vio renderer offers TAA and TAAU (its own temporal resolve) wherever the
 * MRT scene path can carry motion vectors and a colour target's depth can be
 * sampled. FSR 3 and DLSS need php-vio's native module on a hardware GPU; the
 * default headless context (WARP on Windows) has none, so the renderer says
 * why and a graphics.json that picked one of them falls back along the chain -
 * to TAAU where it runs, including the temporal render-scale floor
 * (VioNativeUpscalerTest covers the hardware side).
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRendererUpscalerFallbackTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_TEMPORAL');
    }

    public function testNativeUpscalersFallBackToTheTemporalResolve(): void
    {
        $ctx = $this->context();
        try {
            $temporal = defined('VIO_FEATURE_RENDER_TARGET_DEPTH_SAMPLE')
                && vio_supports_feature($ctx, (int) constant('VIO_FEATURE_RENDER_TARGET_DEPTH_SAMPLE'))
                && defined('VIO_FEATURE_MRT') && vio_supports_feature($ctx, VIO_FEATURE_MRT);
            if (!$temporal) {
                $this->markTestSkipped('this php-vio build cannot sample the depth of a colour target');
            }
            $caps = (new VioRenderer3D($ctx, 64, 64))->graphicsCapabilities();

            $this->assertTrue($caps->temporalAntiAliasing, 'TAA runs on this renderer');
            $this->assertTrue($caps->supportsAntiAliasing(AntiAliasing::Taa));
            $this->assertTrue($caps->supportsUpscaler(Upscaler::Taau));
            $this->assertNull($caps->upscalerNote(Upscaler::Taau));
            foreach ([Upscaler::Fsr3, Upscaler::Dlss] as $native) {
                $this->assertFalse($caps->supportsUpscaler($native), $native->value);
                $this->assertNotNull($caps->upscalerNote($native), $native->value . ' explains itself');
                $this->assertSame(Upscaler::Taau, $caps->resolveUpscaler($native), $native->value);
            }

            $settings = (new GraphicsSettings())->with(
                upscaler: Upscaler::Dlss,
                upscaleQuality: UpscaleQuality::UltraPerformance,
            );
            $this->assertSame(Upscaler::Taau, $settings->effectiveUpscaler($caps));
            $this->assertEqualsWithDelta(1.0 / 3.0, $settings->effectiveRenderScale($caps), 0.01,
                'a temporal upscaler may reconstruct from a third of the resolution');
        } finally {
            vio_destroy($ctx);
        }
    }

    public function testWithoutTheTemporalPathTaauFallsBackToFsr1(): void
    {
        putenv('PHPOLYGON_VIO_TEMPORAL=0');
        $ctx = $this->context();
        try {
            $caps = (new VioRenderer3D($ctx, 64, 64))->graphicsCapabilities();
            $this->assertFalse($caps->temporalAntiAliasing);
            $this->assertFalse($caps->supportsUpscaler(Upscaler::Taau));
            $this->assertNotNull($caps->upscalerNote(Upscaler::Taau));

            $expected = $caps->supportsUpscaler(Upscaler::Fsr1) ? Upscaler::Fsr1 : Upscaler::Off;
            $settings = (new GraphicsSettings())->with(
                upscaler: Upscaler::Dlss,
                upscaleQuality: UpscaleQuality::UltraPerformance,
            );
            $this->assertSame($expected, $settings->effectiveUpscaler($caps));
            $this->assertSame(GraphicsSettings::RENDER_SCALE_MIN, $settings->effectiveRenderScale($caps));
        } finally {
            vio_destroy($ctx);
        }
    }

    public function testTaaIsNoLongerAPreview(): void
    {
        $this->assertSame('TAA', AntiAliasing::Taa->label());
    }

    private function context(): \VioContext
    {
        $ctx = @vio_create('auto', ['width' => 64, 'height' => 64, 'headless' => true, 'vsync' => false]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        return $ctx;
    }
}
