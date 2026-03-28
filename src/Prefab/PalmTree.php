<?php

declare(strict_types=1);

namespace PHPolygon\Prefab;

use PHPolygon\Math\Vec3;
use PHPolygon\Scene\SceneBuilder;

class PalmTree
{
    public function __construct(
        private readonly string $prefix,
        private readonly Vec3 $basePos,
        private readonly float $height = 5.5,
        private readonly float $lean = 0.12,
        private readonly int $treeIndex = 0,
        private readonly int $frondCount = 10,
    ) {}

    public function build(SceneBuilder $builder): void
    {
        // 1. Trunk -> returns crown position
        $trunk = new PalmTrunk($this->prefix, $this->basePos, $this->height, $this->lean, $this->treeIndex);
        $crownPos = $trunk->build($builder);

        // 2. Canopy at crown
        $frondLength = 2.2 + ($this->height - 4.5) * 0.25;
        $yawOffset = $this->treeIndex * 0.55;
        (new PalmCanopy($this->prefix, $crownPos, $this->frondCount, $frondLength, $this->treeIndex, $yawOffset))
            ->build($builder);
    }
}
