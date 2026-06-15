<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\FieldtracingMode;
use PHPUnit\Framework\TestCase;

class GraphicsSettingsFieldtracingTest extends TestCase
{
    public function testDefaultIsOffSoExistingGamesAreUnchanged(): void
    {
        $this->assertSame(FieldtracingMode::Off, (new GraphicsSettings())->fieldtracing);
    }

    public function testWithOverridesFieldtracingAndPreservesOthers(): void
    {
        $base = new GraphicsSettings();
        $next = $base->with(fieldtracing: FieldtracingMode::SdfBounce);

        $this->assertSame(FieldtracingMode::SdfBounce, $next->fieldtracing);
        // Untouched fields carry over.
        $this->assertSame($base->shadowQuality, $next->shadowQuality);
        $this->assertSame($base->ambientOcclusion, $next->ambientOcclusion);
        // Original instance is not mutated.
        $this->assertSame(FieldtracingMode::Off, $base->fieldtracing);
    }

    public function testJsonRoundTrip(): void
    {
        $settings = (new GraphicsSettings())->with(fieldtracing: FieldtracingMode::SdfOcclusion);
        $json = $settings->toJson();
        $this->assertSame('sdf_occlusion', $json['fieldtracing']);

        $restored = GraphicsSettings::fromJson($json);
        $this->assertSame(FieldtracingMode::SdfOcclusion, $restored->fieldtracing);
    }

    public function testFromJsonFallsBackToDefaultOnMissingOrInvalid(): void
    {
        $this->assertSame(FieldtracingMode::Off, GraphicsSettings::fromJson([])->fieldtracing);
        $this->assertSame(
            FieldtracingMode::Off,
            GraphicsSettings::fromJson(['fieldtracing' => 'bogus'])->fieldtracing
        );
    }

    public function testDegradeLadder(): void
    {
        $this->assertSame(FieldtracingMode::SdfOcclusion, FieldtracingMode::SdfBounce->degraded());
        $this->assertSame(FieldtracingMode::ProbesOnly, FieldtracingMode::SdfOcclusion->degraded());
        $this->assertSame(FieldtracingMode::Off, FieldtracingMode::ProbesOnly->degraded());
        $this->assertSame(FieldtracingMode::Off, FieldtracingMode::Off->degraded());
    }
}
