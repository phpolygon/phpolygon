<?php

declare(strict_types=1);

namespace PHPolygon\Prefab;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PalmSway;
use PHPolygon\Component\Transform3D;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\SceneBuilder;

class PalmLeaf
{
    public function __construct(
        private readonly string $name,
        private readonly Vec3 $position,
        private readonly Quaternion $rotation,
        private readonly float $width = 0.32,
        private readonly float $length = 0.85,
        private readonly string $matId = 'palm_leaves',
        private readonly ?PalmSway $sway = null,
    ) {}

    public function build(SceneBuilder $builder): void
    {
        $decl = $builder->entity($this->name)
            ->with(new Transform3D(
                position: $this->position,
                rotation: $this->rotation,
                scale: new Vec3($this->width * 0.5, 0.012, $this->length * 0.5),
            ))
            ->with(new MeshRenderer(meshId: 'box', materialId: $this->matId));

        if ($this->sway !== null) {
            $decl->with(clone $this->sway);
        }
    }
}
