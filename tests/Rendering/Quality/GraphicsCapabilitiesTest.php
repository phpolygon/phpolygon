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

    public function testAPendingUpscalerIsNotOfferedAndTheChainWalksPastIt(): void
    {
        $caps = new GraphicsCapabilities(
            upscalers: [Upscaler::Off, Upscaler::Fsr1, Upscaler::Taau, Upscaler::Fsr3],
            upscalerNotes: [Upscaler::Dlss->value => 'Checking'],
            pendingUpscalers: [Upscaler::Dlss],
        );

        $this->assertTrue($caps->isUpscalerPending(Upscaler::Dlss));
        $this->assertFalse($caps->isUpscalerPending(Upscaler::Fsr3));
        $this->assertFalse($caps->supportsUpscaler(Upscaler::Dlss));
        $this->assertSame(Upscaler::Fsr3, $caps->resolveUpscaler(Upscaler::Dlss));
        $this->assertSame('Checking', $caps->upscalerNote(Upscaler::Dlss));
        $this->assertSame([], GraphicsCapabilities::unknown()->pendingUpscalers);
    }

    public function testNoOtherSelectionDependsOnAPendingDlss(): void
    {
        $known = [Upscaler::Off, Upscaler::Fsr1, Upscaler::Taau, Upscaler::Fsr3];
        $pending = new GraphicsCapabilities(upscalers: $known, pendingUpscalers: [Upscaler::Dlss]);
        $supported = new GraphicsCapabilities(upscalers: [...$known, Upscaler::Dlss]);
        $unsupported = new GraphicsCapabilities(upscalers: $known, upscalerNotes: [Upscaler::Dlss->value => 'no']);

        foreach (Upscaler::cases() as $selected) {
            if ($selected === Upscaler::Dlss) {
                continue;
            }
            $this->assertSame($supported->resolveUpscaler($selected), $pending->resolveUpscaler($selected), $selected->value);
            $this->assertSame($unsupported->resolveUpscaler($selected), $pending->resolveUpscaler($selected), $selected->value);
        }
    }

    public function testManagerWithoutRendererReportsUnknown(): void
    {
        $manager = new GraphicsSettingsManager(sys_get_temp_dir() . '/phpolygon-caps-' . getmypid() . '.json');

        $this->assertEquals(GraphicsCapabilities::unknown(), $manager->capabilities());
    }
}
