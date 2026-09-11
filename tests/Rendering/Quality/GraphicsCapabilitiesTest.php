<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPolygon\Rendering\GraphicsSettingsManager;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\FieldtracingMode;
use PHPolygon\Rendering\Quality\GraphicsCapabilities;
use PHPolygon\Rendering\Quality\Upscaler;
use PHPUnit\Framework\TestCase;

final class GraphicsCapabilitiesTest extends TestCase
{
    public function testUnknownRendererDisablesNothing(): void
    {
        $caps = GraphicsCapabilities::unknown();

        foreach (AntiAliasing::cases() as $aa) {
            $this->assertTrue($caps->supportsAntiAliasing($aa), $aa->value);
        }
        foreach (FieldtracingMode::cases() as $mode) {
            $this->assertTrue($caps->supportsFieldtracing($mode), $mode->value);
        }
        foreach (Upscaler::cases() as $upscaler) {
            $this->assertTrue($caps->supportsUpscaler($upscaler), $upscaler->value);
        }
        $this->assertTrue($caps->shadingRate && $caps->hdrOutput && $caps->lowLatency && $caps->surfaceRelief);
    }

    public function testMissingFeaturesDisableTheirOptionsOnly(): void
    {
        $caps = new GraphicsCapabilities(msaa: false, temporalAntiAliasing: false, fieldtracingSdf: false);

        $this->assertTrue($caps->supportsAntiAliasing(AntiAliasing::Off));
        $this->assertTrue($caps->supportsAntiAliasing(AntiAliasing::Fxaa));
        $this->assertFalse($caps->supportsAntiAliasing(AntiAliasing::Msaa2x));
        $this->assertFalse($caps->supportsAntiAliasing(AntiAliasing::Msaa4x));
        $this->assertFalse($caps->supportsAntiAliasing(AntiAliasing::Taa));

        $this->assertTrue($caps->supportsFieldtracing(FieldtracingMode::ProbesOnly));
        $this->assertFalse($caps->supportsFieldtracing(FieldtracingMode::SdfBounce));

        $this->assertTrue($caps->supportsUpscaler(Upscaler::Off));
        $this->assertFalse($caps->supportsUpscaler(Upscaler::Fsr1));
    }

    public function testManagerWithoutRendererReportsUnknown(): void
    {
        $manager = new GraphicsSettingsManager(sys_get_temp_dir() . '/phpolygon-caps-' . getmypid() . '.json');

        $this->assertEquals(GraphicsCapabilities::unknown(), $manager->capabilities());
    }
}
