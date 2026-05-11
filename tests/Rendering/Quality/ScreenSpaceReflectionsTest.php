<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\ScreenSpaceReflections;
use PHPUnit\Framework\TestCase;

final class ScreenSpaceReflectionsTest extends TestCase
{
    public function testIntensityIsMonotonic(): void
    {
        $this->assertSame(0.0, ScreenSpaceReflections::Off->intensity());
        $this->assertGreaterThan(ScreenSpaceReflections::Off->intensity(), ScreenSpaceReflections::Low->intensity());
        $this->assertGreaterThan(ScreenSpaceReflections::Low->intensity(), ScreenSpaceReflections::High->intensity());
    }

    public function testHighCapsAtOne(): void
    {
        $this->assertSame(1.0, ScreenSpaceReflections::High->intensity());
    }

    public function testGraphicsSettingsDefaultsToOff(): void
    {
        $this->assertSame(ScreenSpaceReflections::Off, (new GraphicsSettings())->ssr);
    }

    public function testGraphicsSettingsRoundtripsSsr(): void
    {
        $s = (new GraphicsSettings())->with(ssr: ScreenSpaceReflections::High);
        $json = $s->toJson();
        $this->assertSame('high', $json['ssr']);

        $restored = GraphicsSettings::fromJson($json);
        $this->assertSame(ScreenSpaceReflections::High, $restored->ssr);
    }
}
