<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Health;
use PHPolygon\Component\Mover;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;

/**
 * Advances every {@see Mover} by its per-tick velocity, optionally steering it
 * toward the nearest {@see Health} of its {@see Mover::$homingTeam}, and
 * despawns it once it travels past {@see Mover::$despawnDistance} from the world
 * origin. Drives incoming enemies in an arcade shooter.
 */
class MoverSystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        /** @var list<int> $gone */
        $gone = [];

        foreach ($world->query(Mover::class, Transform3D::class) as $entity) {
            $mover = $entity->get(Mover::class);
            $tf = $entity->get(Transform3D::class);
            $pos = $tf->position;
            $v = $mover->velocity;

            if ($mover->homingRate > 0.0) {
                $target = $this->nearestOfTeam($world, $mover->homingTeam, $pos);
                if ($target !== null) {
                    $speed = sqrt($v->x * $v->x + $v->y * $v->y + $v->z * $v->z);
                    if ($speed > 1e-6) {
                        $tx = $target->x - $pos->x;
                        $ty = $target->y - $pos->y;
                        $tz = $target->z - $pos->z;
                        $tl = sqrt($tx * $tx + $ty * $ty + $tz * $tz);
                        if ($tl > 1e-6) {
                            $k = $mover->homingRate;
                            // Blend current heading toward the target, renormalised to keep speed.
                            $nx = $v->x + ($tx / $tl * $speed - $v->x) * $k;
                            $ny = $v->y + ($ty / $tl * $speed - $v->y) * $k;
                            $nz = $v->z + ($tz / $tl * $speed - $v->z) * $k;
                            $nl = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
                            if ($nl > 1e-6) {
                                $v = new Vec3($nx / $nl * $speed, $ny / $nl * $speed, $nz / $nl * $speed);
                                $mover->velocity = $v;
                            }
                        }
                    }
                }
            }

            $pos = new Vec3($pos->x + $v->x, $pos->y + $v->y, $pos->z + $v->z);
            $tf->position = $pos;

            if ($mover->despawnDistance > 0.0) {
                $d2 = $pos->x * $pos->x + $pos->y * $pos->y + $pos->z * $pos->z;
                if ($d2 > $mover->despawnDistance * $mover->despawnDistance) {
                    $gone[] = $entity->id;
                }
            }
        }

        foreach ($gone as $id) {
            $world->destroyEntity($id);
        }
    }

    private function nearestOfTeam(World $world, \PHPolygon\Component\Team $team, Vec3 $from): ?Vec3
    {
        $best = null;
        $bestD = INF;
        foreach ($world->query(Health::class, Transform3D::class) as $entity) {
            $h = $entity->get(Health::class);
            if ($h->team !== $team || $h->dead) {
                continue;
            }
            $p = $entity->get(Transform3D::class)->position;
            $d = ($p->x - $from->x) ** 2 + ($p->y - $from->y) ** 2 + ($p->z - $from->z) ** 2;
            if ($d < $bestD) {
                $bestD = $d;
                $best = $p;
            }
        }
        return $best;
    }
}
