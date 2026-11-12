<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Animation\Face\Blink;
use PHPolygon\Animation\Face\ExpressionSet;
use PHPolygon\Animation\Face\LookAt;
use PHPolygon\Animation\Pose;
use PHPolygon\Animation\PoseClip;
use PHPolygon\Animation\PoseRig;
use PHPolygon\Animation\QuatMath;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MorphTargets;
use PHPolygon\Geometry\SkinnedMeshData;
use PHPolygon\Geometry\VatBaker;
use PHPolygon\Math\Vec3;
use PHPUnit\Framework\TestCase;

final class SkinningTest extends TestCase
{
    private function rig(): PoseRig
    {
        return (new PoseRig())
            ->joint('root', null, new Vec3(0.0, 0.0, 0.0))
            ->joint('arm', 'root', new Vec3(0.0, 1.0, 0.0));
    }

    private function skinned(): SkinnedMeshData
    {
        // Vertex 0 hangs on root, 1 on arm, 2 is half and half.
        $mesh = new MeshData(
            [0.0, 0.5, 0.0, 0.0, 1.5, 0.0, 0.0, 1.0, 0.0],
            [0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0],
            [0.0, 0.0, 1.0, 0.0, 0.5, 1.0],
            [0, 1, 2],
        );

        return SkinnedMeshData::fromInfluences($mesh, [['root' => 1.0], ['arm' => 3.0], ['root' => 1.0, 'arm' => 1.0]], $this->rig());
    }

    public function testBindPoseKeepsTheMeshAndWeightsAreNormalised(): void
    {
        $s = $this->skinned();
        self::assertSame(['root', 'arm'], $s->jointNames);
        for ($v = 0; $v < 3; $v++) {
            self::assertEqualsWithDelta(1.0, array_sum(array_slice($s->weights, $v * 4, 4)), 1e-9);
        }
        $out = $s->skin($s->palette($this->rig(), (new Pose())->raw()));
        foreach ($s->mesh->vertices as $i => $v) {
            self::assertEqualsWithDelta($v, $out->vertices[$i], 1e-6);
        }
    }

    public function testBendingTheArmMovesOnlyItsVertices(): void
    {
        $s = $this->skinned();
        $pose = Pose::degrees(['arm' => [0, 0, 90]]);
        $out = $s->skin($s->palette($this->rig(), $pose->raw()));
        // Root vertex stays; the arm vertex 0.5 above the elbow swings to the side (z roll 90 deg about the elbow at y = 1).
        self::assertEqualsWithDelta(0.5, $out->vertices[1], 1e-6);
        self::assertEqualsWithDelta(0.0, $out->vertices[0], 1e-6);
        self::assertEqualsWithDelta(1.0, $out->vertices[4], 1e-6);
        self::assertEqualsWithDelta(0.5, abs($out->vertices[3]), 1e-6);
        // The half-weighted vertex sits on the elbow: it does not move at all.
        self::assertEqualsWithDelta(1.0, $out->vertices[7], 1e-6);
        // Normals stay unit length.
        for ($v = 0; $v < 3; $v++) {
            $l = sqrt($out->normals[$v * 3] ** 2 + $out->normals[$v * 3 + 1] ** 2 + $out->normals[$v * 3 + 2] ** 2);
            self::assertEqualsWithDelta(1.0, $l, 1e-9);
        }
    }

    public function testBakeClipBoundsAndVatWithNormals(): void
    {
        $s = $this->skinned();
        $clip = PoseClip::keyframes([[0.0, new Pose()], [0.5, Pose::degrees(['arm' => [0, 0, 90]])], [1.0, new Pose()]]);
        $frames = $s->bakeClip($this->rig(), $clip, 8.0);
        self::assertCount(8, $frames);
        $vat = VatBaker::bake($frames, true);
        self::assertSame(3, $vat['texelsPerVertex']);
        self::assertSame(9, $vat['width']);
        self::assertSame(8, $vat['height']);
        $palettes = [];
        for ($i = 0; $i < 8; $i++) {
            $palettes[] = $s->palette($this->rig(), $clip->sampleRaw($i / 8.0));
        }
        $b = $s->boundsOver($palettes);
        self::assertGreaterThan(0.2, max(abs($b['min'][0]), abs($b['max'][0])), 'the swinging arm widens the box');
        foreach ($frames as $frame) {
            $fb = $frame->bounds();
            self::assertGreaterThanOrEqual($b['min'][0] - 1e-6, $fb['min']->x);
            self::assertLessThanOrEqual($b['max'][0] + 1e-6, $fb['max']->x);
            self::assertLessThanOrEqual($b['max'][1] + 1e-6, $fb['max']->y);
        }
    }

    public function testInvalidSkinDataIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SkinnedMeshData::fromInfluences(new MeshData([0, 0, 0], [], [], []), [['nope' => 1.0]], $this->rig());
    }

    public function testMorphTargetsAddWeightedSparseDeltas(): void
    {
        $base = new MeshData([0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 2.0, 0.0, 0.0], [0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0], [], [0, 1, 2]);
        $smile = new MeshData([0.0, 0.0, 0.0, 1.0, 0.2, 0.0, 2.0, 0.0, 0.0], [0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0], [], [0, 1, 2]);
        $m = MorphTargets::fromShapes($base, ['smile' => $smile]);
        self::assertSame([1], $m->targets['smile']['indices']);
        $half = $m->apply($base, ['smile' => 0.5, 'missing' => 1.0]);
        self::assertEqualsWithDelta(0.1, $half->vertices[4], 1e-9);
        self::assertSame($base->vertices[0], $half->vertices[0]);
        self::assertSame($base->vertices, $m->apply($base, [])->vertices);
    }

    public function testExpressionBlinkAndLookAt(): void
    {
        $set = new ExpressionSet(['neutral' => [], 'warm' => ['smile' => 1.0, 'brow_up' => 0.4], 'sad' => ['smile' => -0.5]]);
        self::assertEqualsWithDelta(0.5, $set->blend('neutral', 'warm', 0.5)['smile'] ?? 0.0, 1e-9);
        self::assertEqualsWithDelta(0.5, $set->weights(['warm' => 1.0, 'sad' => 1.0])['smile'], 1e-9);
        self::assertSame(1.0, ExpressionSet::layer(['smile' => 0.8], ['smile' => 0.9], ['smile' => [0.0, 1.0]])['smile']);

        $b = new Blink(seed: 5);
        $peak = 0.0;
        for ($t = 0.0; $t < 20.0; $t += 0.01) {
            $w = $b->weight($t);
            self::assertGreaterThanOrEqual(0.0, $w);
            self::assertLessThanOrEqual(1.0, $w);
            $peak = max($peak, $w);
            self::assertSame($w, (new Blink(seed: 5))->weight($t), 'stateless and deterministic');
        }
        self::assertGreaterThan(0.95, $peak);

        $q = LookAt::rotation([1.0, 0.0, 1.0], 1.0, 0.5);
        $d = QuatMath::rotate($q, 0.0, 0.0, 1.0);
        self::assertEqualsWithDelta(M_SQRT1_2, $d[0], 1e-9);
        self::assertEqualsWithDelta(M_SQRT1_2, $d[2], 1e-9);
        $up = LookAt::angles([0.0, 5.0, 1.0], 1.0, 0.3);
        self::assertEqualsWithDelta(0.3, $up['pitch'], 1e-12);
        $dq = QuatMath::rotate(LookAt::rotation([0.0, 1.0, 1.0], 1.0, 1.0), 0.0, 0.0, 1.0);
        self::assertGreaterThan(0.0, $dq[1]);
    }
}
