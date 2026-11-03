<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Animation\Pose;
use PHPolygon\Animation\PoseClip;
use PHPolygon\Animation\PoseLibrary;
use PHPolygon\Animation\PoseRig;
use PHPolygon\Component\PosePlayer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Effects\EffectDefinition;
use PHPolygon\Rendering\Effects\EffectFallback;
use PHPolygon\Rendering\Effects\EffectRegistry;
use PHPolygon\Rendering\Effects\EffectTier;
use PHPolygon\Rendering\Effects\FlashClass;
use PHPolygon\Rendering\Effects\ReducedMotion;
use PHPolygon\System\PosePlayerSystem;
use PHPolygon\System\Transform3DSystem;

final class PosePlayerSystemTest extends TestCase
{
    private const float DT = 1.0 / 60.0;

    private World $world;
    private PosePlayerSystem $system;
    private PosePlayer $player;
    private int $rootId;
    /** @var array<string, Transform3D> */
    private array $parts = [];

    protected function setUp(): void
    {
        PoseLibrary::clear();
        EffectRegistry::clear();
        PoseLibrary::registerRig('figure', (new PoseRig())
            ->joint('shoulder', null, new Vec3(0.2, 1.4, 0.0))
            ->joint('elbow', 'shoulder', new Vec3(0.0, -0.3, 0.0)));
        // Arm swings 0 → 60° → 0 over one second.
        PoseLibrary::registerClip('swing', PoseClip::keyframes([
            [0.0, Pose::degrees(['shoulder' => [0.0]])],
            [0.5, Pose::degrees(['shoulder' => [60.0]])],
            [1.0, Pose::degrees(['shoulder' => [0.0]])],
        ], smooth: false));
        PoseLibrary::registerClip('raise', PoseClip::keyframes([
            [0.0, Pose::degrees(['shoulder' => [-90.0]])],
        ]));
        PoseLibrary::registerClip('bend', PoseClip::keyframes([
            [0.0, Pose::degrees(['elbow' => [0.0]])],
            [0.4, Pose::degrees(['elbow' => [-80.0]])],
        ], loop: false, smooth: false));
        PoseLibrary::registerClip('idle_a', PoseClip::keyframes([[0.0, new Pose()], [0.6, new Pose()]]));
        PoseLibrary::registerClip('idle_b', PoseClip::keyframes([[0.0, Pose::degrees(['shoulder' => [10.0]])], [0.9, Pose::degrees(['shoulder' => [10.0]])]]));
        PoseLibrary::registerClip('idle_c', PoseClip::keyframes([[0.0, Pose::degrees(['shoulder' => [-10.0]])], [0.4, Pose::degrees(['shoulder' => [-10.0]])]], loop: false));

        $this->world = new World();
        $root = $this->world->createEntity();
        $rootTransform = new Transform3D(position: new Vec3(5.0, 0.0, 0.0));
        $root->attach($rootTransform);
        $this->rootId = $root->id;
        $this->player = new PosePlayer('figure', seed: 7);
        foreach (['shoulder', 'elbow'] as $joint) {
            $part = $this->world->createEntity();
            $t = new Transform3D(parentEntityId: $root->id);
            $part->attach($t);
            $rootTransform->addChild($t, $part->id, $root->id);
            $this->player->bind($joint, $part->id);
            $this->parts[$joint] = $t;
        }
        $root->attach($this->player);
        $this->system = new PosePlayerSystem();
        $this->world->addSystem($this->system);
    }

    protected function tearDown(): void
    {
        PoseLibrary::clear();
        EffectRegistry::clear();
    }

    private function simulate(float $seconds): void
    {
        for ($i = 0, $n = (int) round($seconds / self::DT); $i < $n; $i++) {
            $this->world->update(self::DT);
        }
    }

    private function shoulderDegrees(): float
    {
        $v = $this->parts['shoulder']->rotation->rotateVec3(new Vec3(0.0, 1.0, 0.0));
        return rad2deg(atan2($v->z, $v->y));
    }

    public function testWritesTheSolvedPoseToTheBoundParts(): void
    {
        $this->player->play('swing', blend: 0.0);
        $this->simulate(0.25);

        $this->assertEqualsWithDelta(30.0, $this->shoulderDegrees(), 1e-6);
        $this->assertTrue($this->parts['shoulder']->position->equals(new Vec3(0.2, 1.4, 0.0), 1e-12));
        $elbow = (new Vec3(0.2, 1.4, 0.0))->add($this->parts['shoulder']->rotation->rotateVec3(new Vec3(0.0, -0.3, 0.0)));
        $this->assertTrue($this->parts['elbow']->position->equals($elbow, 1e-12));

        // The parts are children of the root: their world matrices carry the pose (what an
        // instanced drawer re-reading world matrices every frame draws).
        $this->world->addSystem(new Transform3DSystem());
        $this->world->update(0.0);
        $world = $this->parts['elbow']->getWorldMatrix()->getTranslation();
        $this->assertTrue($world->equals((new Vec3(5.0, 0.0, 0.0))->add($elbow), 1e-9));
    }

    public function testSpeedScalesTheClipTime(): void
    {
        $this->player->play('swing', blend: 0.0, speed: 0.5);
        $this->simulate(0.5);
        $this->assertEqualsWithDelta(30.0, $this->shoulderDegrees(), 1e-6);
    }

    public function testCrossfadeBlendsFromThePreviousClip(): void
    {
        $this->player->play('raise', blend: 0.0);
        $this->simulate(0.1);
        $this->assertEqualsWithDelta(-90.0, $this->shoulderDegrees(), 1e-6);

        $this->player->play('swing', blend: 0.5);
        $this->assertTrue($this->player->isBlending());
        $this->simulate(0.25);
        // Smoothstep at half the fade: 50/50 between -90° and the swing's 30°.
        $this->assertEqualsWithDelta(-30.0, $this->shoulderDegrees(), 1e-6);
        $this->simulate(0.3);
        $this->assertFalse($this->player->isBlending());
    }

    public function testOneShotHoldsItsLastPoseAndReportsFinished(): void
    {
        $this->player->play('bend', blend: 0.0);
        $this->simulate(0.3);
        $this->assertFalse($this->player->isFinished());
        $this->simulate(0.5);
        $this->assertTrue($this->player->isFinished());
        $v = $this->parts['elbow']->rotation->rotateVec3(new Vec3(0.0, 1.0, 0.0));
        $this->assertEqualsWithDelta(-80.0, rad2deg(atan2($v->z, $v->y)), 1e-6);
    }

    public function testIdleVariantsFollowTheSeedAndChangeAfterACycle(): void
    {
        $runs = [];
        for ($run = 0; $run < 2; $run++) {
            $this->setUp();
            $this->player->playIdle(['idle_a', 'idle_b', 'idle_c'], blend: 0.2);
            $sequence = [$this->player->clip];
            for ($i = 0; $i < 600; $i++) {
                $this->world->update(self::DT);
                if ($this->player->clip !== $sequence[count($sequence) - 1]) {
                    $sequence[] = $this->player->clip;
                }
            }
            $runs[] = $sequence;
        }
        $this->assertSame($runs[0], $runs[1]);
        $this->assertGreaterThanOrEqual(3, count($runs[0]), implode(',', $runs[0]));
        $this->assertCount(3, array_unique($runs[0]), 'all variants come up in 10 s');

        // Leaving idle mode by play() keeps the chosen clip from changing on cycle ends.
        $this->player->play('swing', blend: 0.0);
        $this->simulate(3.0);
        $this->assertSame('swing', $this->player->clip);
    }

    public function testFrozenUnderReducedMotionHoldsThePose(): void
    {
        EffectRegistry::register(new EffectDefinition('figure_motion', EffectTier::Low, null, EffectFallback::Same, EffectFallback::Same, ReducedMotion::Frozen, FlashClass::None));
        $this->player->effect = 'figure_motion';
        $this->player->play('swing', blend: 0.0);
        $this->simulate(0.25);
        EffectRegistry::setReducedMotion(true);
        $this->simulate(1.0);
        $this->assertEqualsWithDelta(30.0, $this->shoulderDegrees(), 1e-6);
    }

    public function testOutOfViewTheFigureFreezes(): void
    {
        $this->player->cullRadius = 1.5;
        $this->player->maxDistance = 20.0;
        $this->player->play('swing', blend: 0.0);
        $this->simulate(0.25);
        $this->system->view()->setEye(new Vec3(100.0, 0.0, 0.0));
        $this->simulate(0.2);
        $this->assertEqualsWithDelta(30.0, $this->shoulderDegrees(), 1e-6);
        $this->system->view()->setEye(new Vec3(5.0, 1.0, 3.0));
        $this->simulate(0.1);
        $this->assertEqualsWithDelta(42.0, $this->shoulderDegrees(), 1e-6);
    }

    public function testUnknownRigOrClipLeavesThePartsAlone(): void
    {
        $this->player->play('nope', blend: 0.0);
        $this->simulate(0.2);
        $this->assertTrue($this->parts['shoulder']->rotation->equals(Quaternion::identity()));
        $this->assertSame($this->rootId, $this->parts['shoulder']->parentEntityId);
    }
}
