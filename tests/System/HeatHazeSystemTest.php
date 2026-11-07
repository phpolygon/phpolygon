<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\HeatSource;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\System\HeatHazeSystem;

final class HeatHazeSystemTest extends TestCase
{
    public function testASourceAheadLandsMidScreenAndFollowsItsLight(): void
    {
        $world = new World();
        $light = new PointLight(intensity: 2.0);
        $world->createEntity()
            ->attach(new Transform3D(position: new Vec3(0.0, 0.0, -10.0)))
            ->attach(new HeatSource(1.5, 0.8, 2.0))
            ->attach($light);
        $camera = new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(1.0, 1.0, 0.1, 100.0),
        );

        $sources = HeatHazeSystem::sources($world, $camera);
        self::assertCount(1, $sources);
        self::assertEqualsWithDelta(0.5, $sources[0][0], 1e-6);
        self::assertEqualsWithDelta(0.5, $sources[0][1], 1e-6);
        self::assertGreaterThan(0.1, $sources[0][2], 'a 1.5 m source at 10 m covers a visible part of the screen');
        self::assertEqualsWithDelta(0.8 * (1.0 - 10.0 / HeatHazeSystem::MAX_DISTANCE), $sources[0][3], 1e-6);

        // The fire is out: no shimmer.
        $light->intensity = 0.0;
        self::assertSame([], HeatHazeSystem::sources($world, $camera));
    }

    public function testASourceBehindTheCameraIsSkipped(): void
    {
        $world = new World();
        $world->createEntity()
            ->attach(new Transform3D(position: new Vec3(0.0, 0.0, 10.0)))
            ->attach(new HeatSource());
        $camera = new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(1.0, 1.0, 0.1, 100.0),
        );

        self::assertSame([], HeatHazeSystem::sources($world, $camera));
    }
}
