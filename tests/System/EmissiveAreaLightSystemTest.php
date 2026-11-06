<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\EmissiveAreaLight;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\RectAreaLight;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\System\EmissiveAreaLightSystem;

final class EmissiveAreaLightSystemTest extends TestCase
{
    protected function tearDown(): void
    {
        MaterialRegistry::clear();
    }

    public function testTheLightFollowsTheEmissionOfItsMaterial(): void
    {
        MaterialRegistry::register('neon', new Material(albedo: new Color(0.1, 0.1, 0.1), emission: new Color(2.0, 0.5, 1.0)));
        $world = new World();
        $entity = $world->createEntity();
        $entity->attach(new EmissiveAreaLight('neon', 8.0, 1.5));

        (new EmissiveAreaLightSystem())->update($world, 0.016);

        $light = $world->tryGetComponent($entity->id, PointLight::class);
        self::assertInstanceOf(PointLight::class, $light);
        self::assertEqualsWithDelta(3.0, $light->intensity, 1e-9);
        self::assertEqualsWithDelta(0.25, $light->color->g, 1e-9);
        self::assertSame(8.0, $light->radius);

        // The emission switched off (day, no power): the light goes dark.
        MaterialRegistry::register('neon', new Material(albedo: new Color(0.1, 0.1, 0.1), emission: new Color(0.0, 0.0, 0.0)));
        (new EmissiveAreaLightSystem())->update($world, 0.016);
        self::assertSame(0.0, $light->intensity);
    }

    public function testARectAreaLightIsDrivenInsteadOfAPointLight(): void
    {
        MaterialRegistry::register('panel', new Material(albedo: new Color(0.1, 0.1, 0.1), emission: new Color(1.0, 2.0, 0.5)));
        $world = new World();
        $entity = $world->createEntity();
        $entity->attach(new EmissiveAreaLight('panel', 8.0, 2.0));
        $entity->attach(new RectAreaLight(3.0, 0.2, Color::white(), 0.0, 10.0));

        (new EmissiveAreaLightSystem())->update($world, 0.016);

        $rect = $world->tryGetComponent($entity->id, RectAreaLight::class);
        self::assertInstanceOf(RectAreaLight::class, $rect);
        self::assertEqualsWithDelta(4.0, $rect->intensity, 1e-9);
        self::assertEqualsWithDelta(0.5, $rect->color->r, 1e-9);
        self::assertSame(3.0, $rect->width);
        self::assertNull($world->tryGetComponent($entity->id, PointLight::class));
    }

    public function testAnUnknownMaterialGivesNoLight(): void
    {
        [, $intensity] = EmissiveAreaLightSystem::lightFor(new EmissiveAreaLight('missing'));

        self::assertSame(0.0, $intensity);
    }
}
