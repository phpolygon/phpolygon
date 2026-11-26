<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\BVH;
use PHPolygon\Physics\Triangle;

/**
 * Axis-aligned 3D box collider.
 * Size is the full extent (not half-extent). Centered on the entity's position + offset.
 */
#[Serializable]
#[Category('Physics')]
class BoxCollider3D extends AbstractComponent
{
    #[Property(editorHint: 'vec3')]
    public Vec3 $size;

    #[Property(editorHint: 'vec3')]
    public Vec3 $offset;

    #[Property]
    public bool $isTrigger;

    #[Property]
    public bool $isStatic;

    /**
     * BVH of the 12 face triangles of a rotated box, for
     * {@see \PHPolygon\Physics\Raycaster3D::segmentBlocked()} only. Built lazily
     * by {@see worldBvh()} and dropped when the box moves; read it through
     * worldBvh(), not directly.
     */
    #[Hidden]
    public ?BVH $bvh = null;

    /**
     * Cached world AABB for non-rotated boxes. getWorldAABB transforms
     * 8 corners through the world matrix; with hundreds of static box
     * colliders that's thousands of Mat4 multiplies per frame. The cache
     * is keyed on the world-matrix snapshot below.
     *
     * @var array{min:Vec3, max:Vec3}|null
     */
    #[Hidden]
    public ?array $cachedWorldAabb = null;

    /** @var float[]|null */
    #[Hidden]
    public ?array $lastWorldMatrixArr = null;

    /**
     * The world matrix object the caches above were built from. Mat4 is
     * immutable, so the same object means the transform did not change and the
     * value compare against lastWorldMatrixArr can be skipped.
     */
    #[Hidden]
    public ?\PHPolygon\Math\Mat4 $lastWorldMatrix = null;

    /** Whether the cached collision data is the rotated (BVH) form. */
    #[Hidden]
    public bool $cachedRotated = false;

    /** The solid form of a rotated box: what characters collide with. */
    #[Hidden]
    public ?\PHPolygon\Physics\OrientedBox $cachedOriented = null;

    public function __construct(
        ?Vec3 $size = null,
        ?Vec3 $offset = null,
        bool $isTrigger = false,
        bool $isStatic = true,
    ) {
        $this->size = $size ?? new Vec3(1.0, 1.0, 1.0);
        $this->offset = $offset ?? Vec3::zero();
        $this->isTrigger = $isTrigger;
        $this->isStatic = $isStatic;
    }

    /**
     * The world-space BVH of a turned box (its 12 face triangles), built on first use from the world matrix
     * the caches were made from. Null while the box is not cached as turned or was not cached yet. Only
     * {@see \PHPolygon\Physics\Raycaster3D::segmentBlocked()} asks: building it with every move of every
     * turned box would cost more than the physics tick it belongs to.
     */
    public function worldBvh(): ?BVH
    {
        if ($this->bvh === null && $this->cachedRotated && $this->lastWorldMatrix !== null) {
            $this->bvh = BVH::build($this->worldTriangles($this->lastWorldMatrix));
        }

        return $this->bvh;
    }

    /**
     * The box as 12 world-space triangles (6 faces x 2), degenerate ones left out.
     *
     * @return list<Triangle>
     */
    public function worldTriangles(\PHPolygon\Math\Mat4 $worldMatrix): array
    {
        $hx = $this->size->x * 0.5;
        $hy = $this->size->y * 0.5;
        $hz = $this->size->z * 0.5;
        $off = $this->offset;

        // 8 corners in local space
        $corners = [];
        foreach ([[-1, 1], [1, 1], [1, -1], [-1, -1]] as [$sx, $sz]) {
            foreach ([-1, 1] as $sy) {
                $corners["{$sx}_{$sy}_{$sz}"] = $worldMatrix->transformPoint(new Vec3(
                    $off->x + $sx * $hx,
                    $off->y + $sy * $hy,
                    $off->z + $sz * $hz,
                ));
            }
        }

        $c = static fn(int $sx, int $sy, int $sz): Vec3 => $corners["{$sx}_{$sy}_{$sz}"];

        $triangles = [];
        // 6 faces, each as 2 triangles, wound clockwise seen from outside: the
        // normals point into the box. Only double-sided queries read them.
        $faces = [
            [$c(1, -1, 1), $c(1, 1, 1), $c(1, 1, -1), $c(1, -1, -1)],     // +X
            [$c(-1, -1, -1), $c(-1, 1, -1), $c(-1, 1, 1), $c(-1, -1, 1)], // -X
            [$c(-1, 1, 1), $c(-1, 1, -1), $c(1, 1, -1), $c(1, 1, 1)],     // +Y
            [$c(-1, -1, -1), $c(-1, -1, 1), $c(1, -1, 1), $c(1, -1, -1)], // -Y
            [$c(-1, -1, 1), $c(-1, 1, 1), $c(1, 1, 1), $c(1, -1, 1)],     // +Z
            [$c(1, -1, -1), $c(1, 1, -1), $c(-1, 1, -1), $c(-1, -1, -1)], // -Z
        ];

        foreach ($faces as [$v0, $v1, $v2, $v3]) {
            $t1 = new Triangle($v0, $v1, $v2);
            $t2 = new Triangle($v0, $v2, $v3);
            if (!$t1->isDegenerate()) {
                $triangles[] = $t1;
            }
            if (!$t2->isDegenerate()) {
                $triangles[] = $t2;
            }
        }

        return $triangles;
    }

    /**
     * Get the world-space AABB min/max, accounting for entity rotation and scale.
     * Transforms all 8 corners through the world matrix to compute a tight AABB.
     *
     * @return array{min: Vec3, max: Vec3}
     */
    public function getWorldAABB(\PHPolygon\Math\Mat4 $worldMatrix): array
    {
        $hx = $this->size->x * 0.5;
        $hy = $this->size->y * 0.5;
        $hz = $this->size->z * 0.5;
        $off = $this->offset;

        $minX = PHP_FLOAT_MAX; $minY = PHP_FLOAT_MAX; $minZ = PHP_FLOAT_MAX;
        $maxX = -PHP_FLOAT_MAX; $maxY = -PHP_FLOAT_MAX; $maxZ = -PHP_FLOAT_MAX;

        foreach ([[-1, 1], [1, 1], [-1, -1], [1, -1]] as [$sx, $sz]) {
            foreach ([-1, 1] as $sy) {
                $corner = $worldMatrix->transformPoint(new Vec3(
                    $off->x + $sx * $hx,
                    $off->y + $sy * $hy,
                    $off->z + $sz * $hz,
                ));
                $minX = min($minX, $corner->x); $maxX = max($maxX, $corner->x);
                $minY = min($minY, $corner->y); $maxY = max($maxY, $corner->y);
                $minZ = min($minZ, $corner->z); $maxZ = max($maxZ, $corner->z);
            }
        }

        return [
            'min' => new Vec3($minX, $minY, $minZ),
            'max' => new Vec3($maxX, $maxY, $maxZ),
        ];
    }
}
