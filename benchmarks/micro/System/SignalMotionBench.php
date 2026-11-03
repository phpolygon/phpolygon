<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\System;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\SignalSpin;
use PHPolygon\Component\SignalSwitch;
use PHPolygon\Component\SignalTrack;
use PHPolygon\Component\Signals;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Motion\TrackEnd;
use PHPolygon\System\SignalMotionSystem;

/**
 * CPU cost of one SignalMotionSystem update: 10 belts carrying 50 items in all
 * (looping sequences, recycled at the end), 80 rollers following the belts and
 * 10 blinking switches. The belts are filled before measuring.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/System/SignalMotionBench.php --report=aggregate
 */
final class SignalMotionBench
{
    private const int BELTS = 10;
    private const int ITEMS_PER_BELT = 5;
    private const int ROLLERS_PER_BELT = 8;

    private World $world;
    private SignalMotionSystem $system;

    public function setUp(): void
    {
        $this->world = new World();
        for ($b = 0; $b < self::BELTS; $b++) {
            $z = $b * 3.0;
            $root = $this->world->createEntity();
            $root->attach(new Transform3D(new Vec3(0.0, 0.0, $z)));
            $signals = new Signals(['run' => true]);
            $root->attach($signals);
            $items = [];
            for ($i = 0; $i < self::ITEMS_PER_BELT; $i++) {
                $item = $this->world->createEntity();
                $item->attach(new Transform3D());
                $item->attach(new MeshRenderer('crate', 'crate'));
                $items[] = $item->id;
            }
            $track = new SignalTrack([new Vec3(0.0, 1.4, $z), new Vec3(18.0, 1.4, $z)], $items, speed: 1.2, spacing: 1.0,
                end: TrackEnd::Recycle, runChannel: 'run', rampUp: 1.5, rampDown: 1.0);
            $track->alignToPath = true;
            $root->attach($track);
            $signals->play('spawn', [0.0, 1.0, 2.0, 3.0, 4.0], 0.8, loop: true);
            for ($r = 0; $r < self::ROLLERS_PER_BELT; $r++) {
                $roller = $this->world->createEntity();
                $roller->attach(new Transform3D(new Vec3($r * 2.0, 1.3, $z)));
                $roller->attach(new SignalSpin(new Vec3(0.0, 0.0, 1.0), followTrack: $root->id, radius: 0.06));
            }
            $lamp = $this->world->createEntity();
            $lamp->attach(new MeshRenderer('lamp', 'lamp'));
            $lamp->attach(new SignalSwitch('run', blinkHz: 1.0, source: $root->id));
        }
        $this->system = new SignalMotionSystem();
        for ($i = 0; $i < 900; $i++) {
            $this->system->update($this->world, 1.0 / 60.0);
        }
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(1000)
     * @Iterations(5)
     */
    public function benchUpdate(): void
    {
        $this->system->update($this->world, 1.0 / 60.0);
    }
}
