<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\HeightmapCollider3D;
use PHPolygon\Component\MeshCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\BoxColliderIndex;
use PHPolygon\Physics\BVH;
use PHPolygon\Physics\CollisionMath;
use PHPolygon\Physics\OrientedBox;
use PHPolygon\Physics\StepAssist;
use PHPolygon\Physics\Triangle;
use PHPolygon\Runtime\PerfProfiler;

class Physics3DSystem extends AbstractSystem
{
    private Vec3 $gravity;
    private float $groundPlaneY;

    /** Maximum mesh-collider resolution iterations per character per frame */
    private const MESH_ITERATIONS = 4;

    /**
     * Longest single integration step (seconds). A tick longer than this is
     * split into equal substeps of at most this length, so gravity, friction and
     * the discrete overlap tests see the same step size on a slow machine as on
     * a fast one. Ticks at or below it (any frame rate ≥ 30 Hz) run exactly one
     * step, unchanged.
     */
    public const float MAX_SUBSTEP = 1.0 / 30.0;

    /**
     * Upper bound on substeps per tick. A tick longer than
     * MAX_SUBSTEPS × MAX_SUBSTEP (a multi-second stall: loading, a debugger
     * pause, a suspended window) simulates only that much — the rest of the
     * stall is dropped instead of flinging characters, and the physics cost of
     * one hitch stays bounded. Systems that are not physics keep the full dt.
     */
    public const int MAX_SUBSTEPS = 8;

    /**
     * How far below its previous feet a support surface may lie and still count
     * as "was standing on / above it" for the swept landing test. Absorbs the
     * resting contact and float noise; small enough that a character walking
     * under a low ledge is never pulled onto it.
     */
    private const float SWEEP_SKIN = 0.05;

    /** Surfaces steeper than this (|normal.y| below it) are walls, not floors. */
    private const float SWEEP_MIN_NORMAL_Y = 0.5;

    /**
     * A contact pushing up at least this much holds a capsule that is not grounded:
     * between two nosings of a narrow stair each edge pushes too steeply to count as
     * floor, yet the capsule neither falls nor walks. Such a character may step.
     */
    private const float MIN_WEDGE_NORMAL_Y = 0.1;

    /**
     * Characters held that way in this tick, by entity id.
     *
     * @var array<int, true>
     */
    private array $wedged = [];

    /**
     * How far (m) a character may be pushed from where its box candidates were queried before the query
     * is repeated from the new spot. See resolveCharacters().
     */
    private const float QUERY_REACH = 2.0;

    /** Slack (m) on the XZ rectangles of the oriented-box queries: the exact tests decide, the query only has to be a superset. */
    private const float QUERY_SLACK = 0.01;

    /** Persistent box-collider index: per-tick cost is the colliders that changed, not all of them. */
    private BoxColliderIndex $colliders;

    /**
     * @param bool $broadphase false makes every character test every box, in the same order: the behaviour of
     *                         before the collider index, kept for comparing results and for ruling the grid out.
     */
    public function __construct(?Vec3 $gravity = null, float $groundPlaneY = 0.0, bool $broadphase = true)
    {
        $this->gravity = $gravity ?? new Vec3(0.0, -9.81, 0.0);
        $this->groundPlaneY = $groundPlaneY;
        $this->colliders = new BoxColliderIndex($broadphase);
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
        PerfProfiler::begin('physics.tick');
        try {
            $this->step($world, $dt);
        } finally {
            PerfProfiler::end();
        }
    }

    private function step(World $world, float $dt): void
    {
        // Box colliders live in a persistent index that only re-reads what changed since the last tick;
        // characters ask it for the boxes near them. Mesh colliders are still collected per tick.
        PerfProfiler::begin('physics.collect');
        try {
            $this->colliders->sync($world);
            $meshColliders = $this->collectMeshColliders($world);
        } finally {
            PerfProfiler::end();
        }

        $assist = new StepAssist($this->colliders, $meshColliders);
        $walkers = $this->walkers($world, $dt);
        $this->wedged = [];

        // Colliders do not move between substeps (nothing else runs in between),
        // so they are collected once and only the character integration repeats.
        PerfProfiler::begin('physics.resolve');
        try {
            [$steps, $stepDt] = self::substeps($dt);
            for ($i = 0; $i < $steps; $i++) {
                $this->resolveCharacters($world, $stepDt, $meshColliders, $assist);
            }
            foreach ($walkers as $walker) {
                $controller = $walker['controller'];
                $wedged = isset($this->wedged[$walker['entityId']]) && $controller->velocity->y <= 0.0;
                if (($controller->isGrounded || $wedged) && $controller->isPhysicsActive()) {
                    $assist->climb($controller, $walker['transform'], $walker['from'], $walker['x'], $walker['z'], $walker['entityId']);
                    $controller->settledPosition = $walker['transform']->position;
                }
            }
        } finally {
            PerfProfiler::end();
        }
    }

    /**
     * Characters that want to walk this tick: what game code added to the position
     * since the last tick, plus what their velocity will add. Standing ones and
     * warps stay out, so a crowd at rest costs one comparison each.
     *
     * @return list<array{entityId: int, controller: CharacterController3D, transform: Transform3D, from: Vec3, x: float, z: float}>
     */
    private function walkers(World $world, float $dt): array
    {
        $walkers = [];
        $minSq = (StepAssist::MIN_INTENT_SPEED * $dt) ** 2;
        $maxSq = StepAssist::MAX_REACH ** 2;

        /** @var array<int, CharacterController3D> $pool */
        $pool = $world->componentPool(CharacterController3D::class);
        foreach ($pool as $entityId => $controller) {
            $from = $controller->settledPosition;
            if ($from === null || $controller->stepHeight < StepAssist::MIN_RISE) {
                continue;
            }
            $transform = $world->tryGetComponent($entityId, Transform3D::class);
            if (!$transform instanceof Transform3D) {
                continue;
            }
            $x = $transform->position->x - $from->x + $controller->velocity->x * $dt;
            $z = $transform->position->z - $from->z + $controller->velocity->z * $dt;
            $lengthSq = $x * $x + $z * $z;

            $debt = $controller->stepDebt;
            if ($debt !== null) {
                $debtLength = $debt->length();
                $along = $lengthSq >= $minSq ? ($x * $debt->x + $z * $debt->z) / $debtLength : 0.0;
                if ($along <= 0.0) {
                    $controller->stepDebt = null;
                } else {
                    // Paid out of this tick's walk, along the direction of the lift.
                    $paid = min($along, $debtLength);
                    $back = $debt->mul($paid / $debtLength);
                    $transform->position = $transform->position->sub($back);
                    $x -= $back->x;
                    $z -= $back->z;
                    $lengthSq = $x * $x + $z * $z;
                    $controller->stepDebt = $paid < $debtLength ? $debt->sub($back) : null;
                }
            }

            if ($lengthSq > 0.0 && $lengthSq >= $minSq && $lengthSq <= $maxSq) {
                $walkers[] = ['entityId' => $entityId, 'controller' => $controller, 'transform' => $transform, 'from' => $from, 'x' => $x, 'z' => $z];
            }
        }

        return $walkers;
    }

    /**
     * Split one tick into equal integration steps of at most MAX_SUBSTEP,
     * capped at MAX_SUBSTEPS (the remainder of an overlong tick is dropped).
     * A non-positive dt still runs one step so state-only work (coupling,
     * scripted animation) keeps being applied.
     *
     * @return array{int, float} [step count, dt per step]
     */
    public static function substeps(float $dt): array
    {
        if ($dt <= self::MAX_SUBSTEP) {
            return [1, $dt];
        }
        // The epsilon keeps an exact multiple (e.g. 2 × MAX_SUBSTEP) from
        // rounding up to an extra step.
        $steps = (int) ceil($dt / self::MAX_SUBSTEP - 1e-9);
        if ($steps > self::MAX_SUBSTEPS) {
            return [self::MAX_SUBSTEPS, self::MAX_SUBSTEP];
        }
        return [$steps, $dt / $steps];
    }

    /**
     * @param list<array{entityId: int, bvh: BVH}> $meshColliders
     */
    private function resolveCharacters(World $world, float $dt, array $meshColliders, StepAssist $assist): void
    {
        foreach ($world->query(CharacterController3D::class, Transform3D::class) as $entity) {
            $controller = $entity->get(CharacterController3D::class);
            $transform = $entity->get(Transform3D::class);

            // Handle non-physics states (coupling, animation)
            if (!$controller->isPhysicsActive()) {
                $this->updateNonPhysicsState($world, $controller, $transform, $dt);
                $controller->settledPosition = null;
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
            $wasGrounded = $controller->isGrounded;
            $controller->isGrounded = false;
            $controller->groundEntityId = null;

            // Swept landing: the overlap tests below only see where the capsule
            // ENDS. A step that carries the feet past a floor thinner than the
            // drop (a long tick, a fast fall) would end beneath or deep inside
            // it and be resolved the wrong way — through it. Catch any support
            // surface crossed on the way down and stand the character on it.
            //
            // A character that was grounded and is not rising also probes
            // SWEEP_SKIN below its feet. Resting exactly on a box top is no
            // overlap, so without the probe it read as airborne every other
            // tick (gravity → sink → push back up → grounded → no gravity → …):
            // isGrounded flickered and a jump pressed on the wrong tick was lost.
            $probe = $wasGrounded && $controller->velocity->y <= 0.0;
            if ($newPos->y < $transform->position->y || $probe) {
                $prevFeetY = $transform->position->y - $halfHeight;
                $support = $this->sweptSupportY(
                    $newPos,
                    $prevFeetY,
                    $newPos->y - $halfHeight - ($probe ? self::SWEEP_SKIN : 0.0),
                    $radius,
                    $entity->id,
                    $meshColliders,
                    $supportEntityId,
                );
                if ($support !== null) {
                    $newPos = new Vec3($newPos->x, $support + $halfHeight, $newPos->z);
                    $controller->velocity = new Vec3($controller->velocity->x, 0.0, $controller->velocity->z);
                    $controller->isGrounded = true;
                    $controller->groundEntityId = $supportEntityId;
                    $charMin = new Vec3($newPos->x - $radius, $newPos->y - $halfHeight, $newPos->z - $radius);
                    $charMax = new Vec3($newPos->x + $radius, $newPos->y + $halfHeight, $newPos->z + $radius);
                }
            }

            // Resolve AABB collisions against box colliders with step-climbing. Only the boxes near the
            // capsule are candidates; they come in pool order, as the full list did, because every push
            // moves the capsule for the next box. A push can carry the capsule further than the query
            // reached (the whole way out of a thick box), so once it has moved more than QUERY_REACH
            // from where the candidates were asked for, they are asked for again from the new spot,
            // continuing after the box just handled: the result is that of testing every box.
            $queryX = $newPos->x;
            $queryZ = $newPos->z;
            $reach = $radius + self::QUERY_REACH;
            $candidates = $this->colliders->boxesIn($queryX - $reach, $queryZ - $reach, $queryX + $reach, $queryZ + $reach);
            $candidateCount = count($candidates);
            for ($ci = 0; $ci < $candidateCount; $ci++) {
                $collider = $candidates[$ci];
                if ($collider['entityId'] === $entity->id) {
                    continue;
                }

                $resolution = self::resolveAABB($charMin, $charMax, $collider['min'], $collider['max']);
                if ($resolution === null) {
                    continue;
                }

                // A beam at head height over a character on the ground: down is no
                // way out, the floor is there. It stops at the beam's edge instead.
                if ($resolution->y < 0.0 && $controller->isGrounded) {
                    $resolution = self::outOfBeamSideways($charMin, $charMax, $collider['min'], $collider['max'], $radius) ?? $resolution;
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
                    && $assist->bodyFitsAt($newPos->x, $newPos->z, $colliderTopY, $controller, $entity->id, $collider['entityId'], $resolution)
                ) {
                    // Lift character onto the step
                    $newPos = new Vec3($newPos->x, $colliderTopY + $halfHeight, $newPos->z);
                    $controller->velocity = new Vec3($controller->velocity->x, 0.0, $controller->velocity->z);
                    $controller->isGrounded = true;
                    $controller->groundEntityId = $collider['entityId'];
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
                            $controller->groundEntityId = $collider['entityId'];
                        }
                    }
                    if (abs($resolution->z) > 0.0001) {
                        $controller->velocity = new Vec3($controller->velocity->x, $controller->velocity->y, 0.0);
                    }
                }

                // Update AABB for next collision test
                $charMin = new Vec3($newPos->x - $radius, $newPos->y - $halfHeight, $newPos->z - $radius);
                $charMax = new Vec3($newPos->x + $radius, $newPos->y + $halfHeight, $newPos->z + $radius);

                if (abs($newPos->x - $queryX) > self::QUERY_REACH || abs($newPos->z - $queryZ) > self::QUERY_REACH) {
                    $queryX = $newPos->x;
                    $queryZ = $newPos->z;
                    $seq = $collider['seq'];
                    $candidates = [];
                    foreach ($this->colliders->boxesIn($queryX - $reach, $queryZ - $reach, $queryX + $reach, $queryZ + $reach) as $next) {
                        if ($next['seq'] > $seq) {
                            $candidates[] = $next;
                        }
                    }
                    $candidateCount = count($candidates);
                    $ci = -1;
                }
            }

            // Resolve mesh collider collisions (BVH-accelerated triangle tests)
            // Also pass box collider data for step-climbing on rotated boxes.
            if ($meshColliders !== [] || $this->colliders->hasRotated()) {
                $newPos = $this->resolveMeshCollisions(
                    $newPos,
                    $controller,
                    $halfHeight,
                    $radius,
                    $meshColliders,
                    $entity->id,
                    $assist,
                );

                // Final AABB update after mesh collision resolution
                $charMin = new Vec3($newPos->x - $radius, $newPos->y - $halfHeight, $newPos->z - $radius);
                $charMax = new Vec3($newPos->x + $radius, $newPos->y + $halfHeight, $newPos->z + $radius);
            }

            // Heightmap terrain collision — O(1) bilinear query per heightmap
            foreach ($world->query(HeightmapCollider3D::class) as $hmEntity) {
                $hm = $hmEntity->get(HeightmapCollider3D::class);
                if (!$hm->isPopulated()) {
                    continue;
                }
                // A heightmap only grounds the player WITHIN its declared world
                // bounds. getHeightAt() clamps out-of-range queries to the edge
                // value, so without this a small map would "extend" its rim height
                // under everything far away — e.g. a coastal island's heightmap
                // clamping a diver/elevator in a distant deep basin up to its
                // ocean-rim height. Skip heightmaps that don't cover this XZ.
                if ($newPos->x < $hm->worldMinX || $newPos->x > $hm->worldMaxX
                    || $newPos->z < $hm->worldMinZ || $newPos->z > $hm->worldMaxZ
                ) {
                    continue;
                }
                $terrainY = $hm->getHeightAt($newPos->x, $newPos->z);
                $feetY = $newPos->y - $halfHeight;
                if ($feetY < $terrainY) {
                    $newPos = new Vec3($newPos->x, $terrainY + $halfHeight, $newPos->z);
                    if ($controller->velocity->y < 0.0) {
                        $controller->velocity = new Vec3($controller->velocity->x, 0.0, $controller->velocity->z);
                    }
                    $controller->isGrounded = true;
                    $controller->groundEntityId = $hmEntity->id;
                }
            }

            // Safety net: prevent falling through the world. The plane is no
            // entity and groundEntityId stays as it is: a floor level with the
            // plane puts the feet on both, and the floor is what they stand on.
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
            $controller->settledPosition = $newPos;
        }
    }

    /**
     * Highest floor surface the character's feet crossed this step, or null.
     *
     * A surface counts when it lies between $lowerY (the new feet height, or
     * SWEEP_SKIN below it for a grounded probe) and the previous feet height
     * (plus {@see SWEEP_SKIN} for the resting contact) under the
     * character: box colliders whose footprint overlaps the capsule's, and
     * near-horizontal mesh triangles under the capsule's centre column. Winding
     * is ignored (|normal.y|) — a walker coming down onto a horizontal face is
     * landing on it whichever way the face was authored.
     *
     * @param list<array{entityId: int, bvh: BVH}> $meshColliders
     * @param-out int|null $supportEntityId owner of the returned surface
     */
    private function sweptSupportY(
        Vec3 $pos,
        float $prevFeetY,
        float $lowerY,
        float $radius,
        int $selfId,
        array $meshColliders,
        ?int &$supportEntityId,
    ): ?float {
        $upper = $prevFeetY + self::SWEEP_SKIN;
        $best = null;
        $supportEntityId = null;

        foreach ($this->colliders->boxesIn($pos->x - $radius, $pos->z - $radius, $pos->x + $radius, $pos->z + $radius) as $collider) {
            if ($collider['entityId'] === $selfId) {
                continue;
            }
            $top = $collider['max']->y;
            if ($top > $upper || $top < $lowerY || ($best !== null && $top <= $best)) {
                continue;
            }
            if ($pos->x + $radius <= $collider['min']->x || $pos->x - $radius >= $collider['max']->x
                || $pos->z + $radius <= $collider['min']->z || $pos->z - $radius >= $collider['max']->z
            ) {
                continue;
            }
            $best = $top;
            $supportEntityId = $collider['entityId'];
        }

        foreach ($this->colliders->rotatedIn($pos->x, $pos->z, $pos->x, $pos->z) as $rotated) {
            $box = $rotated['box'];
            if ($rotated['entityId'] === $selfId
                || $pos->x < $box->min->x || $pos->x > $box->max->x
                || $pos->z < $box->min->z || $pos->z > $box->max->z
                || $lowerY > $box->max->y || $upper < $box->min->y
            ) {
                continue;
            }
            $y = $box->surfaceAt($pos->x, $pos->z);
            if ($y !== null && $y <= $upper && $y >= $lowerY && ($best === null || $y > $best)) {
                $best = $y;
                $supportEntityId = $rotated['entityId'];
            }
        }

        if ($meshColliders !== []) {
            $queryMin = new Vec3($pos->x - $radius, $lowerY, $pos->z - $radius);
            $queryMax = new Vec3($pos->x + $radius, $upper, $pos->z + $radius);
            foreach ($meshColliders as $mc) {
                if ($mc['entityId'] === $selfId) {
                    continue;
                }
                foreach ($mc['bvh']->query($queryMin, $queryMax) as $triangle) {
                    if (abs($triangle->normal->y) < self::SWEEP_MIN_NORMAL_Y) {
                        continue;
                    }
                    $y = self::triangleHeightAt($triangle, $pos->x, $pos->z);
                    if ($y === null || $y > $upper || $y < $lowerY || ($best !== null && $y <= $best)) {
                        continue;
                    }
                    $best = $y;
                    $supportEntityId = $mc['entityId'];
                }
            }
        }

        return $best;
    }

    /**
     * Height of a triangle's plane over (x, z), or null when the point lies
     * outside the triangle's XZ footprint.
     */
    private static function triangleHeightAt(Triangle $t, float $x, float $z): ?float
    {
        $a = $t->v0;
        $b = $t->v1;
        $c = $t->v2;
        $den = ($b->z - $c->z) * ($a->x - $c->x) + ($c->x - $b->x) * ($a->z - $c->z);
        if (abs($den) < 1e-12) {
            return null;
        }
        $w1 = (($b->z - $c->z) * ($x - $c->x) + ($c->x - $b->x) * ($z - $c->z)) / $den;
        $w2 = (($c->z - $a->z) * ($x - $c->x) + ($a->x - $c->x) * ($z - $c->z)) / $den;
        $w3 = 1.0 - $w1 - $w2;
        $eps = 1e-6;
        if ($w1 < -$eps || $w2 < -$eps || $w3 < -$eps) {
            return null;
        }
        return $w1 * $a->y + $w2 * $b->y + $w3 * $c->y;
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
     * Collect all MeshCollider3D components, lazily building BVH as needed.
     * Returns world-space BVH data for each mesh collider entity.
     *
     * @return list<array{entityId: int, bvh: BVH}>
     */
    private function collectMeshColliders(World $world): array
    {
        $colliders = [];

        /** @var array<int, MeshCollider3D> $pool */
        $pool = $world->componentPool(MeshCollider3D::class);
        foreach ($pool as $entityId => $meshCollider) {
            if ($meshCollider->isTrigger) {
                continue;
            }

            $transform = $world->tryGetComponent($entityId, Transform3D::class);
            if (!$transform instanceof Transform3D) {
                continue;
            }
            $worldMatrix = $transform->getWorldMatrix();
            if ($worldMatrix === $meshCollider->lastWorldMatrix && $meshCollider->bvh !== null) {
                $colliders[] = ['entityId' => $entityId, 'bvh' => $meshCollider->bvh];
                continue; // unchanged transform: see collectBoxColliders()
            }
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
            $meshCollider->lastWorldMatrix = $worldMatrix;

            $colliders[] = [
                'entityId' => $entityId,
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
     */
    private function resolveMeshCollisions(
        Vec3 $pos,
        CharacterController3D $controller,
        float $halfHeight,
        float $radius,
        array $meshColliders,
        int $characterEntityId,
        StepAssist $assist,
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

            /** @var list<Triangle|OrientedBox> $shapes */
            $shapes = [];
            /** @var list<int> $owners */
            $owners = [];
            /** @var list<Vec3> $pushes */
            $pushes = [];
            /** @var array<int, float> $depths */
            $depths = [];
            foreach ($meshColliders as $mc) {
                if ($mc['entityId'] === $characterEntityId) {
                    continue;
                }

                /** @var BVH $bvh */
                $bvh = $mc['bvh'];
                foreach ($bvh->query($queryMin, $queryMax) as $triangle) {
                    $resolution = CollisionMath::capsuleVsTriangle($segA, $segB, $radius, $triangle);
                    if ($resolution !== null) {
                        $shapes[] = $triangle;
                        $owners[] = $mc['entityId'];
                        $pushes[] = $resolution;
                        $depths[] = $resolution->dot($resolution);
                    }
                }
            }
            // Turned boxes the capsule can touch: its AABB (plus slack) against their hull.
            $slack = $radius + self::QUERY_SLACK;
            foreach ($this->colliders->rotatedIn($pos->x - $slack, $pos->z - $slack, $pos->x + $slack, $pos->z + $slack) as $rotated) {
                $box = $rotated['box'];
                if ($rotated['entityId'] === $characterEntityId) {
                    continue;
                }
                $resolution = $box->capsulePush($segA, $segB, $radius);
                if ($resolution !== null) {
                    $shapes[] = $box;
                    $owners[] = $rotated['entityId'];
                    $pushes[] = $resolution;
                    $depths[] = $resolution->dot($resolution);
                }
            }

            if ($shapes === []) {
                break; // Converged — no more penetrations
            }

            // Deepest first, and each one asked again from where the one before left
            // the capsule. A flat wall of two triangles touches beside its diagonal
            // on both, but only one of them holds the true depth: taking the other
            // first would push the capsule along the wall, and which came first
            // would be a matter of BVH order.
            if (count($depths) > 1) {
                arsort($depths);
            }

            $moved = false;
            foreach ($depths as $index => $depth) {
                $shape = $shapes[$index];
                $resolution = $pushes[$index];
                if ($moved) {
                    $resolution = $shape instanceof OrientedBox
                        ? $shape->capsulePush($segA, $segB, $radius)
                        : CollisionMath::capsuleVsTriangle($segA, $segB, $radius, $shape);
                    if ($resolution === null) {
                        continue;
                    }
                }
                $moved = true;
                $entityId = $owners[$index];

                // A beam at head height over a character on the ground: down is no
                // way out, the floor is there. It stops at the beam's edge instead.
                if ($resolution->y < 0.0 && $controller->isGrounded && $resolution->dot($resolution) < $radius * $radius) {
                    $level = self::pastBeamEdge($resolution, $radius);
                    if ($level !== null) {
                        $resolution = $level;
                    } elseif (($settled = $controller->settledPosition) !== null) {
                        // Straight under it already: a fast walker that passed the
                        // edge within one tick. Back to where it was still clear.
                        $backA = new Vec3($settled->x, $segA->y, $settled->z);
                        $backB = new Vec3($settled->x, $segB->y, $settled->z);
                        $clearBehind = $shape instanceof OrientedBox
                            ? $shape->capsulePush($backA, $backB, $radius) === null
                            : CollisionMath::capsuleVsTriangle($backA, $backB, $radius, $shape) === null;
                        if ($clearBehind) {
                            $resolution = new Vec3($settled->x - $pos->x, 0.0, $settled->z - $pos->z);
                        }
                    }
                }

                // Step-climbing for rotated box colliders:
                // If the collision pushes horizontally and the box top is within stepHeight,
                // lift the character onto the surface instead.
                $isHorizontalPush = abs($resolution->y) < abs($resolution->x) + abs($resolution->z);
                $stepped = false;
                if ($isHorizontalPush && $shape instanceof OrientedBox) {
                    $feetY = $pos->y - $halfHeight;
                    $topY = $shape->topNear($pos->x, $pos->z);
                    $stepUp = $topY - $feetY;
                    if ($stepUp > 0.0 && $stepUp <= $controller->stepHeight
                        && $assist->bodyFitsAt($pos->x, $pos->z, $topY, $controller, $characterEntityId, $entityId, $resolution)
                    ) {
                        $pos = new Vec3($pos->x, $topY + $halfHeight, $pos->z);
                        $controller->velocity = new Vec3($controller->velocity->x, 0.0, $controller->velocity->z);
                        $controller->isGrounded = true;
                        $controller->groundEntityId = $entityId;
                        $stepped = true;
                    }
                }

                if (!$stepped) {
                    $pos = $pos->add($resolution);

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
                            $controller->groundEntityId = $entityId;
                        } elseif ($resDir->y > self::MIN_WEDGE_NORMAL_Y) {
                            $this->wedged[$characterEntityId] = true;
                        }
                    }
                }

                // Update capsule segment for next triangle test within this iteration
                $segA = new Vec3($pos->x, $pos->y - $capsuleHalfLen, $pos->z);
                $segB = new Vec3($pos->x, $pos->y + $capsuleHalfLen, $pos->z);
            }
        }

        return $pos;
    }

    /**
     * A contact that would push a grounded capsule down, turned into the level push
     * that keeps the same contact distance. Null when the contact lies straight
     * overhead, with no side to go to.
     */
    private static function pastBeamEdge(Vec3 $resolution, float $radius): ?Vec3
    {
        $depth = $resolution->length();
        $distance = $radius - $depth;
        $scale = $distance / $depth;
        $toCapsuleX = $resolution->x * $scale;
        $toCapsuleY = $resolution->y * $scale;
        $toCapsuleZ = $resolution->z * $scale;
        $level = sqrt($toCapsuleX * $toCapsuleX + $toCapsuleZ * $toCapsuleZ);
        if ($level < 1e-6) {
            return null;
        }

        $push = sqrt(max(0.0, $radius * $radius - $toCapsuleY * $toCapsuleY)) - $level;

        return new Vec3($toCapsuleX / $level * $push, 0.0, $toCapsuleZ / $level * $push);
    }

    /**
     * The level way out of a box a grounded character has only walked a little
     * under, or null when it is further in than its own width.
     */
    private static function outOfBeamSideways(Vec3 $charMin, Vec3 $charMax, Vec3 $boxMin, Vec3 $boxMax, float $radius): ?Vec3
    {
        $pushLeft = $boxMin->x - $charMax->x;
        $pushRight = $boxMax->x - $charMin->x;
        $pushBack = $boxMin->z - $charMax->z;
        $pushFore = $boxMax->z - $charMin->z;

        $x = abs($pushLeft) < abs($pushRight) ? $pushLeft : $pushRight;
        $z = abs($pushBack) < abs($pushFore) ? $pushBack : $pushFore;
        if (abs($x) <= abs($z)) {
            return abs($x) <= 2.0 * $radius ? new Vec3($x, 0.0, 0.0) : null;
        }

        return abs($z) <= 2.0 * $radius ? new Vec3(0.0, 0.0, $z) : null;
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
     * @internal Shared with {@see BoxColliderIndex}.
     *
     * @param float[] $a
     * @param float[] $b
     */
    public static function matrixArrayEquals(array $a, array $b): bool
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
     *
     * The push on an axis is the whole way out on the side of A's centre, not the
     * size of the intersection: a box thinner than the penetration would otherwise
     * give back only its own thickness per tick, and a stride longer than that
     * walks through it. A's centre has to stay on its side, so a stride must stay
     * below A's half width.
     */
    public static function resolveAABB(Vec3 $aMin, Vec3 $aMax, Vec3 $bMin, Vec3 $bMax): ?Vec3
    {
        if (min($aMax->x, $bMax->x) - max($aMin->x, $bMin->x) <= 0
            || min($aMax->y, $bMax->y) - max($aMin->y, $bMin->y) <= 0
            || min($aMax->z, $bMax->z) - max($aMin->z, $bMin->z) <= 0
        ) {
            return null;
        }

        $pushX = $aMin->x + $aMax->x < $bMin->x + $bMax->x ? $bMin->x - $aMax->x : $bMax->x - $aMin->x;
        $pushY = $aMin->y + $aMax->y < $bMin->y + $bMax->y ? $bMin->y - $aMax->y : $bMax->y - $aMin->y;
        $pushZ = $aMin->z + $aMax->z < $bMin->z + $bMax->z ? $bMin->z - $aMax->z : $bMax->z - $aMin->z;

        // Push out along axis of minimum penetration
        $depthX = abs($pushX);
        $depthY = abs($pushY);
        $depthZ = abs($pushZ);

        if ($depthX <= $depthY && $depthX <= $depthZ) {
            return new Vec3($pushX, 0.0, 0.0);
        }
        if ($depthY <= $depthX && $depthY <= $depthZ) {
            return new Vec3(0.0, $pushY, 0.0);
        }
        return new Vec3(0.0, 0.0, $pushZ);
    }
}
