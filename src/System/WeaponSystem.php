<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Health;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Projectile;
use PHPolygon\Component\ShooterGameState;
use PHPolygon\Component\Team;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Weapon;
use PHPolygon\Component\WeaponMode;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;

/**
 * Fires every {@see Weapon} that is off cooldown and either has its per-tick
 * {@see Weapon::$firing} intent set (the player, via
 * {@see ShooterControllerSystem}) or {@see Weapon::$autoFire} on (enemies, which
 * also auto-aim at the nearest player). Projectile weapons spawn a
 * {@see Projectile} entity at the muzzle; hitscan weapons apply damage instantly
 * through {@see DamageSystem::damage()}.
 *
 * Aim is taken from {@see Weapon::$aim} (a unit world-space direction) and falls
 * back to the owner's forward (-Z) when unset.
 */
class WeaponSystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        $state = $this->findState($world);

        /** @var list<Vec3> $playerPositions */
        $playerPositions = [];
        foreach ($world->query(Health::class, Transform3D::class) as $entity) {
            $h = $entity->get(Health::class);
            if ($h->team === Team::Player && !$h->dead) {
                $playerPositions[] = $entity->get(Transform3D::class)->position;
            }
        }

        foreach ($world->query(Weapon::class, Transform3D::class) as $entity) {
            $w = $entity->get(Weapon::class);
            $tf = $entity->get(Transform3D::class);

            if ($w->cooldown > 0) {
                $w->cooldown--;
            }

            $health = $entity->tryGet(Health::class);
            $team = $health->team ?? Team::Player;

            $pos = $tf->position;
            $forward = $tf->rotation->rotateVec3(new Vec3(0.0, 0.0, -1.0));

            // Genre-neutral fire decision: fire on an explicit per-tick intent
            // (Weapon::$firing, set by ANY controller — not just the shooter one)
            // or on autoFire. Enemies that fire on their own auto-aim at the
            // nearest player; a player-team auto-firer keeps its preset aim.
            $aim = $w->aim;
            $fire = $w->firing || $w->autoFire;
            if ($w->autoFire && $team === Team::Enemy) {
                $target = $this->nearest($playerPositions, $pos);
                if ($target !== null) {
                    $aim = new Vec3($target->x - $pos->x, $target->y - $pos->y, $target->z - $pos->z);
                }
            }

            $dir = $this->normalizeOr($aim, $forward);

            if ($fire && $w->cooldown <= 0) {
                $muzzleWorld = $this->muzzleWorld($pos, $tf, $w->muzzleOffset);
                if ($w->mode === WeaponMode::Projectile) {
                    $this->spawnProjectile($world, $w, $muzzleWorld, $dir, $team);
                } else {
                    $this->hitscan($world, $muzzleWorld, $dir, $w->range, $w->damage, $team, $state);
                }
                $w->cooldown = $w->fireRate;
            }

            // The player's intent is per-tick; consume it.
            $w->firing = false;
        }
    }

    private function spawnProjectile(World $world, Weapon $w, Vec3 $muzzle, Vec3 $dir, Team $team): void
    {
        $s = $w->projectileScale;
        $entity = $world->createEntity();
        $entity->attach(new Transform3D(position: $muzzle, scale: new Vec3($s, $s, $s)));
        $entity->attach(new MeshRenderer(meshId: $w->projectileMeshId, materialId: $w->projectileMaterialId));
        $entity->attach(new Projectile(
            velocity: new Vec3($dir->x * $w->projectileSpeed, $dir->y * $w->projectileSpeed, $dir->z * $w->projectileSpeed),
            damage: $w->damage,
            lifetime: $w->projectileLifetime,
            team: $team,
            hitRadius: max(0.1, 0.5 * $s),
        ));
    }

    private function hitscan(World $world, Vec3 $origin, Vec3 $dir, float $range, float $damage, Team $team, ?ShooterGameState $state): void
    {
        $bestT = INF;
        $bestId = -1;
        $bestHealth = null;
        foreach ($world->query(Health::class, Transform3D::class) as $entity) {
            $h = $entity->get(Health::class);
            if ($h->dead || $h->team === $team) {
                continue;
            }
            $c = $entity->get(Transform3D::class)->position;
            $ox = $c->x - $origin->x;
            $oy = $c->y - $origin->y;
            $oz = $c->z - $origin->z;
            $t = $ox * $dir->x + $oy * $dir->y + $oz * $dir->z; // projection onto ray
            if ($t < 0.0 || $t > $range) {
                continue;
            }
            $perp2 = ($ox * $ox + $oy * $oy + $oz * $oz) - $t * $t; // squared miss distance
            $r = $h->contactRadius;
            if ($perp2 <= $r * $r && $t < $bestT) {
                $bestT = $t;
                $bestId = $entity->id;
                $bestHealth = $h;
            }
        }
        if ($bestHealth !== null) {
            DamageSystem::damage($world, $bestId, $bestHealth, $damage, $state);
        }
    }

    /** Owner-local muzzle offset rotated into world space and added to the owner position. */
    private function muzzleWorld(Vec3 $pos, Transform3D $tf, Vec3 $offset): Vec3
    {
        $r = $tf->rotation->rotateVec3($offset);
        return new Vec3($pos->x + $r->x, $pos->y + $r->y, $pos->z + $r->z);
    }

    /** @param list<Vec3> $positions */
    private function nearest(array $positions, Vec3 $from): ?Vec3
    {
        $best = null;
        $bestD = INF;
        foreach ($positions as $p) {
            $d = ($p->x - $from->x) ** 2 + ($p->y - $from->y) ** 2 + ($p->z - $from->z) ** 2;
            if ($d < $bestD) {
                $bestD = $d;
                $best = $p;
            }
        }
        return $best;
    }

    private function normalizeOr(Vec3 $v, Vec3 $fallback): Vec3
    {
        $len = sqrt($v->x * $v->x + $v->y * $v->y + $v->z * $v->z);
        if ($len < 1e-6) {
            return $fallback;
        }
        return new Vec3($v->x / $len, $v->y / $len, $v->z / $len);
    }

    private function findState(World $world): ?ShooterGameState
    {
        foreach ($world->query(ShooterGameState::class) as $entity) {
            return $entity->get(ShooterGameState::class);
        }
        return null;
    }
}
