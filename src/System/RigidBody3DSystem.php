<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\BodyType;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\RigidBody3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\Collision3D;
use PHPolygon\Physics\SpatialHash3D;

/**
 * Rigid body physics: gravity, impulse-based collision response, sleep management.
 * Handles Dynamic-vs-Static, Dynamic-vs-Dynamic, Dynamic-vs-Kinematic, and Character-push.
 *
 * Register AFTER Physics3DSystem in the system order.
 */
class RigidBody3DSystem extends AbstractSystem
{
    private Vec3 $gravity;
    private int $solverIterations;
    private SpatialHash3D $spatialHash;
    private float $characterPushForce;

    // No static cache — re-collected each frame for correctness with kinematic movers

    public function __construct(
        ?Vec3 $gravity = null,
        int $solverIterations = 2,
        float $characterPushForce = 50.0,
        float $spatialCellSize = 2.0,
    ) {
        $this->gravity = $gravity ?? new Vec3(0.0, -9.81, 0.0);
        $this->solverIterations = $solverIterations;
        $this->characterPushForce = $characterPushForce;
        $this->spatialHash = new SpatialHash3D($spatialCellSize);
    }

    public function update(World $world, float $dt): void
    {
        if ($dt <= 0.0) return;

        // 1. Collect bodies
        $bodies = $this->collectBodies($world);
        if (empty($bodies)) return;

        // 2. Compute kinematic velocities from position delta
        $this->computeKinematicVelocities($bodies, $dt);

        // 3. Integrate dynamic bodies (gravity + damping + velocity → position)
        $this->integrate($bodies, $dt);

        // 4-6. Broadphase → Narrowphase → Solve (body-vs-body)
        $collisions = $this->detectAndSolveBodyCollisions($bodies);

        // 7. Dynamic vs static colliders
        $this->resolveStaticCollisions($world, $bodies);

        // 8. Character push
        $this->characterPush($world, $bodies, $dt);

        // 9. Sleep management
        foreach ($bodies as &$body) {
            $body['rigid']->updateSleep();
        }

        // 10. Write back to transforms
        $this->writeBack($bodies);
    }

    /**
     * @return array<int, array{entityId: int, rigid: RigidBody3D, transform: Transform3D, collider: BoxCollider3D, min: Vec3, max: Vec3, posX: float, posY: float, posZ: float}>
     */
    private function collectBodies(World $world): array
    {
        $bodies = [];
        foreach ($world->query(RigidBody3D::class, BoxCollider3D::class, Transform3D::class) as $entity) {
            $rigid = $entity->get(RigidBody3D::class);
            if ($rigid->bodyType === BodyType::Static) {
                continue; // Static bodies handled separately
            }

            $transform = $entity->get(Transform3D::class);
            $collider = $entity->get(BoxCollider3D::class);
            $aabb = $collider->getWorldAABB($transform->getWorldMatrix());

            $bodies[$entity->id] = [
                'entityId' => $entity->id,
                'rigid' => $rigid,
                'transform' => $transform,
                'collider' => $collider,
                'min' => $aabb['min'],
                'max' => $aabb['max'],
                'posX' => $transform->position->x,
                'posY' => $transform->position->y,
                'posZ' => $transform->position->z,
            ];
        }
        return $bodies;
    }

    /** @param array<int, array{entityId: int, rigid: RigidBody3D, transform: Transform3D, collider: BoxCollider3D, min: Vec3, max: Vec3, posX: float, posY: float, posZ: float}> &$bodies */
    private function computeKinematicVelocities(array &$bodies, float $dt): void
    {
        $invDt = 1.0 / $dt;
        foreach ($bodies as &$body) {
            $rigid = $body['rigid'];
            if ($rigid->bodyType !== BodyType::Kinematic) {
                continue;
            }
            if ($rigid->previousPosition !== null) {
                $pos = $body['transform']->position;
                $prev = $rigid->previousPosition;
                $rigid->velocity = new Vec3(
                    ($pos->x - $prev->x) * $invDt,
                    ($pos->y - $prev->y) * $invDt,
                    ($pos->z - $prev->z) * $invDt,
                );
            }
            $rigid->previousPosition = clone $body['transform']->position;
        }
    }

    /** @param array<int, array{entityId: int, rigid: RigidBody3D, transform: Transform3D, collider: BoxCollider3D, min: Vec3, max: Vec3, posX: float, posY: float, posZ: float}> &$bodies */
    private function integrate(array &$bodies, float $dt): void
    {
        $gx = $this->gravity->x;
        $gy = $this->gravity->y;
        $gz = $this->gravity->z;

        foreach ($bodies as &$body) {
            $rigid = $body['rigid'];
            if ($rigid->bodyType !== BodyType::Dynamic || $rigid->isSleeping) {
                continue;
            }

            // Semi-implicit Euler: update velocity first, then position
            $gs = $rigid->gravityScale;
            $vx = $rigid->velocity->x + $gx * $gs * $dt;
            $vy = $rigid->velocity->y + $gy * $gs * $dt;
            $vz = $rigid->velocity->z + $gz * $gs * $dt;

            // Linear damping
            $damp = max(0.0, 1.0 - $rigid->linearDamping * $dt);
            $vx *= $damp;
            $vy *= $damp;
            $vz *= $damp;

            $rigid->velocity = new Vec3($vx, $vy, $vz);

            // Update position
            $body['posX'] += $vx * $dt;
            $body['posY'] += $vy * $dt;
            $body['posZ'] += $vz * $dt;
        }
    }

    /**
     * @param array<int, array{entityId: int, rigid: RigidBody3D, transform: Transform3D, collider: BoxCollider3D, min: Vec3, max: Vec3, posX: float, posY: float, posZ: float}> &$bodies
     * @return list<Collision3D>
     */
    private function detectAndSolveBodyCollisions(array &$bodies): array
    {
        // Broadphase: insert into spatial hash
        $this->spatialHash->clear();
        foreach ($bodies as $id => &$body) {
            $rigid = $body['rigid'];
            if ($rigid->bodyType === BodyType::Dynamic && $rigid->isSleeping) {
                continue;
            }
            $this->updateAABB($body);
            $this->spatialHash->insert($id, $body['min'], $body['max']);
        }

        $pairs = $this->spatialHash->queryPairs();
        $collisions = [];

        // Solver iterations
        for ($iter = 0; $iter < $this->solverIterations; $iter++) {
            foreach ($pairs as [$idA, $idB]) {
                if (!isset($bodies[$idA], $bodies[$idB])) continue;

                $a = &$bodies[$idA];
                $b = &$bodies[$idB];
                $rigidA = $a['rigid'];
                $rigidB = $b['rigid'];

                // At least one must be dynamic
                if ($rigidA->bodyType !== BodyType::Dynamic && $rigidB->bodyType !== BodyType::Dynamic) {
                    continue;
                }

                // AABB overlap test
                $this->updateAABB($a);
                $this->updateAABB($b);
                $collision = $this->testAABBOverlap($a, $b, $idA, $idB);
                if ($collision === null) continue;

                if ($iter === 0) {
                    $collisions[] = $collision;
                    // Wake sleeping bodies on collision
                    $rigidA->wake();
                    $rigidB->wake();
                }

                // Impulse resolution
                $this->resolveImpulse($a, $b, $collision);
            }
        }

        return $collisions;
    }

    /** @param array<int, array{entityId: int, rigid: RigidBody3D, transform: Transform3D, collider: BoxCollider3D, min: Vec3, max: Vec3, posX: float, posY: float, posZ: float}> &$bodies */
    private function resolveStaticCollisions(World $world, array &$bodies): void
    {
        // Collect static colliders each frame (no RigidBody3D, just BoxCollider3D)
        $staticAABBs = [];
        foreach ($world->query(BoxCollider3D::class, Transform3D::class) as $entity) {
            if ($world->tryGetComponent($entity->id, RigidBody3D::class) !== null) {
                continue; // Skip bodies handled above
            }
            $collider = $entity->get(BoxCollider3D::class);
            if ($collider->isTrigger) continue;

            $transform = $entity->get(Transform3D::class);
            $aabb = $collider->getWorldAABB($transform->getWorldMatrix());
            $staticAABBs[$entity->id] = $aabb;
        }

        foreach ($bodies as &$body) {
            $rigid = $body['rigid'];
            if ($rigid->bodyType !== BodyType::Dynamic || $rigid->isSleeping) {
                continue;
            }

            $this->updateAABB($body);

            foreach ($staticAABBs as $staticAABB) {
                $overlapX = min($body['max']->x, $staticAABB['max']->x) - max($body['min']->x, $staticAABB['min']->x);
                $overlapY = min($body['max']->y, $staticAABB['max']->y) - max($body['min']->y, $staticAABB['min']->y);
                $overlapZ = min($body['max']->z, $staticAABB['max']->z) - max($body['min']->z, $staticAABB['min']->z);

                if ($overlapX <= 0 || $overlapY <= 0 || $overlapZ <= 0) continue;

                // Push out along minimum penetration axis
                $cx = ($body['min']->x + $body['max']->x) * 0.5;
                $cy = ($body['min']->y + $body['max']->y) * 0.5;
                $cz = ($body['min']->z + $body['max']->z) * 0.5;
                $scx = ($staticAABB['min']->x + $staticAABB['max']->x) * 0.5;
                $scy = ($staticAABB['min']->y + $staticAABB['max']->y) * 0.5;
                $scz = ($staticAABB['min']->z + $staticAABB['max']->z) * 0.5;

                if ($overlapX <= $overlapY && $overlapX <= $overlapZ) {
                    $sign = $cx < $scx ? -1.0 : 1.0;
                    $body['posX'] += $sign * $overlapX;
                    $vx = $rigid->velocity->x;
                    if ($sign * $vx < 0) {
                        $rigid->velocity = new Vec3(-$vx * $rigid->restitution, $rigid->velocity->y, $rigid->velocity->z);
                    }
                } elseif ($overlapY <= $overlapX && $overlapY <= $overlapZ) {
                    $sign = $cy < $scy ? -1.0 : 1.0;
                    $body['posY'] += $sign * $overlapY;
                    $vy = $rigid->velocity->y;
                    if ($sign * $vy < 0) {
                        $rigid->velocity = new Vec3($rigid->velocity->x, -$vy * $rigid->restitution, $rigid->velocity->z);
                        if ($sign > 0) {
                            // Landing on top — apply friction to horizontal velocity
                            $fric = max(0.0, 1.0 - $rigid->friction);
                            $rigid->velocity = new Vec3($rigid->velocity->x * $fric, $rigid->velocity->y, $rigid->velocity->z * $fric);
                        }
                    }
                } else {
                    $sign = $cz < $scz ? -1.0 : 1.0;
                    $body['posZ'] += $sign * $overlapZ;
                    $vz = $rigid->velocity->z;
                    if ($sign * $vz < 0) {
                        $rigid->velocity = new Vec3($rigid->velocity->x, $rigid->velocity->y, -$vz * $rigid->restitution);
                    }
                }

                $this->updateAABB($body);
            }
        }
    }

    /** @param array<int, array{entityId: int, rigid: RigidBody3D, transform: Transform3D, collider: BoxCollider3D, min: Vec3, max: Vec3, posX: float, posY: float, posZ: float}> &$bodies */
    private function characterPush(World $world, array &$bodies, float $dt): void
    {
        foreach ($world->query(CharacterController3D::class, Transform3D::class) as $charEntity) {
            $controller = $charEntity->get(CharacterController3D::class);
            $charTransform = $charEntity->get(Transform3D::class);
            $charPos = $charTransform->position;
            $radius = $controller->radius;
            $halfH = $controller->height * 0.5;

            $charMin = new Vec3($charPos->x - $radius, $charPos->y - $halfH, $charPos->z - $radius);
            $charMax = new Vec3($charPos->x + $radius, $charPos->y + $halfH, $charPos->z + $radius);

            foreach ($bodies as &$body) {
                $rigid = $body['rigid'];
                if ($rigid->bodyType !== BodyType::Dynamic) continue;

                $this->updateAABB($body);

                // AABB overlap test
                if ($charMax->x <= $body['min']->x || $charMin->x >= $body['max']->x) continue;
                if ($charMax->y <= $body['min']->y || $charMin->y >= $body['max']->y) continue;
                if ($charMax->z <= $body['min']->z || $charMin->z >= $body['max']->z) continue;

                // Push direction: from character center to body center (horizontal)
                $dx = $body['posX'] - $charPos->x;
                $dz = $body['posZ'] - $charPos->z;
                $dist = sqrt($dx * $dx + $dz * $dz);

                if ($dist < 0.001) {
                    $dx = 1.0; $dz = 0.0; $dist = 1.0;
                }

                $nx = $dx / $dist;
                $nz = $dz / $dist;

                // Use character velocity if available, otherwise overlap normal
                $charVel = $controller->velocity;
                $charSpeed = sqrt($charVel->x * $charVel->x + $charVel->z * $charVel->z);

                $pushMag = $this->characterPushForce * $dt / $rigid->mass;
                if ($charSpeed > 0.1) {
                    // Push in character's movement direction projected onto push normal
                    $dot = ($charVel->x * $nx + $charVel->z * $nz);
                    if ($dot > 0) {
                        $pushMag *= $dot / $charSpeed;
                    } else {
                        continue; // Character moving away from body
                    }
                }

                $rigid->velocity = new Vec3(
                    $rigid->velocity->x + $nx * $pushMag,
                    $rigid->velocity->y,
                    $rigid->velocity->z + $nz * $pushMag,
                );
                $rigid->wake();
            }
        }
    }

    /** @param array<int, array{entityId: int, rigid: RigidBody3D, transform: Transform3D, collider: BoxCollider3D, min: Vec3, max: Vec3, posX: float, posY: float, posZ: float}> &$bodies */
    private function writeBack(array &$bodies): void
    {
        foreach ($bodies as &$body) {
            $rigid = $body['rigid'];
            if ($rigid->bodyType === BodyType::Kinematic) {
                continue; // Kinematic positions are managed by other systems
            }

            $transform = $body['transform'];
            $transform->position = new Vec3($body['posX'], $body['posY'], $body['posZ']);
            $transform->worldMatrix = $transform->getLocalMatrix();
        }
    }

    // --- Helpers ---

    /** @param array{entityId: int, rigid: RigidBody3D, transform: Transform3D, collider: BoxCollider3D, min: Vec3, max: Vec3, posX: float, posY: float, posZ: float} &$body */
    private function updateAABB(array &$body): void
    {
        $aabb = $body['collider']->getWorldAABB(
            \PHPolygon\Math\Mat4::trs(
                new Vec3($body['posX'], $body['posY'], $body['posZ']),
                $body['transform']->rotation,
                $body['transform']->scale,
            )
        );
        $body['min'] = $aabb['min'];
        $body['max'] = $aabb['max'];
    }

    /**
     * @param array{entityId: int, rigid: RigidBody3D, transform: Transform3D, collider: BoxCollider3D, min: Vec3, max: Vec3, posX: float, posY: float, posZ: float} &$a
     * @param array{entityId: int, rigid: RigidBody3D, transform: Transform3D, collider: BoxCollider3D, min: Vec3, max: Vec3, posX: float, posY: float, posZ: float} &$b
     */
    private function testAABBOverlap(array &$a, array &$b, int $idA, int $idB): ?Collision3D
    {
        $overlapX = min($a['max']->x, $b['max']->x) - max($a['min']->x, $b['min']->x);
        $overlapY = min($a['max']->y, $b['max']->y) - max($a['min']->y, $b['min']->y);
        $overlapZ = min($a['max']->z, $b['max']->z) - max($a['min']->z, $b['min']->z);

        if ($overlapX <= 0 || $overlapY <= 0 || $overlapZ <= 0) return null;

        $cx = ($a['posX'] + $b['posX']) * 0.5;
        $cy = ($a['posY'] + $b['posY']) * 0.5;
        $cz = ($a['posZ'] + $b['posZ']) * 0.5;

        // Normal = axis of minimum penetration, from A to B
        if ($overlapX <= $overlapY && $overlapX <= $overlapZ) {
            $sign = $a['posX'] < $b['posX'] ? 1.0 : -1.0;
            return new Collision3D($idA, $idB, new Vec3($sign, 0, 0), $overlapX, new Vec3($cx, $cy, $cz));
        }
        if ($overlapY <= $overlapX && $overlapY <= $overlapZ) {
            $sign = $a['posY'] < $b['posY'] ? 1.0 : -1.0;
            return new Collision3D($idA, $idB, new Vec3(0, $sign, 0), $overlapY, new Vec3($cx, $cy, $cz));
        }
        $sign = $a['posZ'] < $b['posZ'] ? 1.0 : -1.0;
        return new Collision3D($idA, $idB, new Vec3(0, 0, $sign), $overlapZ, new Vec3($cx, $cy, $cz));
    }

    /**
     * @param array{entityId: int, rigid: RigidBody3D, transform: Transform3D, collider: BoxCollider3D, min: Vec3, max: Vec3, posX: float, posY: float, posZ: float} &$a
     * @param array{entityId: int, rigid: RigidBody3D, transform: Transform3D, collider: BoxCollider3D, min: Vec3, max: Vec3, posX: float, posY: float, posZ: float} &$b
     */
    private function resolveImpulse(array &$a, array &$b, Collision3D $collision): void
    {
        $rigidA = $a['rigid'];
        $rigidB = $b['rigid'];
        $invMassA = $rigidA->getInverseMass();
        $invMassB = $rigidB->getInverseMass();
        $invMassSum = $invMassA + $invMassB;

        if ($invMassSum < 1e-8) return; // Both infinite mass

        $nx = $collision->normal->x;
        $ny = $collision->normal->y;
        $nz = $collision->normal->z;

        // Relative velocity
        $rvx = $rigidA->velocity->x - $rigidB->velocity->x;
        $rvy = $rigidA->velocity->y - $rigidB->velocity->y;
        $rvz = $rigidA->velocity->z - $rigidB->velocity->z;

        // Normal component of relative velocity
        $vn = $rvx * $nx + $rvy * $ny + $rvz * $nz;

        // Already separating?
        if ($vn > 0) return;

        // Restitution (bounciness)
        $e = min($rigidA->restitution, $rigidB->restitution);

        // Normal impulse magnitude
        $j = -(1.0 + $e) * $vn / $invMassSum;

        // Apply normal impulse
        $jnx = $nx * $j;
        $jny = $ny * $j;
        $jnz = $nz * $j;

        $rigidA->velocity = new Vec3(
            $rigidA->velocity->x + $jnx * $invMassA,
            $rigidA->velocity->y + $jny * $invMassA,
            $rigidA->velocity->z + $jnz * $invMassA,
        );
        $rigidB->velocity = new Vec3(
            $rigidB->velocity->x - $jnx * $invMassB,
            $rigidB->velocity->y - $jny * $invMassB,
            $rigidB->velocity->z - $jnz * $invMassB,
        );

        // Friction impulse (tangential)
        $friction = ($rigidA->friction + $rigidB->friction) * 0.5;
        $tvx = $rvx - $nx * $vn;
        $tvy = $rvy - $ny * $vn;
        $tvz = $rvz - $nz * $vn;
        $tvLen = sqrt($tvx * $tvx + $tvy * $tvy + $tvz * $tvz);

        if ($tvLen > 1e-6) {
            $tvx /= $tvLen; $tvy /= $tvLen; $tvz /= $tvLen;
            $jt = -$tvLen / $invMassSum;

            // Coulomb friction clamp
            if (abs($jt) > abs($j) * $friction) {
                $jt = -$j * $friction * ($jt > 0 ? 1.0 : -1.0);
            }

            $rigidA->velocity = new Vec3(
                $rigidA->velocity->x + $tvx * $jt * $invMassA,
                $rigidA->velocity->y + $tvy * $jt * $invMassA,
                $rigidA->velocity->z + $tvz * $jt * $invMassA,
            );
            $rigidB->velocity = new Vec3(
                $rigidB->velocity->x - $tvx * $jt * $invMassB,
                $rigidB->velocity->y - $tvy * $jt * $invMassB,
                $rigidB->velocity->z - $tvz * $jt * $invMassB,
            );
        }

        // Position correction (Baumgarte stabilization)
        $slop = 0.01;
        $percent = 0.2;
        $correction = max($collision->penetration - $slop, 0.0) * $percent / $invMassSum;

        $a['posX'] += $nx * $correction * $invMassA;
        $a['posY'] += $ny * $correction * $invMassA;
        $a['posZ'] += $nz * $correction * $invMassA;
        $b['posX'] -= $nx * $correction * $invMassB;
        $b['posY'] -= $ny * $correction * $invMassB;
        $b['posZ'] -= $nz * $correction * $invMassB;
    }

}
