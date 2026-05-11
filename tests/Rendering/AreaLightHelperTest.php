<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\AreaLightHelper;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\RenderCommandList;
use PHPUnit\Framework\TestCase;

class AreaLightHelperTest extends TestCase
{
    public function testEmits1x1ForSamples1(): void
    {
        $cl = new RenderCommandList();
        AreaLightHelper::pushRectangle(
            $cl,
            center: new Vec3(0.0, 0.0, 0.0),
            orientation: Quaternion::identity(),
            width: 4.0, height: 2.0,
            color: Color::white(),
            intensity: 8.0,
            samples: 1,
        );
        $lights = array_filter(iterator_to_array($cl->getCommands()), fn ($c) => $c instanceof AddPointLight);
        $this->assertCount(1, $lights);
    }

    public function testEmitsGridForSamplesGreaterThan1(): void
    {
        $cl = new RenderCommandList();
        AreaLightHelper::pushRectangle(
            $cl,
            center: new Vec3(0.0, 0.0, 0.0),
            orientation: Quaternion::identity(),
            width: 4.0, height: 2.0,
            color: Color::white(),
            intensity: 8.0,
            samples: 3,
        );
        $lights = array_filter(iterator_to_array($cl->getCommands()), fn ($c) => $c instanceof AddPointLight);
        $this->assertCount(9, $lights);
    }

    public function testTotalIntensityIsConserved(): void
    {
        $cl = new RenderCommandList();
        AreaLightHelper::pushRectangle(
            $cl,
            center: new Vec3(0.0, 0.0, 0.0),
            orientation: Quaternion::identity(),
            width: 4.0, height: 2.0,
            color: Color::white(),
            intensity: 12.0,
            samples: 2,
        );
        $lights = array_filter(iterator_to_array($cl->getCommands()), fn ($c) => $c instanceof AddPointLight);
        $sum = 0.0;
        foreach ($lights as $l) {
            $sum += $l->intensity;
        }
        $this->assertEqualsWithDelta(12.0, $sum, 1e-6);
    }

    public function testSamplesAreCenteredAroundOrigin(): void
    {
        $cl = new RenderCommandList();
        AreaLightHelper::pushRectangle(
            $cl,
            center: new Vec3(10.0, 4.0, -2.0),
            orientation: Quaternion::identity(),
            width: 4.0, height: 2.0,
            color: Color::white(),
            intensity: 4.0,
            samples: 2,
        );
        // Average of all 4 sample positions should equal the centre.
        $lights = array_values(array_filter(iterator_to_array($cl->getCommands()), fn ($c) => $c instanceof AddPointLight));
        $cx = $cy = $cz = 0.0;
        foreach ($lights as $l) {
            $cx += $l->position->x;
            $cy += $l->position->y;
            $cz += $l->position->z;
        }
        $n = count($lights);
        $this->assertEqualsWithDelta(10.0, $cx / $n, 1e-6);
        $this->assertEqualsWithDelta(4.0,  $cy / $n, 1e-6);
        $this->assertEqualsWithDelta(-2.0, $cz / $n, 1e-6);
    }
}
