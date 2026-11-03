<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\SignalSpin;
use PHPolygon\Component\SignalSwitch;
use PHPolygon\Component\SignalTrack;
use PHPolygon\Component\Signals;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Motion\TrackEnd;
use PHPolygon\Rendering\Effects\EffectDefinition;
use PHPolygon\Rendering\Effects\EffectFallback;
use PHPolygon\Rendering\Effects\EffectRegistry;
use PHPolygon\Rendering\Effects\EffectTier;
use PHPolygon\Rendering\Effects\FlashClass;
use PHPolygon\Rendering\Effects\ReducedMotion;
use PHPolygon\System\SignalMotionSystem;

class SignalMotionSystemTest extends TestCase
{
    private const DT = 1.0 / 60.0;

    protected function tearDown(): void
    {
        EffectRegistry::clear();
    }

    /**
     * A straight 10 m track along +X with $poolSize items.
     *
     * @return array{0: World, 1: SignalMotionSystem, 2: Signals, 3: SignalTrack, 4: list<int>}
     */
    private function belt(int $poolSize = 3, TrackEnd $end = TrackEnd::Queue, float $speed = 1.0, float $spacing = 1.0): array
    {
        $world = new World();
        $items = [];
        for ($i = 0; $i < $poolSize; $i++) {
            $e = $world->createEntity();
            $e->attach(new Transform3D());
            $e->attach(new MeshRenderer('box', 'mat'));
            $items[] = $e->id;
        }
        $root = $world->createEntity();
        $root->attach(new Transform3D());
        $signals = new Signals();
        $root->attach($signals);
        $track = new SignalTrack([new Vec3(0, 1, 0), new Vec3(10, 1, 0)], $items, speed: $speed, spacing: $spacing, end: $end);
        $track->arrivedChannel = 'arrived';
        $track->fullChannel = 'full';
        $root->attach($track);
        $system = new SignalMotionSystem();
        $world->addSystem($system);
        return [$world, $system, $signals, $track, $items];
    }

    private static function simulate(World $world, float $seconds): void
    {
        $steps = (int) round($seconds / self::DT);
        for ($i = 0; $i < $steps; $i++) {
            $world->update(self::DT);
        }
    }

    private static function shown(MeshRenderer $mesh): bool
    {
        return $mesh->visible;
    }

    public function testPoolItemsStartHiddenAndAPulseSendsOneAtTheStart(): void
    {
        [$world, , $signals, $track, $items] = $this->belt();
        $world->update(self::DT);
        foreach ($items as $id) {
            $this->assertFalse($world->getComponent($id, MeshRenderer::class)->visible);
        }

        $signals->pulse('spawn', 7.0);
        $world->update(self::DT);

        $this->assertSame([$items[0]], $track->activeItems());
        $this->assertSame(7.0, $track->valueOf($items[0]));
        $this->assertTrue($world->getComponent($items[0], MeshRenderer::class)->visible);
        $this->assertEqualsWithDelta(1.0, $world->getComponent($items[0], Transform3D::class)->position->y, 1e-9);
    }

    public function testItemsMoveAtSpeedKeepSpacingAndQueueAtTheEnd(): void
    {
        [$world, , $signals, $track, $items] = $this->belt(3, TrackEnd::Queue, speed: 2.0, spacing: 1.5);
        $signals->pulse('spawn', 0.0);
        $signals->pulse('spawn', 1.0);
        $signals->pulse('spawn', 2.0);

        self::simulate($world, 1.0);
        // The first left at once and is ~2 m along; the second waited for 1.5 m of room.
        $this->assertEqualsWithDelta(2.0, $track->distanceOf($items[0]), 0.05);
        $this->assertEqualsWithDelta(0.5, $track->distanceOf($items[1]), 0.05);

        self::simulate($world, 10.0);
        $this->assertEqualsWithDelta(10.0, $track->distanceOf($items[0]), 1e-9);
        $this->assertEqualsWithDelta(8.5, $track->distanceOf($items[1]), 1e-9);
        $this->assertEqualsWithDelta(7.0, $track->distanceOf($items[2]), 1e-9);
        $this->assertEqualsWithDelta(10.0, $world->getComponent($items[0], Transform3D::class)->position->x, 1e-9);
        // Only the item that reached the end counts as arrived.
        $this->assertSame(1.0, $signals->number('arrived'));
        $this->assertSame([0.0, 1.0, 2.0], array_map(fn (int $i): ?float => $track->valueOf($i), $track->activeItems()));
    }

    public function testRecycledItemsReturnToThePool(): void
    {
        [$world, , $signals, $track, $items] = $this->belt(2, TrackEnd::Recycle, speed: 5.0);
        $signals->play('spawn', [0.0, 1.0, 2.0, 3.0], 1.0);
        self::simulate($world, 8.0);

        $this->assertSame(4.0, $signals->number('arrived'));
        $this->assertSame([], $track->activeItems());
        foreach ($items as $id) {
            $this->assertFalse($world->getComponent($id, MeshRenderer::class)->visible);
        }
    }

    public function testFullPoolRaisesTheFullChannelAndDropsBeyondTheQueue(): void
    {
        [$world, , $signals, $track] = $this->belt(2, TrackEnd::Queue, speed: 10.0, spacing: 0.5);
        $signals->maxPendingPulses = 3;
        for ($i = 0; $i < 6; $i++) {
            $signals->pulse('spawn', (float) $i);
        }
        $this->assertSame(3, $signals->droppedPulses('spawn'));

        self::simulate($world, 3.0);
        $this->assertCount(2, $track->activeItems());
        $this->assertSame(1, $signals->pendingPulses('spawn'));
        $this->assertTrue($signals->get('full'));

        $signals->clearPulses('spawn');
        $world->update(self::DT);
        $this->assertFalse($signals->get('full'));
    }

    public function testHaltEasesOutAndRunEasesIn(): void
    {
        [$world, , $signals, $track, $items] = $this->belt(1, TrackEnd::Queue, speed: 1.0);
        $track->haltChannel = 'blocked';
        $track->rampUp = 1.0;
        $track->rampDown = 0.5;
        $signals->pulse('spawn');

        self::simulate($world, 1.0);
        // Smoothstep from 0 to 1 over 1 s covers half of the full-speed distance.
        $this->assertEqualsWithDelta(0.5, $track->distanceOf($items[0]), 0.02);
        self::simulate($world, 1.0);
        $this->assertEqualsWithDelta(1.5, $track->distanceOf($items[0]), 0.02);

        $signals->set('blocked', true);
        self::simulate($world, 0.5);
        $this->assertEqualsWithDelta(1.75, $track->distanceOf($items[0]), 0.02);
        $stopped = $track->distanceOf($items[0]);
        self::simulate($world, 1.0);
        $this->assertSame($stopped, $track->distanceOf($items[0]));
        $this->assertSame(0.0, $track->drive);
    }

    public function testSequenceEmitsItsValuesAtTheInterval(): void
    {
        $s = new Signals();
        $s->play('n', [4.0, 5.0], 0.5, loop: true, delay: 0.25);
        $s->advance(0.2);
        $this->assertSame(0, $s->pendingPulses('n'));
        $s->advance(0.1);
        $this->assertSame(4.0, $s->takePulse('n'));
        $s->advance(1.0);
        $this->assertSame([5.0, 4.0], [$s->takePulse('n'), $s->takePulse('n')]);
        $this->assertTrue($s->isPlaying('n'));

        $s->play('once', [1.0], 1.0);
        $s->advance(0.0);
        $this->assertSame(1.0, $s->takePulse('once'));
        $this->assertFalse($s->isPlaying('once'));
    }

    public function testRollerFollowsTheTrackTravel(): void
    {
        [$world, , $signals, $track] = $this->belt(1, TrackEnd::Queue, speed: 0.5);
        $track->rampUp = 0.4;
        $roller = $world->createEntity();
        $rest = Quaternion::fromAxisAngle(new Vec3(0, 1, 0), 0.3);
        $roller->attach(new Transform3D(rotation: $rest));
        $spin = new SignalSpin(new Vec3(0, 0, 1), followTrack: $world->componentEntities(SignalTrack::class)[0], radius: 0.05);
        $roller->attach($spin);
        $signals->pulse('spawn');

        self::simulate($world, 1.3);
        $expected = fmod($track->travel / 0.05, 2.0 * M_PI);
        $this->assertEqualsWithDelta($expected, $spin->angle, 1e-9);
        $q = $rest->multiply(Quaternion::fromAxisAngle(new Vec3(0, 0, 1), $expected));
        $this->assertTrue($q->equals($roller->get(Transform3D::class)->rotation, 1e-9));
    }

    public function testSpinWithOwnDriveAndSpeedChannel(): void
    {
        $world = new World();
        $e = $world->createEntity();
        $e->attach(new Transform3D());
        $signals = new Signals(['on' => true], ['rate' => 0.5]);
        $e->attach($signals);
        $spin = new SignalSpin(new Vec3(0, 1, 0), speed: 2.0, runChannel: 'on');
        $spin->speedChannel = 'rate';
        $e->attach($spin);
        $world->addSystem(new SignalMotionSystem());

        self::simulate($world, 1.0);
        $this->assertEqualsWithDelta(1.0, $spin->angle, 1e-9);
        $signals->set('on', false);
        self::simulate($world, 1.0);
        $this->assertEqualsWithDelta(1.0, $spin->angle, 1e-9);
    }

    public function testSwitchShowsBlinksAndHoldsSteadyUnderFrozenReducedMotion(): void
    {
        $world = new World();
        $lamp = $world->createEntity();
        $lamp->attach(new Transform3D());
        $mesh = new MeshRenderer('lamp', 'red');
        $lamp->attach($mesh);
        $signals = new Signals();
        $lamp->attach($signals);
        $switch = new SignalSwitch('fault', blinkHz: 2.0);
        $switch->effect = 'beacon';
        $lamp->attach($switch);
        $world->addSystem(new SignalMotionSystem());

        $world->update(self::DT);
        $this->assertFalse($mesh->visible);

        $signals->set('fault', true);
        $seen = [];
        for ($i = 0; $i < 60; $i++) {
            $world->update(self::DT);
            $seen[] = self::shown($mesh);
        }
        // 2 Hz for one second: two on and two off runs.
        $runs = 1;
        for ($i = 1; $i < 60; $i++) {
            $runs += $seen[$i] !== $seen[$i - 1] ? 1 : 0;
        }
        $this->assertSame(4, $runs);

        EffectRegistry::register(new EffectDefinition('beacon', EffectTier::Low, null, EffectFallback::Same, EffectFallback::Same, ReducedMotion::Frozen, FlashClass::Gentle));
        EffectRegistry::setReducedMotion(true, 0.25);
        for ($i = 0; $i < 60; $i++) {
            $world->update(self::DT);
            $this->assertTrue($mesh->visible);
        }
    }

    public function testSwitchBlinkRateCapUnderReducedMotion(): void
    {
        $world = new World();
        $lamp = $world->createEntity();
        $mesh = new MeshRenderer('lamp', 'red');
        $lamp->attach($mesh);
        $switch = new SignalSwitch('', blinkHz: 4.0);
        $switch->maxBlinkHzReduced = 1.0;
        $lamp->attach($switch);
        $world->addSystem(new SignalMotionSystem());
        EffectRegistry::setReducedMotion(true);

        $seen = [];
        for ($i = 0; $i < 120; $i++) {
            $world->update(self::DT);
            $seen[] = $mesh->visible;
        }
        $changes = 0;
        for ($i = 1; $i < 120; $i++) {
            $changes += $seen[$i] !== $seen[$i - 1] ? 1 : 0;
        }
        // 1 Hz for two seconds: four edges at most.
        $this->assertLessThanOrEqual(4, $changes);
        $this->assertGreaterThanOrEqual(3, $changes);
    }

    public function testReducedMotionSlowsTheTrackAndStretchesItsRamp(): void
    {
        EffectRegistry::register(new EffectDefinition('belt', EffectTier::Low, null, EffectFallback::Same, EffectFallback::Same, ReducedMotion::Reduced, FlashClass::None));
        [$world, , $signals, $track, $items] = $this->belt(1, TrackEnd::Queue, speed: 1.0);
        $track->effect = 'belt';
        $track->rampUp = 1.0;
        $track->reducedRampScale = 1.5;
        $signals->pulse('spawn');

        EffectRegistry::setReducedMotion(true, 0.5);
        // Scaled time runs at 0.5: 1 s of ramp scaled by 1.5 takes 3 s of real time.
        self::simulate($world, 3.0);
        $this->assertEqualsWithDelta(1.0, $track->drive, 1e-6);
        $this->assertEqualsWithDelta(0.75, $track->distanceOf($items[0]), 0.02);
        self::simulate($world, 2.0);
        $this->assertEqualsWithDelta(1.75, $track->distanceOf($items[0]), 0.02);

        EffectRegistry::setReducedMotion(false);
        self::simulate($world, 1.0);
        $this->assertEqualsWithDelta(2.75, $track->distanceOf($items[0]), 0.02);
    }

    public function testTrackFreezesOutOfViewAndGoesOnWhereItStopped(): void
    {
        [$world, $system, $signals, $track, $items] = $this->belt(1, TrackEnd::Queue, speed: 1.0);
        $track->cullRadius = 6.0;
        $track->maxDistance = 30.0;
        $signals->pulse('spawn');
        self::simulate($world, 1.0);
        $before = $track->distanceOf($items[0]);

        $system->view()->setEye(new Vec3(100, 0, 0));
        self::simulate($world, 2.0);
        $this->assertTrue($track->frozen);
        $this->assertSame($before, $track->distanceOf($items[0]));

        // Behind the camera: outside the frustum.
        $view = Mat4::lookAt(new Vec3(0, 1, 20), new Vec3(0, 1, 40), new Vec3(0, 1, 0));
        $system->view()->set($view, Mat4::perspective(deg2rad(60.0), 16 / 9, 0.1, 500.0));
        self::simulate($world, 1.0);
        $this->assertTrue($track->frozen);

        $system->view()->setEye(new Vec3(5, 1, 5));
        self::simulate($world, 1.0);
        $this->assertFalse($track->frozen);
        $this->assertEqualsWithDelta($before + 1.0, $track->distanceOf($items[0]), 0.02);
    }

    public function testSameStepsGiveTheSamePoses(): void
    {
        $poses = [];
        for ($run = 0; $run < 2; $run++) {
            [$world, , $signals, $track, $items] = $this->belt(4, TrackEnd::Recycle, speed: 1.3, spacing: 0.7);
            $track->rampUp = 0.6;
            $track->rampDown = 0.4;
            $track->haltChannel = 'halt';
            $track->alignToPath = true;
            $signals->play('spawn', [0.0, 1.0, 2.0, 3.0], 0.9, loop: true);
            $out = [];
            for ($i = 0; $i < 600; $i++) {
                $signals->set('halt', ($i % 170) > 120);
                $world->update(self::DT);
            }
            foreach ($items as $id) {
                $out[] = $world->getComponent($id, Transform3D::class)->position->toArray();
            }
            $out[] = $track->travel;
            $poses[] = $out;
        }
        $this->assertSame($poses[0], $poses[1]);
    }
}
