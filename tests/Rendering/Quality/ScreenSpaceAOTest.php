<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPUnit\Framework\TestCase;

class ScreenSpaceAOTest extends TestCase
{
    public function testStrengthsAreMonotonicallyIncreasing(): void
    {
        $this->assertSame(0.0, ScreenSpaceAO::Off->strength());
        $this->assertGreaterThan(ScreenSpaceAO::Off->strength(),    ScreenSpaceAO::Low->strength());
        $this->assertGreaterThan(ScreenSpaceAO::Low->strength(),    ScreenSpaceAO::Medium->strength());
        $this->assertGreaterThan(ScreenSpaceAO::Medium->strength(), ScreenSpaceAO::High->strength());
    }

    public function testFullStrengthCapsAtOne(): void
    {
        // The shader multiplies (1 - occlusion * strength) into the ambient
        // term, so values > 1 would let occlusion overshoot into negative
        // ambient. Keep High at exactly 1.0.
        $this->assertSame(1.0, ScreenSpaceAO::High->strength());
    }

    public function testGraphicsSettingsDefaultIsMedium(): void
    {
        $s = new GraphicsSettings();
        $this->assertSame(ScreenSpaceAO::Medium, $s->ambientOcclusion);
    }

    public function testGraphicsSettingsRoundtripsAo(): void
    {
        $s = (new GraphicsSettings())->with(ambientOcclusion: ScreenSpaceAO::Off);
        $json = $s->toJson();
        $this->assertSame('off', $json['ambientOcclusion']);

        $restored = GraphicsSettings::fromJson($json);
        $this->assertSame(ScreenSpaceAO::Off, $restored->ambientOcclusion);
    }

    public function testFromJsonFallsBackToDefaultOnUnknownAo(): void
    {
        $restored = GraphicsSettings::fromJson(['ambientOcclusion' => 'extreme']);
        $this->assertSame(ScreenSpaceAO::Medium, $restored->ambientOcclusion);
    }
}
