<?php

declare(strict_types=1);

namespace PHPolygon\Navigation;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\HeightmapCollider3D;
use PHPolygon\Component\MeshCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\Triangle;

/**
 * Collects world geometry from ECS entities and builds a NavMesh.
 *
 * Iterates BoxCollider3D, MeshCollider3D, and HeightmapCollider3D
 * components to gather triangles, then feeds them to NavMeshGenerator.
 */
class NavMeshBuilder
{
    private NavMeshGenerator $generator;

    public function __construct(?NavMeshGeneratorConfig $config = null)
    {
        $this->generator = new NavMeshGenerator($config);
    }

    /**
     * Build a NavMesh from all static colliders in the world.
     */
    public function build(World $world): NavMesh
    {
        $triangles = $this->collectWorldGeometry($world);
        return $this->generator->generate($triangles);
    }

    /**
     * Build a NavMesh from pre-collected triangles.
     *
     * @param Triangle[] $triangles
     */
    public function buildFromTriangles(array $triangles): NavMesh
    {
        return $this->generator->generate($triangles);
    }

    /**
     * Collect all static collider geometry as world-space triangles.
     *
     * @return Triangle[]
     */
    public function collectWorldGeometry(World $world): array
    {
        $triangles = [];

        $this->collectBoxColliders($world, $triangles);
        $this->collectMeshColliders($world, $triangles);
        $this->collectHeightmapColliders($world, $triangles);

        return $triangles;
    }

    /**
     * @param Triangle[] $triangles
     */
    private function collectBoxColliders(World $world, array &$triangles): void
    {
        foreach ($world->query(BoxCollider3D::class, Transform3D::class) as $entity) {
            $collider = $entity->get(BoxCollider3D::class);
            $transform = $entity->get(Transform3D::class);

            if ($collider->isTrigger) {
                continue;
            }

            $worldMatrix = $transform->getWorldMatrix();
            $this->boxToTriangles($collider, $worldMatrix, $triangles);
        }
    }

    /**
     * @param Triangle[] $triangles
     */
    private function collectMeshColliders(World $world, array &$triangles): void
    {
        foreach ($world->query(MeshCollider3D::class, Transform3D::class) as $entity) {
            $collider = $entity->get(MeshCollider3D::class);
            $transform = $entity->get(Transform3D::class);

            if ($collider->isTrigger) {
                continue;
            }

            $meshData = MeshRegistry::get($collider->meshId);
            if ($meshData === null) {
                continue;
            }

            $worldMatrix = $transform->getWorldMatrix();
            $this->meshDataToTriangles($meshData, $worldMatrix, $triangles);
        }
    }

    /**
     * @param Triangle[] $triangles
     */
    private function collectHeightmapColliders(World $world, array &$triangles): void
    {
        foreach ($world->query(HeightmapCollider3D::class) as $entity) {
            $hm = $entity->get(HeightmapCollider3D::class);

            if (!$hm->isPopulated()) {
                continue;
            }

            $this->heightmapToTriangles($hm, $triangles);
        }
    }

    /**
     * Convert a box collider into world-space triangles (12 triangles for 6 faces).
     *
     * @param Triangle[] $triangles
     */
    private function boxToTriangles(BoxCollider3D $collider, Mat4 $worldMatrix, array &$triangles): void
    {
        $hx = $collider->size->x * 0.5;
        $hy = $collider->size->y * 0.5;
        $hz = $collider->size->z * 0.5;
        $off = $collider->offset;

        // 8 corners in local space -> world
        $c = [];
        foreach ([[-1, 1], [1, 1], [1, -1], [-1, -1]] as [$sx, $sz]) {
            foreach ([-1, 1] as $sy) {
                $c["{$sx}_{$sy}_{$sz}"] = $worldMatrix->transformPoint(new Vec3(
                    $off->x + $sx * $hx,
                    $off->y + $sy * $hy,
                    $off->z + $sz * $hz,
                ));
            }
        }

        $g = fn(int $sx, int $sy, int $sz): Vec3 => $c["{$sx}_{$sy}_{$sz}"];

        $faces = [
            [$g(1, -1, 1), $g(1, 1, 1), $g(1, 1, -1), $g(1, -1, -1)],
            [$g(-1, -1, -1), $g(-1, 1, -1), $g(-1, 1, 1), $g(-1, -1, 1)],
            [$g(-1, 1, 1), $g(-1, 1, -1), $g(1, 1, -1), $g(1, 1, 1)],
            [$g(-1, -1, -1), $g(-1, -1, 1), $g(1, -1, 1), $g(1, -1, -1)],
            [$g(-1, -1, 1), $g(-1, 1, 1), $g(1, 1, 1), $g(1, -1, 1)],
            [$g(1, -1, -1), $g(1, 1, -1), $g(-1, 1, -1), $g(-1, -1, -1)],
        ];

        foreach ($faces as [$v0, $v1, $v2, $v3]) {
            $t1 = new Triangle($v0, $v1, $v2);
            $t2 = new Triangle($v0, $v2, $v3);
            if (!$t1->isDegenerate()) $triangles[] = $t1;
            if (!$t2->isDegenerate()) $triangles[] = $t2;
        }
    }

    /**
     * Convert mesh data into world-space triangles.
     *
     * @param Triangle[] $triangles
     */
    private function meshDataToTriangles(MeshData $meshData, Mat4 $worldMatrix, array &$triangles): void
    {
        $vertices = $meshData->vertices;
        $indices = $meshData->indices;

        $indexCount = count($indices);
        for ($i = 0; $i < $indexCount; $i += 3) {
            $i0 = $indices[$i];
            $i1 = $indices[$i + 1];
            $i2 = $indices[$i + 2];

            $v0 = $worldMatrix->transformPoint(new Vec3(
                (float) $vertices[$i0 * 3],
                (float) $vertices[$i0 * 3 + 1],
                (float) $vertices[$i0 * 3 + 2],
            ));
            $v1 = $worldMatrix->transformPoint(new Vec3(
                (float) $vertices[$i1 * 3],
                (float) $vertices[$i1 * 3 + 1],
                (float) $vertices[$i1 * 3 + 2],
            ));
            $v2 = $worldMatrix->transformPoint(new Vec3(
                (float) $vertices[$i2 * 3],
                (float) $vertices[$i2 * 3 + 1],
                (float) $vertices[$i2 * 3 + 2],
            ));

            $tri = new Triangle($v0, $v1, $v2);
            if (!$tri->isDegenerate()) {
                $triangles[] = $tri;
            }
        }
    }

    /**
     * Convert a heightmap collider into world-space triangles.
     *
     * @param Triangle[] $triangles
     */
    private function heightmapToTriangles(HeightmapCollider3D $hm, array &$triangles): void
    {
        $xRange = $hm->worldMaxX - $hm->worldMinX;
        $zRange = $hm->worldMaxZ - $hm->worldMinZ;

        if ($xRange <= 0.0 || $zRange <= 0.0) {
            return;
        }

        $xStep = $xRange / ($hm->gridWidth - 1);
        $zStep = $zRange / ($hm->gridDepth - 1);

        // Generate two triangles per quad in the heightmap grid
        for ($zi = 0; $zi < $hm->gridDepth - 1; $zi++) {
            for ($xi = 0; $xi < $hm->gridWidth - 1; $xi++) {
                $x0 = $hm->worldMinX + $xi * $xStep;
                $x1 = $x0 + $xStep;
                $z0 = $hm->worldMinZ + $zi * $zStep;
                $z1 = $z0 + $zStep;

                $h00 = $hm->getHeightAt($x0, $z0);
                $h10 = $hm->getHeightAt($x1, $z0);
                $h01 = $hm->getHeightAt($x0, $z1);
                $h11 = $hm->getHeightAt($x1, $z1);

                $v00 = new Vec3($x0, $h00, $z0);
                $v10 = new Vec3($x1, $h10, $z0);
                $v01 = new Vec3($x0, $h01, $z1);
                $v11 = new Vec3($x1, $h11, $z1);

                $t1 = new Triangle($v00, $v10, $v11);
                $t2 = new Triangle($v00, $v11, $v01);
                if (!$t1->isDegenerate()) $triangles[] = $t1;
                if (!$t2->isDegenerate()) $triangles[] = $t2;
            }
        }
    }
}
