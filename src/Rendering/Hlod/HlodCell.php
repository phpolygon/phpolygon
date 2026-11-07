<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Hlod;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMesh;

/**
 * One HLOD cell (shader plan 9.1, {@see HlodBuilder}): the static draws of a region
 * merged and simplified into one proxy mesh per material. Beyond $switchDistance from
 * the cell's bounding sphere the proxies stand in for the region's own draws.
 */
final readonly class HlodCell
{
    /**
     * @param list<array{0: string, 1: string}> $proxies mesh id, material id
     */
    public function __construct(
        public string $id,
        public Vec3 $center,
        public float $radius,
        public float $switchDistance,
        public array $proxies,
        public int $sourceTriangles,
        public int $proxyTriangles,
    ) {}

    /** True when the camera is far enough for the proxies to stand in. */
    public function useProxy(Vec3 $camera): bool
    {
        return $camera->sub($this->center)->length() - $this->radius > $this->switchDistance;
    }

    /**
     * The proxy draws (world-space meshes: identity model matrix).
     *
     * @return list<DrawMesh>
     */
    public function proxyDraws(): array
    {
        $draws = [];
        foreach ($this->proxies as [$meshId, $materialId]) {
            $draws[] = new DrawMesh($meshId, $materialId, Mat4::identity());
        }
        return $draws;
    }
}
