<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Spatial;

use PHPolygon\Audio\Spatial\AudioListener;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPUnit\Framework\TestCase;

final class AudioListenerTest extends TestCase
{
    public function testDefaultLooksDownNegativeZ(): void
    {
        $l = new AudioListener();
        $this->assertTrue($l->forward->equals(new Vec3(0.0, 0.0, -1.0)));
        $this->assertTrue($l->right->equals(new Vec3(1.0, 0.0, 0.0)));
        $this->assertTrue($l->up->equals(new Vec3(0.0, 1.0, 0.0)));
        $this->assertTrue($l->toLocal(new Vec3(3.0, 0.0, 0.0))->equals(new Vec3(3.0, 0.0, 0.0)));
        $this->assertTrue($l->toLocal(new Vec3(0.0, 0.0, -2.0))->equals(new Vec3(0.0, 0.0, -2.0)));
    }

    public function testTurnedListenerHearsTheSourceOnItsSide(): void
    {
        // Facing +X: a source at +Z is on the listener's right.
        $l = new AudioListener(new Vec3(10.0, 0.0, 0.0), new Vec3(1.0, 0.0, 0.0));
        $local = $l->toLocal(new Vec3(10.0, 0.0, 5.0));
        $this->assertEqualsWithDelta(5.0, $local->x, 1e-9);
        $this->assertEqualsWithDelta(0.0, $local->z, 1e-9);
        // Straight ahead is −Z in listener space.
        $ahead = $l->toLocal(new Vec3(14.0, 0.0, 0.0));
        $this->assertEqualsWithDelta(-4.0, $ahead->z, 1e-9);
        $this->assertEqualsWithDelta(5.0, $l->distanceTo(new Vec3(10.0, 0.0, 5.0)), 1e-9);
    }

    public function testFrameStaysOrthonormalWhenLookingStraightUp(): void
    {
        $l = new AudioListener(null, new Vec3(0.0, 1.0, 0.0));
        $this->assertEqualsWithDelta(0.0, $l->forward->dot($l->right), 1e-9);
        $this->assertEqualsWithDelta(0.0, $l->forward->dot($l->up), 1e-9);
        $this->assertEqualsWithDelta(1.0, $l->right->length(), 1e-9);
    }

    public function testFromRotationUsesTheCameraConvention(): void
    {
        $yaw = Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), -M_PI / 2.0); // turn right
        $l = AudioListener::fromRotation(new Vec3(), $yaw);
        $this->assertEqualsWithDelta(1.0, $l->forward->x, 1e-9);
    }

    public function testFromActiveCameraAddsTheEyeOffset(): void
    {
        $world = new World();
        $off = $world->createEntity();
        $off->attach(new Transform3D(new Vec3(99.0, 0.0, 0.0)));
        $off->attach(new Camera3DComponent(active: false));

        $cam = $world->createEntity();
        $cam->attach(new Transform3D(new Vec3(1.0, 2.0, 3.0)));
        $cam->attach(new Camera3DComponent(eyeOffset: new Vec3(0.0, 1.5, 0.0)));

        $l = AudioListener::fromActiveCamera($world);
        $this->assertNotNull($l);
        $this->assertTrue($l->position->equals(new Vec3(1.0, 3.5, 3.0)));
    }

    public function testFromActiveCameraIsNullWithoutCamera(): void
    {
        $this->assertNull(AudioListener::fromActiveCamera(new World()));
    }
}
