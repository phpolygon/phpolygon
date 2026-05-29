<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\PlatformerController;
use PHPolygon\Component\PlatformerGameState;
use PHPolygon\Component\PlatformerStatus;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Runtime\InputInterface;

/**
 * Arcade platformer movement for every {@see PlatformerController} entity:
 * input → ground/air acceleration + friction + capped run speed, a
 * variable-height jump, capped gravity, and per-axis AABB resolution against
 * the scene's {@see BoxCollider3D} solids (the engine's standard collider —
 * reused here rather than the capsule {@see Physics3DSystem}, so the classic
 * frame-based feel is preserved exactly).
 *
 * Falling below {@see PlatformerController::$killPlaneY} respawns the player at
 * the last grounded position and, when a {@see PlatformerGameState} exists,
 * costs a life (game over at zero).
 *
 * Runs in the update (fixed-timestep) phase, before {@see FollowCameraSystem}.
 */
class PlatformerControllerSystem extends AbstractSystem
{
    /**
     * How far inside a platform's edge a respawn point is pulled, on top of the
     * body's half-extents. Keeps the player clear of the lip so a fall doesn't
     * respawn them on the brink (the death spiral).
     */
    private const RESPAWN_EDGE_MARGIN = 0.75;

    /** Default key bindings (GLFW codes): WASD + arrows, space to jump. */
    private const KEY_LEFT    = [65, 263];  // A, Left
    private const KEY_RIGHT   = [68, 262];  // D, Right
    private const KEY_FORWARD = [87, 265];  // W, Up
    private const KEY_BACK    = [83, 264];  // S, Down
    private const KEY_JUMP    = [32];       // Space

    public function __construct(private readonly InputInterface $input) {}

    public function update(World $world, float $dt): void
    {
        $state = $this->findState($world);
        if ($state !== null && $state->status !== PlatformerStatus::Playing) {
            return; // Freeze the player on win / game-over.
        }

        $solids = $this->collectSolids($world);

        $left    = $this->anyDown(self::KEY_LEFT);
        $right   = $this->anyDown(self::KEY_RIGHT);
        $forward = $this->anyDown(self::KEY_FORWARD);
        $back    = $this->anyDown(self::KEY_BACK);
        $jump    = $this->anyDown(self::KEY_JUMP);

        foreach ($world->query(PlatformerController::class, Transform3D::class) as $entity) {
            $pc = $entity->get(PlatformerController::class);
            $tf = $entity->get(Transform3D::class);
            $this->step($pc, $tf, $solids, $left, $right, $forward, $back, $jump, $state);
        }
    }

    /**
     * @param list<array{min: Vec3, max: Vec3}> $solids
     */
    private function step(
        PlatformerController $pc,
        Transform3D $tf,
        array $solids,
        bool $left,
        bool $right,
        bool $forward,
        bool $back,
        bool $jump,
        ?PlatformerGameState $state,
    ): void {
        $vel = $pc->velocity;
        $pos = $tf->position;

        // --- input → horizontal acceleration ---
        $ax = ($left ? -1.0 : 0.0) + ($right ? 1.0 : 0.0);
        $az = ($forward ? -1.0 : 0.0) + ($back ? 1.0 : 0.0);
        if ($ax !== 0.0 || $az !== 0.0) {
            $len = sqrt($ax * $ax + $az * $az);
            $ax /= $len;
            $az /= $len;
        }

        $ac = $pc->onGround ? $pc->moveAccel : $pc->airAccel;
        $vx = $vel->x + $ax * $ac;
        $vz = $vel->z + $az * $ac;
        if ($ax === 0.0 && $pc->onGround) { $vx *= $pc->friction; }
        if ($az === 0.0 && $pc->onGround) { $vz *= $pc->friction; }

        $sp = sqrt($vx * $vx + $vz * $vz);
        if ($sp > $pc->maxSpeed && $sp > 0.0) {
            $vx *= $pc->maxSpeed / $sp;
            $vz *= $pc->maxSpeed / $sp;
        }
        if (abs($vx) < 0.002) { $vx = 0.0; }
        if (abs($vz) < 0.002) { $vz = 0.0; }

        // --- jump (variable height) + gravity ---
        $vy = $vel->y;
        if ($jump && $pc->onGround && !$pc->jumpHeld) {
            $vy = $pc->jumpVelocity;
            $pc->onGround = false;
            $pc->jumpHeld = true;
        }
        if (!$jump) { $pc->jumpHeld = false; }
        if (!$jump && $vy > 0.0) { $vy *= $pc->jumpCutFactor; }
        $vy = max($vy - $pc->gravity, -$pc->maxFall);

        // --- integrate + resolve per axis (Y, then X, then Z) ---
        $half = $pc->halfExtents;
        $pc->onGround = false;

        $groundBox = null;
        $pos = new Vec3($pos->x, $pos->y + $vy, $pos->z);
        foreach ($solids as $b) {
            if ($this->overlap($pos, $half, $b)) {
                if ($vy <= 0.0) {
                    $pos = new Vec3($pos->x, $b['max']->y + $half->y, $pos->z);
                    $pc->onGround = true;
                    $groundBox = $b; // remember the platform we landed on (for a safe respawn)
                } else {
                    $pos = new Vec3($pos->x, $b['min']->y - $half->y, $pos->z);
                }
                $vy = 0.0;
            }
        }

        $pos = new Vec3($pos->x + $vx, $pos->y, $pos->z);
        foreach ($solids as $b) {
            if ($this->overlap($pos, $half, $b)) {
                if ($vx > 0.0)      { $pos = new Vec3($b['min']->x - $half->x, $pos->y, $pos->z); }
                elseif ($vx < 0.0)  { $pos = new Vec3($b['max']->x + $half->x, $pos->y, $pos->z); }
                $vx = 0.0;
            }
        }

        $pos = new Vec3($pos->x, $pos->y, $pos->z + $vz);
        foreach ($solids as $b) {
            if ($this->overlap($pos, $half, $b)) {
                if ($vz > 0.0)      { $pos = new Vec3($pos->x, $pos->y, $b['min']->z - $half->z); }
                elseif ($vz < 0.0)  { $pos = new Vec3($pos->x, $pos->y, $b['max']->z + $half->z); }
                $vz = 0.0;
            }
        }

        $pc->velocity = new Vec3($vx, $vy, $vz);
        $tf->position = $pos;

        // --- grounded bookkeeping: safe spot + stride animation ---
        if ($pc->onGround) {
            // Respawn at a point pulled away from the platform lip, not at the
            // exact spot we were grounded: standing on the brink and falling
            // would otherwise respawn us right back at the brink, where the
            // next step drops us off again — an inescapable death spiral.
            $pc->lastSafe = $groundBox !== null ? $this->safeSpot($pos, $half, $groundBox) : $pos;
            if ($sp > 0.02) { $pc->animPhase += $sp * 1.2; }
        }
        if ($pc->invuln > 0) { $pc->invuln--; }

        // --- facing: ease yaw toward travel direction ---
        if ($sp > 0.02) {
            $target = atan2($vx, $vz);
            $diff = $target - $pc->facing;
            while ($diff > M_PI)  { $diff -= 2.0 * M_PI; }
            while ($diff < -M_PI) { $diff += 2.0 * M_PI; }
            $pc->facing += $diff * 0.3;
            $tf->rotation = Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), $pc->facing);
        }

        // --- fall into the void ---
        if ($pos->y < $pc->killPlaneY) {
            self::applyDamage($pc, $tf, $state);
        }
    }

    /**
     * Cost a life and respawn at the last grounded spot (or end the run at
     * zero lives). Shared by the kill-plane here and by enemy contact in
     * {@see StompSystem}, so the death rules stay in one place.
     */
    public static function applyDamage(PlatformerController $pc, Transform3D $tf, ?PlatformerGameState $state): void
    {
        if ($state !== null) {
            $state->lives--;
            $state->score = max(0, $state->score - $state->deathPenalty);
            if ($state->lives <= 0) {
                $state->status = PlatformerStatus::Lost;
                // Guard against same-frame re-entry: StompSystem (and any future
                // damage source) iterates the rest of its query and would call
                // applyDamage() again with invuln still 0, driving lives below
                // zero. Setting invuln here makes the elseif (invuln <= 0)
                // checks short-circuit for the remainder of this frame.
                $pc->invuln = $pc->invulnFrames;
                return;
            }
        }
        $tf->position = new Vec3($pc->lastSafe->x, $pc->lastSafe->y + 0.5, $pc->lastSafe->z);
        $pc->velocity = Vec3::zero();
        $pc->invuln = $pc->invulnFrames;
    }

    /**
     * Clamp a grounded position to lie within the supporting platform, inset by
     * the body half-extents plus {@see self::RESPAWN_EDGE_MARGIN}, so it makes a
     * safe respawn point. Platforms too small to hold the inset collapse to the
     * platform centre.
     *
     * @param array{min: Vec3, max: Vec3} $box
     */
    private function safeSpot(Vec3 $pos, Vec3 $half, array $box): Vec3
    {
        $m = self::RESPAWN_EDGE_MARGIN;
        $loX = $box['min']->x + $half->x + $m;
        $hiX = $box['max']->x - $half->x - $m;
        $loZ = $box['min']->z + $half->z + $m;
        $hiZ = $box['max']->z - $half->z - $m;

        $x = $loX <= $hiX ? max($loX, min($hiX, $pos->x)) : ($box['min']->x + $box['max']->x) / 2.0;
        $z = $loZ <= $hiZ ? max($loZ, min($hiZ, $pos->z)) : ($box['min']->z + $box['max']->z) / 2.0;

        return new Vec3($x, $pos->y, $z);
    }

    /**
     * @param array{min: Vec3, max: Vec3} $b
     */
    private function overlap(Vec3 $pos, Vec3 $half, array $b): bool
    {
        return $pos->x + $half->x > $b['min']->x && $pos->x - $half->x < $b['max']->x
            && $pos->y + $half->y > $b['min']->y && $pos->y - $half->y < $b['max']->y
            && $pos->z + $half->z > $b['min']->z && $pos->z - $half->z < $b['max']->z;
    }

    /**
     * @return list<array{min: Vec3, max: Vec3}>
     */
    private function collectSolids(World $world): array
    {
        $solids = [];
        foreach ($world->query(BoxCollider3D::class, Transform3D::class) as $entity) {
            $box = $entity->get(BoxCollider3D::class);
            if ($box->isTrigger) {
                continue;
            }
            $tf = $entity->get(Transform3D::class);
            $solids[] = $box->getWorldAABB($tf->getWorldMatrix());
        }
        return $solids;
    }

    private function findState(World $world): ?PlatformerGameState
    {
        foreach ($world->query(PlatformerGameState::class) as $entity) {
            return $entity->get(PlatformerGameState::class);
        }
        return null;
    }

    /** @param list<int> $keys */
    private function anyDown(array $keys): bool
    {
        foreach ($keys as $k) {
            if ($this->input->isKeyDown($k)) {
                return true;
            }
        }
        return false;
    }
}
