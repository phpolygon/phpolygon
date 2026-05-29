<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Patrol;
use PHPolygon\Component\PlatformerController;
use PHPolygon\Component\PlatformerGameState;
use PHPolygon\Component\SpinBob;
use PHPolygon\Component\Stompable;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;

/**
 * Resolves {@see PlatformerController} ↔ {@see Stompable} contact: descending
 * onto an enemy's head defeats it (and bounces the player); any other contact
 * hurts the player via {@see PlatformerControllerSystem::applyDamage()}.
 */
class StompSystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        $playerPc = null;
        $playerTf = null;
        foreach ($world->query(PlatformerController::class, Transform3D::class) as $entity) {
            $playerPc = $entity->get(PlatformerController::class);
            $playerTf = $entity->get(Transform3D::class);
            break;
        }
        if ($playerPc === null || $playerTf === null) {
            return;
        }
        $state = $this->findState($world);

        $pPos = $playerTf->position;
        $pHalfY = $playerPc->halfExtents->y;

        /** @var list<int> $despawn entities whose squash has finished */
        $despawn = [];
        /** @var list<int> $freeze entities stomped this frame, to stop moving */
        $freeze = [];

        foreach ($world->query(Stompable::class, Transform3D::class) as $entity) {
            $st = $entity->get(Stompable::class);
            $tf = $entity->get(Transform3D::class);

            if (!$st->alive) {
                // Linger squashed for squashFrames, then despawn. (The original
                // set this timer on a stomp but never acted on it — the corpse
                // stayed forever; we complete the intent.)
                if ($st->squashTimer > 0) {
                    $st->squashTimer--;
                } else {
                    $despawn[] = $entity->id;
                }
                continue;
            }

            $e = $tf->position;
            $dx = $pPos->x - $e->x;
            $dz = $pPos->z - $e->z;
            if (sqrt($dx * $dx + $dz * $dz) >= $st->contactRadius) {
                continue;
            }

            $enemyTop = $e->y + $st->bodyHeight;
            $enemyMid = $e->y + $st->stompHeight;
            $playerFeet = $pPos->y - $pHalfY;
            if ($playerFeet >= $enemyTop || $pPos->y + $pHalfY <= $e->y) {
                continue;
            }

            if ($playerPc->velocity->y < 0.0 && $playerFeet > $enemyMid) {
                // Stomp: squash the enemy and bounce the player.
                $st->alive = false;
                $st->squashTimer = $st->squashFrames;
                $tf->scale = new Vec3($tf->scale->x, $tf->scale->y * $st->squashScale, $tf->scale->z);
                $freeze[] = $entity->id; // freeze the corpse (no patrol / bob) while it lingers
                $playerPc->velocity = new Vec3(
                    $playerPc->velocity->x,
                    $st->bounceVelocity,
                    $playerPc->velocity->z,
                );
                if ($state !== null) {
                    $state->score += $st->score;
                }
            } elseif ($playerPc->invuln <= 0) {
                PlatformerControllerSystem::applyDamage($playerPc, $playerTf, $state);
            }
        }

        // Structural changes deferred until after iteration. Freezing detaches
        // Patrol + SpinBob so the squashed corpse holds still; despawn removes
        // it (cascading to its child meshes) once the squash timer elapses.
        foreach ($freeze as $id) {
            $world->detachComponent($id, Patrol::class);
            $world->detachComponent($id, SpinBob::class);
        }
        foreach ($despawn as $id) {
            $world->destroyEntity($id);
        }
    }

    private function findState(World $world): ?PlatformerGameState
    {
        foreach ($world->query(PlatformerGameState::class) as $entity) {
            return $entity->get(PlatformerGameState::class);
        }
        return null;
    }
}
