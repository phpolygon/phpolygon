<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\MeshCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\BVH;
use PHPolygon\Physics\CollisionMath;
use PHPolygon\Physics\Triangle;

class Physics3DSystem extends AbstractSystem
{
    private Vec3 $gravity;
    private float $groundPlaneY;

    /** Maximum mesh-collider resolution iterations per character per frame */
    private const MESH_ITERATIONS = 4;

    public function __construct(?Vec3 $gravity = null, float $groundPlaneY = 0.0)
    {
        $this->gravity = $gravity ?? new Vec3(0.0, -9.81, 0.0);
        $this->groundPlaneY = $groundPlaneY;
    }

    public function setGravity(Vec3 $gravity): void
    {
        $this->gravity = $gravity;
    }

    public function getGravity(): Vec3
    {
        return $this->gravity;
    }

    public function update(World $world, float $dt): void
    {
        // Collect all static colliders once per frame
        $staticColliders = $this->collectStaticColliders($world);
        $meshColliders = $this->collectMeshColliders($world);

        foreach ($world->query(CharacterController3D::class, Transform3D::class) as $entity) {
            $controller = $entity->get(CharacterController3D::class);
            $transform = $entity->get(Transform3D::class);

            // Apply gravity
            if (!$controller->isGrounded) {
                $controller->velocity = $controller->velocity->add($this->gravity->mul($dt));
            }

            // Integrate velocity
            $newPos = $transform->position->add($controller->velocity->mul($dt));

            // Build character capsule AABB
            $halfHeight = $controller->height / 2.0;
            $radius = $controller->radius;
            $charMin = new Vec3(
                $newPos->x - $radius,
                $newPos->y - $halfHeight,
                $newPos->z - $radius,
            );
            $charMax = new Vec3(
                $newPos->x + $radius,
                $newPos->y + $halfHeight,
                $newPos->z + $radius,
            );

            // Ground detection: floor at configurable Y
            $controller->isGrounded = false;
            if ($charMin->y <= $this->groundPlaneY) {
                $newPos = new Vec3($newPos->x, $this->groundPlaneY + $halfHeight, $newPos->z);
                $controller->velocity = new Vec3($controller->velocity->x, 0.0, $controller->velocity->z);
                $controller->isGrounded = true;
                // Recompute AABB after ground snap
                $charMin = new Vec3($newPos->x - $radius, $newPos->y - $halfHeight, $newPos->z - $radius);
                $charMax = new Vec3($newPos->x + $radius, $newPos->y + $halfHeight, $newPos->z + $radius);
            }

            // Resolve AABB collisions against static box colliders
            foreach ($staticColliders as $collider) {
                if ($collider['entityId'] === $entity->id) {
                    continue;
                }

                $resolution = self::resolveAABB($charMin, $charMax, $collider['min'], $collider['max']);
                if ($resolution !== null) {
                    $newPos = $newPos->add($resolution);

                    // Zero velocity along collision normal
                    if (abs($resolution->x) > 0.0001) {
                        $controller->velocity = new Vec3(0.0, $controller->velocity->y, $controller->velocity->z);
                    }
                    if (abs($resolution->y) > 0.0001) {
                        $controller->velocity = new Vec3($controller->velocity->x, 0.0, $controller->velocity->z);
                        if ($resolution->y > 0) {
                            $controller->isGrounded = true;
                        }
                    }
                    if (abs($resolution->z) > 0.0001) {
                        $controller->velocity = new Vec3($controller->velocity->x, $controller->velocity->y, 0.0);
                    }

                    // Update AABB for next collision test
                    $charMin = new Vec3($newPos->x - $radius, $newPos->y - $halfHeight, $newPos->z - $radius);
                    $charMax = new Vec3($newPos->x + $radius, $newPos->y + $halfHeight, $newPos->z + $radius);
                }
            }

            // Resolve mesh collider collisions (BVH-accelerated triangle tests)
            if (!empty($meshColliders)) {
                $newPos = $this->resolveMeshCollisions(
                    $newPos,
                    $controller,
                    $halfHeight,
                    $radius,
                    $meshColliders,
                    $entity->id,
                );

                // Final AABB update after mesh collision resolution
                $charMin = new Vec3($newPos->x - $radius, $newPos->y - $halfHeight, $newPos->z - $radius);
                $charMax = new Vec3($newPos->x + $radius, $newPos->y + $halfHeight, $newPos->z + $radius);
            }

            $transform->position = $newPos;
            $transform->worldMatrix = $transform->getLocalMatrix();
        }
    }

    /**
     * Collect all static BoxCollider3D world AABBs.
     *
     * @return list<array{entityId: int, min: Vec3, max: Vec3}>
     */
    private function collectStaticColliders(World $world): array
    {
        $colliders = [];
        foreach ($world->query(BoxCollider3D::class, Transform3D::class) as $entity) {
            $collider = $entity->get(BoxCollider3D::class);
            if (!$collider->isStatic) {
                continue;
            }

            $transform = $entity->get(Transform3D::class);
            $pos = $transform->getWorldPosition();

            // Apply scale to collider size
            $scaledSize = new Vec3(
                $collider->size->x * $transform->scale->x,
                $collider->size->y * $transform->scale->y,
                $collider->size->z * $transform->scale->z,
            );

            $center = $pos->add($collider->offset);
            $halfSize = new Vec3($scaledSize->x * 0.5, $scaledSize->y * 0.5, $scaledSize->z * 0.5);

            $colliders[] = [
                'entityId' => $entity->id,
                'min' => $center->sub($halfSize),
                'max' => $center->add($halfSize),
            ];
        }
        return $colliders;
    }

    /**
     * Collect all MeshCollider3D components, lazily building BVH as needed.
     * Returns world-space BVH data for each mesh collider entity.
     *
     * @return list<array{entityId: int, bvh: BVH}>
     */
    private function collectMeshColliders(World $world): array
    {
        $colliders = [];

        foreach ($world->query(MeshCollider3D::class, Transform3D::class) as $entity) {
            $meshCollider = $entity->get(MeshCollider3D::class);
            if (!$meshCollider->isStatic || $meshCollider->isTrigger) {
                continue;
            }

            $transform = $entity->get(Transform3D::class);
            $worldMatrix = $transform->getWorldMatrix();
            $worldMatrixArr = $worldMatrix->toArray();

            // Check if BVH needs to be (re)built: first use or transform changed
            $needsRebuild = $meshCollider->bvh === null
                || $meshCollider->lastWorldMatrixArr === null
                || !self::matrixArrayEquals($worldMatrixArr, $meshCollider->lastWorldMatrixArr);

            if ($needsRebuild) {
                $meshData = MeshRegistry::get($meshCollider->meshId);
                if ($meshData === null) {
                    continue;
                }

                $triangles = self::meshDataToWorldTriangles($meshData, $worldMatrix);
                $meshCollider->bvh = BVH::build($triangles);
                $meshCollider->lastWorldMatrixArr = $worldMatrixArr;
            }

            if ($meshCollider->bvh !== null) {
                $colliders[] = [
                    'entityId' => $entity->id,
                    'bvh' => $meshCollider->bvh,
                ];
            }
        }

        return $colliders;
    }

    /**
     * Resolve capsule vs mesh-collider triangle collisions.
     * Iterates up to MESH_ITERATIONS times for convergence.
     *
     * @param list<array{entityId: int, bvh: BVH}> $meshColliders
     */
    private function resolveMeshCollisions(
        Vec3 $pos,
        CharacterController3D $controller,
        float $halfHeight,
        float $radius,
        array $meshColliders,
        int $characterEntityId,
    ): Vec3 {
        // Capsule medial axis: from bottom-center+radius to top-center-radius
        // The capsule extends from pos.y - halfHeight to pos.y + halfHeight
        // Medial segment goes from pos.y - halfHeight + radius to pos.y + halfHeight - radius
        $capsuleHalfLen = max(0.0, $halfHeight - $radius);

        for ($iteration = 0; $iteration < self::MESH_ITERATIONS; $iteration++) {
            $segA = new Vec3($pos->x, $pos->y - $capsuleHalfLen, $pos->z);
            $segB = new Vec3($pos->x, $pos->y + $capsuleHalfLen, $pos->z);

            // Capsule AABB for BVH query
            $queryMin = new Vec3(
                $pos->x - $radius,
                $pos->y - $halfHeight,
                $pos->z - $radius,
            );
            $queryMax = new Vec3(
                $pos->x + $radius,
                $pos->y + $halfHeight,
                $pos->z + $radius,
            );

            $hadCollision = false;

            foreach ($meshColliders as $mc) {
                if ($mc['entityId'] === $characterEntityId) {
                    continue;
                }

                /** @var BVH $bvh */
                $bvh = $mc['bvh'];
                $candidateTriangles = $bvh->query($queryMin, $queryMax);

                foreach ($candidateTriangles as $triangle) {
                    $resolution = CollisionMath::capsuleVsTriangle($segA, $segB, $radius, $triangle);
                    if ($resolution === null) {
                        continue;
                    }

                    $pos = $pos->add($resolution);
                    $hadCollision = true;

                    // Zero velocity along collision direction
                    $resLen = $resolution->length();
                    if ($resLen > 0.0001) {
                        $resDir = $resolution->div($resLen);

                        // Project velocity onto the resolution direction and remove that component
                        $velDotRes = $controller->velocity->dot($resDir);
                        if ($velDotRes < 0.0) {
                            $controller->velocity = $controller->velocity->sub($resDir->mul($velDotRes));
                        }

                        // Check if triangle normal points upward (grounding)
                        if ($resDir->y > 0.5) {
                            $controller->isGrounded = true;
                        }
                    }

                    // Update capsule segment for next triangle test within this iteration
                    $segA = new Vec3($pos->x, $pos->y - $capsuleHalfLen, $pos->z);
                    $segB = new Vec3($pos->x, $pos->y + $capsuleHalfLen, $pos->z);
                    $queryMin = new Vec3($pos->x - $radius, $pos->y - $halfHeight, $pos->z - $radius);
                    $queryMax = new Vec3($pos->x + $radius, $pos->y + $halfHeight, $pos->z + $radius);
                }
            }

            if (!$hadCollision) {
                break; // Converged — no more penetrations
            }
        }

        return $pos;
    }

    /**
     * Convert MeshData triangles to world-space Triangle objects using a world matrix.
     *
     * @return Triangle[]
     */
    private static function meshDataToWorldTriangles(MeshData $meshData, Mat4 $worldMatrix): array
    {
        $vertices = $meshData->vertices;
        $indices = $meshData->indices;
        $triangles = [];

        $indexCount = count($indices);
        for ($i = 0; $i < $indexCount; $i += 3) {
            $i0 = $indices[$i];
            $i1 = $indices[$i + 1];
            $i2 = $indices[$i + 2];

            $v0 = $worldMatrix->transformPoint(new Vec3(
                (float)$vertices[$i0 * 3],
                (float)$vertices[$i0 * 3 + 1],
                (float)$vertices[$i0 * 3 + 2],
            ));
            $v1 = $worldMatrix->transformPoint(new Vec3(
                (float)$vertices[$i1 * 3],
                (float)$vertices[$i1 * 3 + 1],
                (float)$vertices[$i1 * 3 + 2],
            ));
            $v2 = $worldMatrix->transformPoint(new Vec3(
                (float)$vertices[$i2 * 3],
                (float)$vertices[$i2 * 3 + 1],
                (float)$vertices[$i2 * 3 + 2],
            ));

            $tri = new Triangle($v0, $v1, $v2);
            if (!$tri->isDegenerate()) {
                $triangles[] = $tri;
            }
        }

        return $triangles;
    }

    /**
     * Compare two column-major matrix float arrays for equality.
     *
     * @param float[] $a
     * @param float[] $b
     */
    private static function matrixArrayEquals(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        for ($i = 0; $i < 16; $i++) {
            if (abs((float)$a[$i] - (float)$b[$i]) > 1e-6) {
                return false;
            }
        }
        return true;
    }

    /**
     * Test AABB overlap and return the minimum penetration resolution vector.
     * Returns null if no overlap.
     */
    public static function resolveAABB(Vec3 $aMin, Vec3 $aMax, Vec3 $bMin, Vec3 $bMax): ?Vec3
    {
        $overlapX = min($aMax->x, $bMax->x) - max($aMin->x, $bMin->x);
        $overlapY = min($aMax->y, $bMax->y) - max($aMin->y, $bMin->y);
        $overlapZ = min($aMax->z, $bMax->z) - max($aMin->z, $bMin->z);

        if ($overlapX <= 0 || $overlapY <= 0 || $overlapZ <= 0) {
            return null;
        }

        // Push out along axis of minimum penetration
        $centerAx = ($aMin->x + $aMax->x) * 0.5;
        $centerBx = ($bMin->x + $bMax->x) * 0.5;
        $centerAy = ($aMin->y + $aMax->y) * 0.5;
        $centerBy = ($bMin->y + $bMax->y) * 0.5;
        $centerAz = ($aMin->z + $aMax->z) * 0.5;
        $centerBz = ($bMin->z + $bMax->z) * 0.5;

        if ($overlapX <= $overlapY && $overlapX <= $overlapZ) {
            $sign = $centerAx < $centerBx ? -1.0 : 1.0;
            return new Vec3($sign * $overlapX, 0.0, 0.0);
        }
        if ($overlapY <= $overlapX && $overlapY <= $overlapZ) {
            $sign = $centerAy < $centerBy ? -1.0 : 1.0;
            return new Vec3(0.0, $sign * $overlapY, 0.0);
        }
        $sign = $centerAz < $centerBz ? -1.0 : 1.0;
        return new Vec3(0.0, 0.0, $sign * $overlapZ);
    }
}
