<?php

declare(strict_types=1);

namespace PHPolygon\Thread\Subsystem;

/**
 * Pure-math physics solver. No ECS dependency — operates on plain arrays.
 * Used by both threaded PhysicsSubsystem and the NullThreadScheduler fallback.
 *
 * Input format:
 *   'dt' => float,
 *   'gravity' => [float, float],
 *   'bodies' => [entityId => ['x','y','vx','vy','mass','drag','gravityScale','isKinematic','restitution','fixedRotation','angularVelocity','angularDrag','rotation', 'ax','ay'], ...]
 *   'colliders' => [entityId => ['ox','oy','w','h','isTrigger'], ...]
 *
 * Output format:
 *   'positions' => [entityId => [float, float], ...]
 *   'velocities' => [entityId => [float, float], ...]
 *   'rotations' => [entityId => float, ...]
 *   'angularVelocities' => [entityId => float, ...]
 *   'collisions' => [['a' => int, 'b' => int, 'nx' => float, 'ny' => float, 'pen' => float, 'isTrigger' => bool], ...]
 */
final class PhysicsSolver
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function simulate(array $input): array
    {
        /** @var float $dt */
        $dt = $input['dt'];
        /** @var array{0: float, 1: float} $gravity */
        $gravity = $input['gravity'];
        /** @var array<int, array<string, float|bool>> $bodies */
        $bodies = $input['bodies'];
        /** @var array<int, array<string, float|bool>> $colliders */
        $colliders = $input['colliders'] ?? [];

        $positions = [];
        $velocities = [];
        $rotations = [];
        $angularVelocities = [];

        // Integration
        foreach ($bodies as $id => $b) {
            if ($b['isKinematic']) {
                $positions[$id] = [(float) $b['x'], (float) $b['y']];
                $velocities[$id] = [(float) $b['vx'], (float) $b['vy']];
                $rotations[$id] = (float) $b['rotation'];
                $angularVelocities[$id] = (float) $b['angularVelocity'];
                continue;
            }

            $gScale = (float) $b['gravityScale'];
            $ax = (float) $b['ax'] + $gravity[0] * $gScale;
            $ay = (float) $b['ay'] + $gravity[1] * $gScale;

            $vx = (float) $b['vx'] + $ax * $dt;
            $vy = (float) $b['vy'] + $ay * $dt;

            $drag = (float) $b['drag'];
            if ($drag > 0) {
                $factor = 1.0 - $drag * $dt;
                $vx *= $factor;
                $vy *= $factor;
            }

            $x = (float) $b['x'] + $vx * $dt;
            $y = (float) $b['y'] + $vy * $dt;

            $rotation = (float) $b['rotation'];
            $angVel = (float) $b['angularVelocity'];
            if (!$b['fixedRotation']) {
                $angVel *= (1.0 - (float) $b['angularDrag'] * $dt);
                $rotation += $angVel * $dt;
            }

            $positions[$id] = [$x, $y];
            $velocities[$id] = [$vx, $vy];
            $rotations[$id] = $rotation;
            $angularVelocities[$id] = $angVel;
        }

        // Collision detection
        $collisions = [];
        $colliderIds = array_keys($colliders);
        $count = count($colliderIds);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $idA = $colliderIds[$i];
                $idB = $colliderIds[$j];
                $cA = $colliders[$idA];
                $cB = $colliders[$idB];

                // Build world rects from updated positions
                /** @var array{0: float, 1: float} $posA */
                $posA = $positions[$idA] ?? [(float) ($bodies[$idA]['x'] ?? 0), (float) ($bodies[$idA]['y'] ?? 0)];
                /** @var array{0: float, 1: float} $posB */
                $posB = $positions[$idB] ?? [(float) ($bodies[$idB]['x'] ?? 0), (float) ($bodies[$idB]['y'] ?? 0)];

                $rectA = self::worldRect($posA, $cA);
                $rectB = self::worldRect($posB, $cB);

                $collision = self::aabbCollision($idA, $idB, $rectA, $rectB);
                if ($collision === null) {
                    continue;
                }

                $collision['isTrigger'] = (bool) ($cA['isTrigger'] ?? false) || (bool) ($cB['isTrigger'] ?? false);

                if (!$collision['isTrigger']) {
                    self::resolveCollision(
                        $idA, $idB, $collision,
                        $bodies, $positions, $velocities
                    );
                }

                $collisions[] = $collision;
            }
        }

        return [
            'positions' => $positions,
            'velocities' => $velocities,
            'rotations' => $rotations,
            'angularVelocities' => $angularVelocities,
            'collisions' => $collisions,
        ];
    }

    /**
     * @param array{0: float, 1: float} $pos
     * @param array<string, float|bool> $collider
     * @return array{left: float, top: float, right: float, bottom: float}
     */
    private static function worldRect(array $pos, array $collider): array
    {
        $ox = (float) ($collider['ox'] ?? 0);
        $oy = (float) ($collider['oy'] ?? 0);
        $w = (float) ($collider['w'] ?? 0);
        $h = (float) ($collider['h'] ?? 0);

        return [
            'left' => $pos[0] + $ox,
            'top' => $pos[1] + $oy,
            'right' => $pos[0] + $ox + $w,
            'bottom' => $pos[1] + $oy + $h,
        ];
    }

    /**
     * @param array{left: float, top: float, right: float, bottom: float} $a
     * @param array{left: float, top: float, right: float, bottom: float} $b
     * @return array{a: int, b: int, nx: float, ny: float, pen: float}|null
     */
    private static function aabbCollision(int $idA, int $idB, array $a, array $b): ?array
    {
        $overlapX = min($a['right'], $b['right']) - max($a['left'], $b['left']);
        $overlapY = min($a['bottom'], $b['bottom']) - max($a['top'], $b['top']);

        if ($overlapX <= 0 || $overlapY <= 0) {
            return null;
        }

        $centerAx = ($a['left'] + $a['right']) * 0.5;
        $centerAy = ($a['top'] + $a['bottom']) * 0.5;
        $centerBx = ($b['left'] + $b['right']) * 0.5;
        $centerBy = ($b['top'] + $b['bottom']) * 0.5;

        if ($overlapX < $overlapY) {
            $nx = $centerAx < $centerBx ? -1.0 : 1.0;
            $ny = 0.0;
            $pen = $overlapX;
        } else {
            $nx = 0.0;
            $ny = $centerAy < $centerBy ? -1.0 : 1.0;
            $pen = $overlapY;
        }

        return ['a' => $idA, 'b' => $idB, 'nx' => $nx, 'ny' => $ny, 'pen' => $pen];
    }

    /**
     * @param array{a: int, b: int, nx: float, ny: float, pen: float} $collision
     * @param array<int, array<string, float|bool>> $bodies
     * @param array<int, array<int, float>> $positions
     * @param array<int, array<int, float>> $velocities
     * @param-out array<int, array<int, float>> $positions
     * @param-out array<int, array<int, float>> $velocities
     */
    private static function resolveCollision(
        int $idA,
        int $idB,
        array $collision,
        array $bodies,
        array &$positions,
        array &$velocities,
    ): void {
        $bA = $bodies[$idA] ?? null;
        $bB = $bodies[$idB] ?? null;

        $aKinematic = $bA === null || (bool) ($bA['isKinematic'] ?? true);
        $bKinematic = $bB === null || (bool) ($bB['isKinematic'] ?? true);

        if ($aKinematic && $bKinematic) {
            return;
        }

        $nx = $collision['nx'];
        $ny = $collision['ny'];
        $pen = $collision['pen'];

        // Position correction
        if ($aKinematic) {
            $positions[$idB][0] -= $nx * $pen;
            $positions[$idB][1] -= $ny * $pen;
        } elseif ($bKinematic) {
            $positions[$idA][0] += $nx * $pen;
            $positions[$idA][1] += $ny * $pen;
        } else {
            $half = $pen * 0.5;
            $positions[$idA][0] += $nx * $half;
            $positions[$idA][1] += $ny * $half;
            $positions[$idB][0] -= $nx * $half;
            $positions[$idB][1] -= $ny * $half;
        }

        // Velocity response
        $vAx = $velocities[$idA][0] ?? 0.0;
        $vAy = $velocities[$idA][1] ?? 0.0;
        $vBx = $velocities[$idB][0] ?? 0.0;
        $vBy = $velocities[$idB][1] ?? 0.0;

        $relVx = $vAx - $vBx;
        $relVy = $vAy - $vBy;
        $velAlongNormal = $relVx * $nx + $relVy * $ny;

        if ($velAlongNormal > 0) {
            return;
        }

        $restitution = max(
            (float) ($bA['restitution'] ?? 0),
            (float) ($bB['restitution'] ?? 0),
        );

        $invMassA = $aKinematic ? 0.0 : 1.0 / (float) ($bA['mass'] ?? 1.0);
        $invMassB = $bKinematic ? 0.0 : 1.0 / (float) ($bB['mass'] ?? 1.0);

        $j = -(1.0 + $restitution) * $velAlongNormal / ($invMassA + $invMassB);

        if (!$aKinematic) {
            $velocities[$idA][0] += $nx * $j * $invMassA;
            $velocities[$idA][1] += $ny * $j * $invMassA;
        }
        if (!$bKinematic) {
            $velocities[$idB][0] -= $nx * $j * $invMassB;
            $velocities[$idB][1] -= $ny * $j * $invMassB;
        }
    }
}
