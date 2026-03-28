<?php

declare(strict_types=1);

namespace PHPolygon\Prefab;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\SceneBuilder;

class PalmCanopy
{
    public function __construct(
        private readonly string $prefix,
        private readonly Vec3 $crownPos,
        private readonly int $frondCount = 10,
        private readonly float $frondLength = 2.5,
        private readonly int $treeIndex = 0,
        private readonly float $yawOffset = 0.0,
    ) {}

    public function build(SceneBuilder $builder): void
    {
        $this->buildCrownBase($builder);
        $this->buildCoconuts($builder);
        $this->buildFronds($builder);
    }

    private function buildCrownBase(SceneBuilder $builder): void
    {
        $builder->entity("{$this->prefix}_CrownBase")
            ->with(new Transform3D(
                position: $this->crownPos,
                scale: new Vec3(0.28, 0.22, 0.28),
            ))
            ->with(new MeshRenderer(meshId: 'sphere', materialId: 'palm_trunk'));
    }

    private function buildCoconuts(SceneBuilder $builder): void
    {
        if ($this->treeIndex % 3 !== 0) {
            return;
        }

        $count = 2 + ($this->treeIndex % 2);

        for ($c = 0; $c < $count; $c++) {
            $angle = ($c / $count) * 2.0 * M_PI;
            $pos = new Vec3(
                $this->crownPos->x + cos($angle) * 0.3,
                $this->crownPos->y - 0.35,
                $this->crownPos->z + sin($angle) * 0.3,
            );

            $builder->entity("{$this->prefix}_Coconut_{$c}")
                ->with(new Transform3D(
                    position: $pos,
                    scale: new Vec3(0.11, 0.13, 0.11),
                ))
                ->with(new MeshRenderer(meshId: 'sphere', materialId: 'coconut'));
        }
    }

    private function buildFronds(SceneBuilder $builder): void
    {
        for ($f = 0; $f < $this->frondCount; $f++) {
            $baseYaw = ($f / $this->frondCount) * 2.0 * M_PI + $this->yawOffset;
            $yawJitter = sin($f * 2.7 + $this->treeIndex * 1.3) * 0.18;
            $elevation = -0.30 - sin($f * 1.9 + $this->treeIndex * 0.7) * 0.20;
            $length = $this->frondLength * (0.87 + sin($f * 3.1 + $this->treeIndex) * 0.13);
            $swayPhase = $this->treeIndex * 1.3 + $f * (2.0 * M_PI / $this->frondCount);

            (new PalmFrond(
                prefix: "{$this->prefix}_Frond_{$f}",
                crownPos: $this->crownPos,
                yaw: $baseYaw + $yawJitter,
                elevation: $elevation,
                length: $length,
                swayPhase: $swayPhase,
                treeIndex: $this->treeIndex,
            ))->build($builder);
        }
    }
}
