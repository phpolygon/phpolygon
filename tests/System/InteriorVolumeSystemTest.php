<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\InteriorVolume;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\System\InteriorVolumeSystem;

final class InteriorVolumeSystemTest extends TestCase
{
    public function testNearestVolumesFillTheSlotsAndTheRestStaysEmpty(): void
    {
        $world = new World();
        $far = $world->createEntity();
        $far->attach(new Transform3D(position: new Vec3(500.0, 0.0, 0.0)))->attach(new InteriorVolume(new Vec3(5.0, 3.0, 5.0)));
        $near = $world->createEntity();
        $near->attach(new Transform3D(position: new Vec3(10.0, 2.0, 0.0)))->attach(new InteriorVolume(new Vec3(4.0, 2.0, 6.0), 0.8));
        $off = $world->createEntity();
        $off->attach(new Transform3D(position: new Vec3(1.0, 0.0, 0.0)))->attach(new InteriorVolume(new Vec3(1.0, 1.0, 1.0), 0.0));

        $u = InteriorVolumeSystem::uniforms($world, new Vec3(0.0, 1.0, 0.0));

        self::assertSame([10.0, 2.0, 0.0], array_slice($u['u_interior_box_0'], 0, 3), 'nearest first');
        self::assertSame([4.0, 2.0, 6.0, 0.8], $u['u_interior_ext_0']);
        self::assertSame(500.0, $u['u_interior_box_1'][0]);
        self::assertSame(0.0, $u['u_interior_ext_2'][3], 'a strength-0 volume takes no slot');
        self::assertCount(2 * InteriorVolumeSystem::SLOTS, $u);
    }
}
