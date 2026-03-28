<?php

declare(strict_types=1);

namespace PHPolygon\Prefab;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PalmSway;
use PHPolygon\Component\Transform3D;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\SceneBuilder;

class PalmTrunk
{
    private const RING_POSITIONS = [0.22, 0.47, 0.72];

    public function __construct(
        private readonly string $prefix,
        private readonly Vec3 $basePos,
        private readonly float $height = 5.5,
        private readonly float $lean = 0.12,
        private readonly int $treeIndex = 0,
    ) {}

    /**
     * Build the trunk entities and return the crown position.
     */
    public function build(SceneBuilder $builder): Vec3
    {
        $leanRot = Quaternion::fromAxisAngle(new Vec3(0.0, 0.0, 1.0), $this->lean);

        $trunkSway = new PalmSway(
            swayStrength: 0.4 + fmod($this->treeIndex * 0.11, 0.3),
            phaseOffset: $this->treeIndex * 1.3,
            isTrunk: true,
        );

        $this->buildLowerTrunk($builder, $leanRot, clone $trunkSway);
        $this->buildUpperTrunk($builder, $leanRot, clone $trunkSway);
        $this->buildRings($builder, $leanRot);
        $this->buildCollider($builder);

        // Crown position: base + lean offset
        $crownX = $this->basePos->x + sin($this->lean) * $this->height;
        return new Vec3($crownX, $this->basePos->y + $this->height, $this->basePos->z);
    }

    private function buildLowerTrunk(SceneBuilder $builder, Quaternion $leanRot, PalmSway $sway): void
    {
        $lowerH = $this->height * 0.55;

        $builder->entity("{$this->prefix}_TrunkLower")
            ->with(new Transform3D(
                position: new Vec3(
                    $this->basePos->x + sin($this->lean) * $lowerH * 0.5,
                    $this->basePos->y + $lowerH * 0.5,
                    $this->basePos->z,
                ),
                rotation: $leanRot,
                scale: new Vec3(0.19, $lowerH * 0.5, 0.19),
            ))
            ->with(new MeshRenderer(meshId: 'cylinder', materialId: 'palm_trunk'))
            ->with($sway);
    }

    private function buildUpperTrunk(SceneBuilder $builder, Quaternion $leanRot, PalmSway $sway): void
    {
        $lowerH = $this->height * 0.55;
        $upperH = $this->height * 0.50;
        $overlapStart = $this->height * 0.52;

        $builder->entity("{$this->prefix}_TrunkUpper")
            ->with(new Transform3D(
                position: new Vec3(
                    $this->basePos->x + sin($this->lean) * ($overlapStart + $upperH * 0.5),
                    $this->basePos->y + $overlapStart + $upperH * 0.5,
                    $this->basePos->z,
                ),
                rotation: $leanRot,
                scale: new Vec3(0.15, $upperH * 0.5, 0.15),
            ))
            ->with(new MeshRenderer(
                meshId: 'cylinder',
                materialId: ($this->treeIndex % 2 === 0) ? 'palm_trunk' : 'palm_trunk_dark',
            ))
            ->with($sway);
    }

    private function buildRings(SceneBuilder $builder, Quaternion $leanRot): void
    {
        foreach (self::RING_POSITIONS as $r => $fraction) {
            $ringY = $this->height * $fraction;

            $builder->entity("{$this->prefix}_Ring_{$r}")
                ->with(new Transform3D(
                    position: new Vec3(
                        $this->basePos->x + sin($this->lean) * $ringY,
                        $this->basePos->y + $ringY,
                        $this->basePos->z,
                    ),
                    rotation: $leanRot,
                    scale: new Vec3(0.23, 0.035, 0.23),
                ))
                ->with(new MeshRenderer(meshId: 'cylinder', materialId: 'palm_trunk_ring'));
        }
    }

    private function buildCollider(SceneBuilder $builder): void
    {
        $builder->entity("{$this->prefix}_Collider")
            ->with(new Transform3D(
                position: new Vec3(
                    $this->basePos->x + sin($this->lean) * $this->height * 0.5,
                    $this->basePos->y + $this->height * 0.5,
                    $this->basePos->z,
                ),
            ))
            ->with(new BoxCollider3D(
                size: new Vec3(0.9, $this->height, 0.9),
                isStatic: true,
            ));
    }
}
