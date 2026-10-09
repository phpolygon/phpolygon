<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\UpscaleQuality;
use PHPolygon\Rendering\Quality\Upscaler;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Until the temporal resolve and the native upscaler modules exist, the vio
 * renderer must not offer Taau / Fsr3 / Dlss: it says why, and a graphics.json
 * that picked one of them falls back along the chain (to FSR 1 where its
 * shaders compiled), including the render-scale floor of that fallback.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRendererUpscalerFallbackTest extends TestCase
{
    public function testTemporalUpscalersAreNotOfferedYetAndFallBack(): void
    {
        $ctx = @vio_create('auto', ['width' => 64, 'height' => 64, 'headless' => true, 'vsync' => false]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }

        try {
            $caps = (new VioRenderer3D($ctx, 64, 64))->graphicsCapabilities();

            foreach ([Upscaler::Taau, Upscaler::Fsr3, Upscaler::Dlss] as $temporal) {
                $this->assertFalse($caps->supportsUpscaler($temporal), $temporal->value);
                $this->assertNotNull($caps->upscalerNote($temporal), $temporal->value . ' explains itself');
                $this->assertFalse($caps->resolveUpscaler($temporal)->isTemporal(), $temporal->value);
            }

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
}
