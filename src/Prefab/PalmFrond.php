<?php

declare(strict_types=1);

namespace PHPolygon\Prefab;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PalmSway;
use PHPolygon\Component\Transform3D;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\SceneBuilder;

class PalmFrond
{
    private const LEAF_COUNT = 5;
    private const LEAF_T_VALUES = [0.15, 0.30, 0.50, 0.70, 0.90];

    public function __construct(
        private readonly string $prefix,
        private readonly Vec3 $crownPos,
        private readonly float $yaw,
        private readonly float $elevation = -0.35,
        private readonly float $length = 2.4,
        private readonly float $swayPhase = 0.0,
        private readonly int $treeIndex = 0,
    ) {}

    public function build(SceneBuilder $builder): void
    {
        $this->buildStem($builder);
        $this->buildLeaves($builder);
    }

    private function buildStem(SceneBuilder $builder): void
    {
        $stemRotation = Quaternion::fromEuler($this->elevation, $this->yaw, 0.0);

        $stemSway = new PalmSway(
            swayStrength: 0.5 + fmod($this->swayPhase * 0.3, 0.4),
            phaseOffset: $this->swayPhase,
            isTrunk: false,
        );

        // CylinderMesh grows in Y; rotation orients it along the frond direction
        $stemOffset = $stemRotation->rotateVec3(new Vec3(0.0, $this->length * 0.5, 0.0));

        $builder->entity("{$this->prefix}_Stem")
            ->with(new Transform3D(
                position: $this->crownPos->add($stemOffset),
                rotation: $stemRotation,
                scale: new Vec3(0.02, $this->length * 0.5, 0.02),
            ))
            ->with(new MeshRenderer(meshId: 'cylinder', materialId: 'palm_branch'))
            ->with($stemSway);
    }

    private function buildLeaves(SceneBuilder $builder): void
    {
        $stemRotation = Quaternion::fromEuler($this->elevation, $this->yaw, 0.0);

        for ($i = 0; $i < self::LEAF_COUNT; $i++) {
            $t = self::LEAF_T_VALUES[$i];

            // Position along the stem
            $leafOffset = $stemRotation->rotateVec3(new Vec3(0.0, $this->length * $t, 0.0));
            $leafPos = $this->crownPos->add($leafOffset);

            // Tapering dimensions
            $leafWidth = 0.38 - $t * 0.14;
            $leafLength = 0.95 - $t * 0.25;

            // Droop increases toward tip
            $droopAngle = -0.25 - $t * 0.45;

            // Alternating side
            $sideSign = ($i % 2 === 0) ? 1.0 : -1.0;
            $leafYaw = $this->yaw + $sideSign * (M_PI / 2.0) + ($i * 0.08);

            $leafRotation = Quaternion::fromEuler($droopAngle, $leafYaw, 0.0);

            // Material variation based on tree index
            $matId = ($this->treeIndex % 2 === 0) ? 'palm_leaves' : 'palm_leaves_light';

            $leafSway = new PalmSway(
                swayStrength: 0.3 + $t * 0.4,
                phaseOffset: $this->swayPhase + $i * 0.5,
                isTrunk: false,
            );

            (new PalmLeaf(
                name: "{$this->prefix}_Leaf_{$i}",
                position: $leafPos,
                rotation: $leafRotation,
                width: $leafWidth,
                length: $leafLength,
                matId: $matId,
                sway: $leafSway,
            ))->build($builder);
        }
    }
}
