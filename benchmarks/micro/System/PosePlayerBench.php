<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\System;

use PHPolygon\Animation\Pose;
use PHPolygon\Animation\PoseClip;
use PHPolygon\Animation\PoseLibrary;
use PHPolygon\Animation\PoseRig;
use PHPolygon\Component\PosePlayer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\System\PosePlayerSystem;

/**
 * CPU cost of one PosePlayerSystem update for 10 figures of 14 joints (two
 * three-part legs and arms, pelvis, spine, neck, head), each playing a four-key
 * walk cycle; half of them are inside a crossfade at any time.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/System/PosePlayerBench.php --report=aggregate
 */
final class PosePlayerBench
{
    private const int FIGURES = 10;

    private World $world;
    private PosePlayerSystem $system;
    private int $frame = 0;

    /** @var list<PosePlayer> */
    private array $players = [];

    public function setUp(): void
    {
        PoseLibrary::clear();
        $rig = (new PoseRig())
            ->joint('pelvis', null, new Vec3(0.0, 0.95, 0.0))
            ->joint('spine', 'pelvis', new Vec3(0.0, 0.2, 0.0))
            ->joint('neck', 'spine', new Vec3(0.0, 0.35, 0.0))
            ->joint('head', 'neck', new Vec3(0.0, 0.1, 0.0));
        foreach (['l' => 1.0, 'r' => -1.0] as $side => $sign) {
            $rig->joint("hip_{$side}", 'pelvis', new Vec3(0.1 * $sign, -0.05, 0.0))
                ->joint("knee_{$side}", "hip_{$side}", new Vec3(0.0, -0.42, 0.0))
                ->joint("ankle_{$side}", "knee_{$side}", new Vec3(0.0, -0.42, 0.0))
                ->joint("shoulder_{$side}", 'spine', new Vec3(0.19 * $sign, 0.3, 0.0))
                ->joint("elbow_{$side}", "shoulder_{$side}", new Vec3(0.0, -0.28, 0.0));
        }
        PoseLibrary::registerRig('figure', $rig);
        $stride = static fn (float $s): Pose => Pose::degrees([
            'hip_l' => [25.0 * $s], 'knee_l' => [max(0.0, -40.0 * $s)], 'hip_r' => [-25.0 * $s], 'knee_r' => [max(0.0, 40.0 * $s)],
            'shoulder_l' => [-20.0 * $s], 'elbow_l' => [-15.0], 'shoulder_r' => [20.0 * $s], 'elbow_r' => [-15.0],
            'spine' => [0.0, 4.0 * $s], 'head' => [0.0, -2.0 * $s],
        ], new Vec3(0.0, -0.02 * abs($s), 0.0));
        PoseLibrary::registerClip('walk', PoseClip::keyframes([[0.0, $stride(1.0)], [0.5, $stride(-1.0)], [1.0, $stride(1.0)]], smooth: false));
        PoseLibrary::registerClip('stand', PoseClip::keyframes([[0.0, new Pose()], [2.0, Pose::degrees(['head' => [5.0]])], [4.0, new Pose()]]));

        $this->world = new World();
        for ($f = 0; $f < self::FIGURES; $f++) {
            $root = $this->world->createEntity();
            $rootTransform = new Transform3D(position: new Vec3($f * 2.0, 0.0, 0.0));
            $root->attach($rootTransform);
            $player = new PosePlayer('figure', seed: $f);
            foreach ($rig->jointNames() as $joint) {
                $part = $this->world->createEntity();
                $t = new Transform3D(parentEntityId: $root->id);
                $part->attach($t);
                $rootTransform->addChild($t, $part->id, $root->id);
                $player->bind($joint, $part->id);
            }
            $root->attach($player);
            $player->play('walk', blend: 0.0);
            $this->players[] = $player;
        }
        $this->system = new PosePlayerSystem();
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(1000)
     * @Iterations(5)
     */
    public function benchUpdate(): void
    {
        // Every 30 frames half of the figures switch clips, so crossfades are always running.
        if (++$this->frame % 30 === 0) {
            foreach ($this->players as $i => $player) {
                if ($i % 2 === ($this->frame / 30) % 2) {
                    $player->play($player->clip === 'walk' ? 'stand' : 'walk', blend: 0.4);
                }
            }
        }
        $this->system->update($this->world, 1.0 / 60.0);
    }
}
