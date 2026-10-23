<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Spatial;

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * Where the ears are: position plus an orthonormal frame (forward, up, right).
 *
 * Listener space – what {@see toLocal()} returns and what spatial backends
 * receive as a direction – is right-handed like the camera: +X right, +Y up,
 * −Z forward.
 *
 * $velocity (world units per second) drives the Doppler effect on a native
 * spatial backend. Null means "not given": AudioManager then derives it from
 * the position change between two {@see \PHPolygon\Audio\AudioManager::setListener()}
 * calls.
 */
final class AudioListener
{
    public readonly Vec3 $position;
    public readonly Vec3 $forward;
    public readonly Vec3 $up;
    public readonly Vec3 $right;
    public readonly ?Vec3 $velocity;

    public function __construct(?Vec3 $position = null, ?Vec3 $forward = null, ?Vec3 $up = null, ?Vec3 $velocity = null)
    {
        $this->position = $position !== null ? new Vec3($position->x, $position->y, $position->z) : new Vec3();
        $this->velocity = $velocity !== null ? new Vec3($velocity->x, $velocity->y, $velocity->z) : null;

        $f = $forward ?? new Vec3(0.0, 0.0, -1.0);
        $f = $f->lengthSquared() > 1e-12 ? $f->normalize() : new Vec3(0.0, 0.0, -1.0);
        $u = $up ?? new Vec3(0.0, 1.0, 0.0);

        $r = $f->cross($u);
        if ($r->lengthSquared() < 1e-12) {
            // Up parallel to forward (looking straight up/down): pick any perpendicular.
            $r = $f->cross(abs($f->y) < 0.99 ? new Vec3(0.0, 1.0, 0.0) : new Vec3(0.0, 0.0, 1.0));
        }
        $r = $r->normalize();

        $this->forward = $f;
        $this->right = $r;
        $this->up = $r->cross($f)->normalize();
    }

    /** The same pose moving at $velocity (null = let AudioManager derive it). */
    public function withVelocity(?Vec3 $velocity): self
    {
        return new self($this->position, $this->forward, $this->up, $velocity);
    }

    /** Listener at $position looking along $rotation applied to −Z (the camera convention). */
    public static function fromRotation(Vec3 $position, Quaternion $rotation): self
    {
        return new self(
            $position,
            $rotation->rotateVec3(new Vec3(0.0, 0.0, -1.0)),
            $rotation->rotateVec3(new Vec3(0.0, 1.0, 0.0)),
        );
    }

    /**
     * The pose of the first active Camera3DComponent (world position plus its
     * eye offset, transform rotation) – the same camera the renderer uses,
     * without its render interpolation. Null when no camera is active.
     */
    public static function fromActiveCamera(World $world): ?self
    {
        foreach ($world->query(Camera3DComponent::class, Transform3D::class) as $entity) {
            $cam = $entity->get(Camera3DComponent::class);
            if (!$cam->active) {
                continue;
            }
            $transform = $entity->get(Transform3D::class);
            return self::fromRotation($transform->getWorldPosition()->add($cam->eyeOffset), $transform->rotation);
        }
        return null;
    }

    /** A world-space point in listener space (+X right, +Y up, −Z forward). */
    public function toLocal(Vec3 $world): Vec3
    {
        $d = $world->sub($this->position);
        return new Vec3($d->dot($this->right), $d->dot($this->up), -$d->dot($this->forward));
    }

    public function distanceTo(Vec3 $world): float
    {
        return sqrt($this->position->distanceSquaredTo($world));
    }
}
