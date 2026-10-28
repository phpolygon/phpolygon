<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Renderer3DSystem;

class Renderer3DSystemDirectionalLightTest extends TestCase
{
    public function testDirectionalLightCarriesItsVolumetricScaleIntoTheCommand(): void
    {
        $world = new World();
        $world->createEntity()
            ->attach(new DirectionalLight(new Vec3(0.0, -1.0, 0.0), new Color(0.5, 0.6, 0.8), 0.65, volumetricScale: 0.1))
            ->attach(new Transform3D());

        $spy = new class extends NullRenderer3D {
            /** @var list<SetDirectionalLight> */
            public array $lights = [];

            public function render(RenderCommandList $commands): void
            {
                $this->lights = $commands->ofType(SetDirectionalLight::class);
            }
        };

        (new Renderer3DSystem($spy, new RenderCommandList()))->render($world);

        $this->assertCount(1, $spy->lights);
        $this->assertEqualsWithDelta(0.65, $spy->lights[0]->intensity, 1e-9);
        $this->assertEqualsWithDelta(0.1, $spy->lights[0]->volumetricScale, 1e-9);
    }

    public function testVolumetricScaleDefaultsToFull(): void
    {
        $this->assertSame(1.0, (new DirectionalLight())->volumetricScale);
        $this->assertSame(1.0, (new SetDirectionalLight(new Vec3(0.0, -1.0, 0.0), Color::white(), 1.0))->volumetricScale);
    }
}
