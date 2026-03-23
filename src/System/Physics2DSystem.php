<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\BoxCollider2D;
use PHPolygon\Component\RigidBody2D;
use PHPolygon\Component\Transform2D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Event\CollisionEnter;
use PHPolygon\Event\CollisionExit;
use PHPolygon\Event\EventDispatcher;
use PHPolygon\Event\TriggerEnter;
use PHPolygon\Event\TriggerExit;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Physics\Collision2D;
use PHPolygon\Physics\RaycastHit2D;

class Physics2DSystem extends AbstractSystem
{
    private Vec2 $gravity;

    /** @var array<string, bool> Active collision pairs from previous frame */
    private array $activePairs = [];

    private ?EventDispatcher $events;

    public function __construct(
        ?Vec2 $gravity = null,
        ?EventDispatcher $events = null,
    ) {
        $this->gravity = $gravity ?? new Vec2(0.0, 980.0);
        $this->events = $events;
    }

    public function setGravity(Vec2 $gravity): void
    {
        $this->gravity = $gravity;
    }

    public function getGravity(): Vec2
    {
        return $this->gravity;
    }

    public function update(World $world, float $dt): void
    {
        $this->integrate($world, $dt);
        $this->detectAndResolveCollisions($world);
    }

    private function integrate(World $world, float $dt): void
    {
        foreach ($world->query(Transform2D::class, RigidBody2D::class) as $entity) {
            $transform = $world->getComponent($entity->id, Transform2D::class);
            $rb = $world->getComponent($entity->id, RigidBody2D::class);

            if ($rb->isKinematic) {
                continue;
            }

            // Apply gravity
            $gravityForce = $this->gravity->mul($rb->gravityScale);
            $totalAcceleration = $rb->acceleration->add($gravityForce);

            // Semi-implicit Euler integration
            $rb->velocity = $rb->velocity->add($totalAcceleration->mul($dt));

            // Apply drag
            if ($rb->drag > 0) {
                $rb->velocity = $rb->velocity->mul(1.0 - $rb->drag * $dt);
            }

            // Update position
            $transform->position = $transform->position->add($rb->velocity->mul($dt));

            // Angular velocity
            if (!$rb->fixedRotation) {
                $rb->angularVelocity *= (1.0 - $rb->angularDrag * $dt);
                $transform->rotation += $rb->angularVelocity * $dt;
            }

            // Clear per-frame acceleration
            $rb->acceleration = Vec2::zero();
        }
    }

    private function detectAndResolveCollisions(World $world): void
    {
        $colliders = [];
        foreach ($world->query(Transform2D::class, BoxCollider2D::class) as $entity) {
            $transform = $world->getComponent($entity->id, Transform2D::class);
            $collider = $world->getComponent($entity->id, BoxCollider2D::class);
            $colliders[] = [
                'id' => $entity->id,
                'transform' => $transform,
                'collider' => $collider,
                'rect' => $collider->getWorldRect($transform->position),
            ];
        }

        $currentPairs = [];
        $count = count($colliders);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $colliders[$i];
                $b = $colliders[$j];

                $pairKey = $this->pairKey($a['id'], $b['id']);

                if (!$a['rect']->intersects($b['rect'])) {
                    // Was colliding, now separated
                    if (isset($this->activePairs[$pairKey])) {
                        $this->dispatchExit($a, $b);
                    }
                    continue;
                }

                $currentPairs[$pairKey] = true;

                // Compute collision details
                $collision = $this->computeCollision(
                    $a['id'], $b['id'], $a['rect'], $b['rect']
                );

                if ($collision === null) {
                    continue;
                }

                $isTrigger = $a['collider']->isTrigger || $b['collider']->isTrigger;

                if ($isTrigger) {
                    if (!isset($this->activePairs[$pairKey])) {
                        $this->events?->dispatch(new TriggerEnter($a['id'], $b['id']));
                    }
                } else {
                    // Resolve physical collision
                    $this->resolveCollision($world, $collision);

                    if (!isset($this->activePairs[$pairKey])) {
                        $this->events?->dispatch(new CollisionEnter($collision));
                    }
                }
            }
        }

        // Detect exits for pairs that were active but are no longer
        foreach ($this->activePairs as $key => $_) {
            if (!isset($currentPairs[$key])) {
                [$idA, $idB] = explode(':', $key);
                $isTriggerA = $this->isTrigger($world, (int)$idA);
                $isTriggerB = $this->isTrigger($world, (int)$idB);

                if ($isTriggerA || $isTriggerB) {
                    $this->events?->dispatch(new TriggerExit((int)$idA, (int)$idB));
                } else {
                    $this->events?->dispatch(new CollisionExit((int)$idA, (int)$idB));
                }
            }
        }

        $this->activePairs = $currentPairs;
    }

    private function computeCollision(int $idA, int $idB, Rect $a, Rect $b): ?Collision2D
    {
        // AABB overlap computation
        $overlapX = min($a->right(), $b->right()) - max($a->left(), $b->left());
        $overlapY = min($a->bottom(), $b->bottom()) - max($a->top(), $b->top());

        if ($overlapX <= 0 || $overlapY <= 0) {
            return null;
        }

        $centerA = $a->center();
        $centerB = $b->center();

        // Minimum penetration axis
        if ($overlapX < $overlapY) {
            $normal = new Vec2($centerA->x < $centerB->x ? -1.0 : 1.0, 0.0);
            $penetration = $overlapX;
        } else {
            $normal = new Vec2(0.0, $centerA->y < $centerB->y ? -1.0 : 1.0);
            $penetration = $overlapY;
        }

        $contactPoint = new Vec2(
            max($a->left(), $b->left()) + $overlapX * 0.5,
            max($a->top(), $b->top()) + $overlapY * 0.5,
        );

        return new Collision2D($idA, $idB, $normal, $penetration, $contactPoint);
    }

    private function resolveCollision(World $world, Collision2D $collision): void
    {
        $rbA = $world->tryGetComponent($collision->entityA, RigidBody2D::class);
        $rbB = $world->tryGetComponent($collision->entityB, RigidBody2D::class);
        $tA = $world->tryGetComponent($collision->entityA, Transform2D::class);
        $tB = $world->tryGetComponent($collision->entityB, Transform2D::class);

        if ($tA === null || $tB === null) {
            return;
        }

        $aKinematic = $rbA === null || $rbA->isKinematic;
        $bKinematic = $rbB === null || $rbB->isKinematic;

        // Position correction (push apart)
        $normal = $collision->normal;
        $pen = $collision->penetration;

        if ($aKinematic && $bKinematic) {
            return; // Both immovable
        }

        if ($aKinematic) {
            $tB->position = $tB->position->sub($normal->mul($pen));
        } elseif ($bKinematic) {
            $tA->position = $tA->position->add($normal->mul($pen));
        } else {
            $half = $pen * 0.5;
            $tA->position = $tA->position->add($normal->mul($half));
            $tB->position = $tB->position->sub($normal->mul($half));
        }

        // Velocity response
        if ($rbA === null && $rbB === null) {
            return;
        }

        $velA = $rbA?->velocity ?? Vec2::zero();
        $velB = $rbB?->velocity ?? Vec2::zero();
        $relativeVelocity = $velA->sub($velB);
        $velocityAlongNormal = $relativeVelocity->dot($normal);

        // Only resolve if objects are moving towards each other
        if ($velocityAlongNormal > 0) {
            return;
        }

        $restitution = max(
            $rbA?->restitution ?? 0.0,
            $rbB?->restitution ?? 0.0,
        );

        $massA = $aKinematic ? PHP_FLOAT_MAX : $rbA->mass;
        $massB = $bKinematic ? PHP_FLOAT_MAX : $rbB->mass;
        $invMassA = $aKinematic ? 0.0 : 1.0 / $massA;
        $invMassB = $bKinematic ? 0.0 : 1.0 / $massB;

        $j = -(1.0 + $restitution) * $velocityAlongNormal / ($invMassA + $invMassB);

        if (!$aKinematic && $rbA !== null) {
            $rbA->velocity = $rbA->velocity->add($normal->mul($j * $invMassA));
        }
        if (!$bKinematic && $rbB !== null) {
            $rbB->velocity = $rbB->velocity->sub($normal->mul($j * $invMassB));
        }
    }

    /**
     * Cast a ray and return the first hit.
     *
     * @return RaycastHit2D|null
     */
    public function raycast(World $world, Vec2 $origin, Vec2 $direction, float $maxDistance = PHP_FLOAT_MAX): ?RaycastHit2D
    {
        $dir = $direction->normalize();
        $closest = null;
        $closestDist = $maxDistance;

        foreach ($world->query(Transform2D::class, BoxCollider2D::class) as $entity) {
            $transform = $world->getComponent($entity->id, Transform2D::class);
            $collider = $world->getComponent($entity->id, BoxCollider2D::class);
            $rect = $collider->getWorldRect($transform->position);

            $hit = $this->raycastAABB($origin, $dir, $rect, $closestDist);
            if ($hit !== null && $hit['distance'] < $closestDist) {
                $closestDist = $hit['distance'];
                $closest = new RaycastHit2D(
                    $entity->id,
                    $hit['point'],
                    $hit['normal'],
                    $hit['distance'],
                );
            }
        }

        return $closest;
    }

    /**
     * @return array{point: Vec2, normal: Vec2, distance: float}|null
     */
    private function raycastAABB(Vec2 $origin, Vec2 $dir, Rect $rect, float $maxDist): ?array
    {
        $invDirX = $dir->x != 0.0 ? 1.0 / $dir->x : PHP_FLOAT_MAX;
        $invDirY = $dir->y != 0.0 ? 1.0 / $dir->y : PHP_FLOAT_MAX;

        $tx1 = ($rect->left() - $origin->x) * $invDirX;
        $tx2 = ($rect->right() - $origin->x) * $invDirX;
        $ty1 = ($rect->top() - $origin->y) * $invDirY;
        $ty2 = ($rect->bottom() - $origin->y) * $invDirY;

        $tmin = max(min($tx1, $tx2), min($ty1, $ty2));
        $tmax = min(max($tx1, $tx2), max($ty1, $ty2));

        if ($tmax < 0 || $tmin > $tmax || $tmin > $maxDist) {
            return null;
        }

        $t = $tmin < 0 ? $tmax : $tmin;
        if ($t < 0) {
            return null;
        }

        $point = $origin->add($dir->mul($t));

        // Determine normal from hit face
        $normal = Vec2::zero();
        if (abs($point->x - $rect->left()) < 0.001) {
            $normal = new Vec2(-1.0, 0.0);
        } elseif (abs($point->x - $rect->right()) < 0.001) {
            $normal = new Vec2(1.0, 0.0);
        } elseif (abs($point->y - $rect->top()) < 0.001) {
            $normal = new Vec2(0.0, -1.0);
        } elseif (abs($point->y - $rect->bottom()) < 0.001) {
            $normal = new Vec2(0.0, 1.0);
        }

        return ['point' => $point, 'normal' => $normal, 'distance' => $t];
    }

    private function pairKey(int $a, int $b): string
    {
        return $a < $b ? "{$a}:{$b}" : "{$b}:{$a}";
    }

    private function isTrigger(World $world, int $entityId): bool
    {
        if (!$world->isAlive($entityId)) {
            return false;
        }
        $collider = $world->tryGetComponent($entityId, BoxCollider2D::class);
        return $collider instanceof BoxCollider2D && $collider->isTrigger;
    }

    /**
     * @param array{id: int, transform: Transform2D, collider: BoxCollider2D, rect: Rect} $a
     * @param array{id: int, transform: Transform2D, collider: BoxCollider2D, rect: Rect} $b
     */
    private function dispatchExit(array $a, array $b): void
    {
        if ($a['collider']->isTrigger || $b['collider']->isTrigger) {
            $this->events?->dispatch(new TriggerExit($a['id'], $b['id']));
        } else {
            $this->events?->dispatch(new CollisionExit($a['id'], $b['id']));
        }
    }
}
