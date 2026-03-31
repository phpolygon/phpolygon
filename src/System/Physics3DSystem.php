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
        // Collect all box colliders once per frame (static + dynamic)
        $boxData = $this->collectBoxColliders($world);
        $staticColliders = $boxData['aabbs'];
        $meshColliders = $this->collectMeshColliders($world);
        // Merge rotated-box BVHs into mesh colliders for triangle-based resolution
        foreach ($boxData['meshColliders'] as $mc) {
            $meshColliders[] = $mc;
        }

        foreach ($world->query(CharacterController3D::class, Transform3D::class) as $entity) {
            $controller = $entity->get(CharacterController3D::class);
            $transform = $entity->get(Transform3D::class);

            // Handle non-physics states (coupling, animation)
            if (!$controller->isPhysicsActive()) {
                $this->updateNonPhysicsState($world, $controller, $transform, $dt);
                continue;
            }

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

            // Reset grounded flag — will be set by collision response below
            $controller->isGrounded = false;

            // Resolve AABB collisions against box colliders with step-climbing
            foreach ($staticColliders as $collider) {
                if ($collider['entityId'] === $entity->id) {
                    continue;
                }

                $resolution = self::resolveAABB($charMin, $charMax, $collider['min'], $collider['max']);
                if ($resolution === null) {
                    continue;
                }

                // Step-climbing: if the collision is horizontal (X or Z) and
                // the collider top is within stepHeight of the character's feet,
                // lift the character onto the surface instead of pushing sideways.
                $isHorizontalPush = abs($resolution->x) > 0.0001 || abs($resolution->z) > 0.0001;
                $feetY = $newPos->y - $halfHeight;
                $colliderTopY = $collider['max']->y;
                $stepUp = $colliderTopY - $feetY;

                if ($isHorizontalPush && abs($resolution->y) < 0.0001
                    && $stepUp > 0.0 && $stepUp <= $controller->stepHeight
                ) {
                    // Lift character onto the step
                    $newPos = new Vec3($newPos->x, $colliderTopY + $halfHeight, $newPos->z);
                    $controller->velocity = new Vec3($controller->velocity->x, 0.0, $controller->velocity->z);
                    $controller->isGrounded = true;
                } else {
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
                }

                // Update AABB for next collision test
                $charMin = new Vec3($newPos->x - $radius, $newPos->y - $halfHeight, $newPos->z - $radius);
                $charMax = new Vec3($newPos->x + $radius, $newPos->y + $halfHeight, $newPos->z + $radius);
            }

            // Resolve mesh collider collisions (BVH-accelerated triangle tests)
            // Also pass box collider data for step-climbing on rotated boxes.
            if (!empty($meshColliders)) {
                $newPos = $this->resolveMeshCollisions(
                    $newPos,
                    $controller,
                    $halfHeight,
                    $radius,
                    $meshColliders,
                    $entity->id,
                    $boxData['boxTopY'],
                );

                // Final AABB update after mesh collision resolution
                $charMin = new Vec3($newPos->x - $radius, $newPos->y - $halfHeight, $newPos->z - $radius);
                $charMax = new Vec3($newPos->x + $radius, $newPos->y + $halfHeight, $newPos->z + $radius);
            }

            // Safety net: prevent falling through the world
            $charMinY = $newPos->y - $halfHeight;
            if ($charMinY <= $this->groundPlaneY) {
                $newPos = new Vec3($newPos->x, $this->groundPlaneY + $halfHeight, $newPos->z);
                $controller->velocity = new Vec3(0.0, 0.0, 0.0);
                $controller->isGrounded = true;
            }

            // Ground friction: rapidly decay horizontal velocity when grounded.
            // Player movement is applied via position (not velocity) by game systems,
            // so residual velocity from collision pushback should decay quickly.
            if ($controller->isGrounded) {
                $friction = max(0.0, 1.0 - 12.0 * $dt); // ~92% decay per frame at 60fps
                $controller->velocity = new Vec3(
                    $controller->velocity->x * $friction,
                    $controller->velocity->y,
                    $controller->velocity->z * $friction,
                );
                // Kill very small horizontal velocity to prevent drift
                if (abs($controller->velocity->x) < 0.01 && abs($controller->velocity->z) < 0.01) {
                    $controller->velocity = new Vec3(0.0, $controller->velocity->y, 0.0);
                }
            }

            $transform->position = $newPos;
            $transform->worldMatrix = $transform->getLocalMatrix();
        }
    }

    /**
     * Handle characters that are coupled to objects or playing animations.
     */
    private function updateNonPhysicsState(
        World $world,
        CharacterController3D $controller,
        Transform3D $transform,
        float $dt,
    ): void {
        // Coupled to another entity (seat, vehicle, mount)
        if ($controller->coupledToEntity !== null) {
            $targetTransform = $world->tryGetComponent($controller->coupledToEntity, Transform3D::class);
            if ($targetTransform !== null) {
                // Follow the coupled entity's position with local offset
                if ($controller->coupledInheritRotation) {
                    $worldOffset = $targetTransform->rotation->rotateVec3($controller->coupledOffset);
                    $transform->position = $targetTransform->getWorldPosition()->add($worldOffset);
                    $transform->rotation = $targetTransform->rotation;
                } else {
                    $transform->position = $targetTransform->getWorldPosition()->add($controller->coupledOffset);
                }
                $transform->worldMatrix = $transform->getLocalMatrix();
            } else {
                // Coupled entity was destroyed — decouple
                $controller->decouple();
            }
            return;
        }

        // Scripted animation
        if ($controller->state === \PHPolygon\Component\CharacterState::Animated
            && $controller->animationCallback !== null
        ) {
            $controller->animationElapsed += $dt;
            $continue = ($controller->animationCallback)(
                $controller,
                $transform,
                $dt,
                $controller->animationElapsed,
            );
            if (!$continue) {
                $controller->animationCallback = null;
                $controller->state = \PHPolygon\Component\CharacterState::Walking;
            }
            $transform->worldMatrix = $transform->getLocalMatrix();
        }
    }

    /**
     * Collect all BoxCollider3D as world-space data.
     * Non-rotated boxes return AABBs for fast axis-aligned resolution.
     * Rotated boxes are converted to BVH triangles for correct surface-normal resolution.
     *
     * @return array{aabbs: list<array{entityId: int, min: Vec3, max: Vec3}>, meshColliders: list<array{entityId: int, bvh: BVH}>, boxTopY: array<int, float>}
     */
    private function collectBoxColliders(World $world): array
    {
        $aabbs = [];
        $meshColliders = [];
        $boxTopY = []; // Entity ID → max Y of collider (for step-climbing on rotated boxes)

        foreach ($world->query(BoxCollider3D::class, Transform3D::class) as $entity) {
            $collider = $entity->get(BoxCollider3D::class);
            if ($collider->isTrigger) {
                continue;
            }

            $transform = $entity->get(Transform3D::class);
            $worldMatrix = $transform->getWorldMatrix();

            // Check if entity has meaningful rotation (non-identity)
            $rot = $transform->rotation;
            $isRotated = abs($rot->x) > 0.001 || abs($rot->y) > 0.001 || abs($rot->z) > 0.001;
            if ($isRotated && abs(abs($rot->w) - 1.0) < 0.001) {
                $isRotated = false;
            }

            if ($isRotated) {
                $triangles = self::boxToWorldTriangles($collider, $worldMatrix);
                $bvh = BVH::build($triangles);
                $meshColliders[] = [
                    'entityId' => $entity->id,
                    'bvh' => $bvh,
                ];
                // Store the AABB top Y for step-climbing
                $aabb = $collider->getWorldAABB($worldMatrix);
                $boxTopY[$entity->id] = $aabb['max']->y;
            } else {
                $aabb = $collider->getWorldAABB($worldMatrix);
                $aabbs[] = [
                    'entityId' => $entity->id,
                    'min' => $aabb['min'],
                    'max' => $aabb['max'],
                ];
            }
        }

        return ['aabbs' => $aabbs, 'meshColliders' => $meshColliders, 'boxTopY' => $boxTopY];
    }

    /**
     * Convert a BoxCollider3D into 12 world-space triangles (6 faces × 2 tris).
     *
     * @return Triangle[]
     */
    private static function boxToWorldTriangles(BoxCollider3D $collider, Mat4 $worldMatrix): array
    {
        $hx = $collider->size->x * 0.5;
        $hy = $collider->size->y * 0.5;
        $hz = $collider->size->z * 0.5;
        $off = $collider->offset;

        // 8 corners in local space
        $corners = [];
        foreach ([[-1,1], [1,1], [1,-1], [-1,-1]] as [$sx, $sz]) {
            foreach ([-1, 1] as $sy) {
                $corners["{$sx}_{$sy}_{$sz}"] = $worldMatrix->transformPoint(new Vec3(
                    $off->x + $sx * $hx,
                    $off->y + $sy * $hy,
                    $off->z + $sz * $hz,
                ));
            }
        }

        // Helper to get corners by sign indices
        $c = fn(int $sx, int $sy, int $sz) => $corners["{$sx}_{$sy}_{$sz}"];

        $triangles = [];
        // 6 faces, each as 2 triangles (CCW winding from outside)
        $faces = [
            // +X face
            [$c(1,-1,1), $c(1,1,1), $c(1,1,-1), $c(1,-1,-1)],
            // -X face
            [$c(-1,-1,-1), $c(-1,1,-1), $c(-1,1,1), $c(-1,-1,1)],
            // +Y face
            [$c(-1,1,1), $c(-1,1,-1), $c(1,1,-1), $c(1,1,1)],
            // -Y face
            [$c(-1,-1,-1), $c(-1,-1,1), $c(1,-1,1), $c(1,-1,-1)],
            // +Z face
            [$c(-1,-1,1), $c(-1,1,1), $c(1,1,1), $c(1,-1,1)],
            // -Z face
            [$c(1,-1,-1), $c(1,1,-1), $c(-1,1,-1), $c(-1,-1,-1)],
        ];

        foreach ($faces as [$v0, $v1, $v2, $v3]) {
            $t1 = new Triangle($v0, $v1, $v2);
            $t2 = new Triangle($v0, $v2, $v3);
            if (!$t1->isDegenerate()) $triangles[] = $t1;
            if (!$t2->isDegenerate()) $triangles[] = $t2;
        }

        return $triangles;
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
            if ($meshCollider->isTrigger) {
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

            $colliders[] = [
                'entityId' => $entity->id,
                'bvh' => $meshCollider->bvh,
            ];
        }

        return $colliders;
    }

    /**
     * Resolve capsule vs mesh-collider triangle collisions.
     * Iterates up to MESH_ITERATIONS times for convergence.
     *
     * @param list<array{entityId: int, bvh: BVH}> $meshColliders
     * @param array<int, float> $boxTopY Entity ID → top Y for step-climbing
     */
    private function resolveMeshCollisions(
        Vec3 $pos,
        CharacterController3D $controller,
        float $halfHeight,
        float $radius,
        array $meshColliders,
        int $characterEntityId,
        array $boxTopY = [],
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

                    // Step-climbing for rotated box colliders:
                    // If the collision pushes horizontally and the box top is within stepHeight,
                    // lift the character onto the surface instead.
                    $isHorizontalPush = abs($resolution->y) < abs($resolution->x) + abs($resolution->z);
                    if ($isHorizontalPush && isset($boxTopY[$mc['entityId']])) {
                        $feetY = $pos->y - $halfHeight;
                        $topY = $boxTopY[$mc['entityId']];
                        $stepUp = $topY - $feetY;
                        if ($stepUp > 0.0 && $stepUp <= $controller->stepHeight) {
                            $pos = new Vec3($pos->x, $topY + $halfHeight, $pos->z);
                            $controller->velocity = new Vec3($controller->velocity->x, 0.0, $controller->velocity->z);
                            $controller->isGrounded = true;
                            $hadCollision = true;
                            continue; // Skip normal resolution — we stepped up
                        }
                    }

                    $pos = $pos->add($resolution);
                    $hadCollision = true;

                    // Zero velocity along collision direction
                    $resLen = $resolution->length();
                    if ($resLen > 0.0001) {
                        $resDir = $resolution->div($resLen);

                        $velDotRes = $controller->velocity->dot($resDir);
                        if ($velDotRes < 0.0) {
                            $controller->velocity = $controller->velocity->sub($resDir->mul($velDotRes));
                        }

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
