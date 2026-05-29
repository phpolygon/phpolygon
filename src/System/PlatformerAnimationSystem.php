<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PlatformerController;
use PHPolygon\Component\PlatformerGameState;
use PHPolygon\Component\PlatformerLegSegment;
use PHPolygon\Component\PlatformerStatus;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * Procedural character animation for a {@see PlatformerController}, ported from
 * the original JSX render loop (which the data-driven importer can't capture):
 *
 *  - **Run cycle** — every {@see PlatformerLegSegment} swings about its hip
 *    pivot by `sin(animPhase) * min(1, speed/maxSpeed) * 0.7`, alternating per
 *    leg via {@see PlatformerLegSegment::$swingSign}.
 *  - **Jump pose** — while airborne the legs hold a fixed tuck (∓0.5 rad).
 *  - **Invulnerability blink** — after a hit the whole character flickers by
 *    toggling its meshes' {@see MeshRenderer::$visible}.
 *
 * Must run before {@see Transform3DSystem} (it writes leg local transforms that
 * the hierarchy pass then bakes into world matrices).
 */
class PlatformerAnimationSystem extends AbstractSystem
{
    /** Peak leg swing amplitude (radians) at full running speed. */
    private const SWING_AMPLITUDE = 0.7;

    /** Fixed leg tuck (radians) applied while airborne. */
    private const JUMP_POSE = 0.5;

    /** Blink toggles every this many invulnerability frames. */
    private const BLINK_PERIOD = 5;

    private readonly Vec3 $axisX;

    public function __construct()
    {
        $this->axisX = new Vec3(1.0, 0.0, 0.0);
    }

    public function update(World $world, float $dt): void
    {
        $pc = null;
        $playerId = null;
        foreach ($world->query(PlatformerController::class, Transform3D::class) as $entity) {
            $pc = $entity->get(PlatformerController::class);
            $playerId = $entity->id;
            break;
        }
        // $pc and $playerId are set together in the loop above. PHPStan narrows
        // $playerId to int through the assignment, so checking $pc is enough to
        // cover the "no player entity at all" case.
        if ($pc === null) {
            return;
        }

        $speed = sqrt($pc->velocity->x * $pc->velocity->x + $pc->velocity->z * $pc->velocity->z);
        $intensity = $pc->maxSpeed > 0.0 ? min(1.0, $speed / $pc->maxSpeed) : 0.0;
        $swing = sin($pc->animPhase) * $intensity * self::SWING_AMPLITUDE;

        foreach ($world->query(PlatformerLegSegment::class, Transform3D::class) as $entity) {
            $seg = $entity->get(PlatformerLegSegment::class);
            $tf = $entity->get(Transform3D::class);

            $angle = $pc->onGround
                ? $seg->swingSign * $swing
                : -self::JUMP_POSE * $seg->swingSign;

            $rot = Quaternion::fromAxisAngle($this->axisX, $angle);

            // Orbit the rest position about the shared hip pivot, then tilt the
            // mesh by the same rotation — together this reproduces rotating a
            // hip group that the meshes used to be parented under.
            $offset = new Vec3(
                $seg->restPosition->x - $seg->pivot->x,
                $seg->restPosition->y - $seg->pivot->y,
                $seg->restPosition->z - $seg->pivot->z,
            );
            $rotated = $rot->rotateVec3($offset);
            $tf->position = new Vec3(
                $seg->pivot->x + $rotated->x,
                $seg->pivot->y + $rotated->y,
                $seg->pivot->z + $rotated->z,
            );
            $tf->rotation = $rot->multiply($seg->restRotation);
        }

        // Invulnerability blink: flicker the whole character on/off — but never
        // on the win / game-over screen. The controller short-circuits on
        // !Playing, so invuln stops decrementing and could otherwise leave the
        // rig frozen in a hidden blink bucket (visually killing the GAME OVER
        // banner's player silhouette).
        $state = $this->findState($world);
        $gameOver = $state !== null && $state->status !== PlatformerStatus::Playing;
        $invuln = max(0, $pc->invuln);
        $visible = $gameOver || $invuln <= 0 || intdiv($invuln, self::BLINK_PERIOD) % 2 === 0;
        $this->setVisibleRecursive($world, $playerId, $visible);
    }

    private function findState(World $world): ?PlatformerGameState
    {
        foreach ($world->query(PlatformerGameState::class) as $entity) {
            return $entity->get(PlatformerGameState::class);
        }
        return null;
    }

    private function setVisibleRecursive(World $world, int $entityId, bool $visible): void
    {
        $mesh = $world->tryGetComponent($entityId, MeshRenderer::class);
        if ($mesh instanceof MeshRenderer) {
            $mesh->visible = $visible;
        }
        $tf = $world->tryGetComponent($entityId, Transform3D::class);
        if (!$tf instanceof Transform3D) {
            return;
        }
        foreach ($tf->childEntityIds as $childId) {
            if ($world->isAlive($childId)) {
                $this->setVisibleRecursive($world, $childId, $visible);
            }
        }
    }
}
