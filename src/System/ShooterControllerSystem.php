<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\ShooterController;
use PHPolygon\Component\ShooterGameState;
use PHPolygon\Component\ShooterMovement;
use PHPolygon\Component\ShooterStatus;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Weapon;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\Runtime\Window;

/**
 * Drives the player {@see ShooterController}: planar strafing within bounds
 * (arcade / rail) or mouse-look + WASD (first-person), and sets the owner
 * {@see Weapon}'s {@see Weapon::$firing} intent and {@see Weapon::$aim} each
 * tick. Movement is in per-tick units. Freezes while the
 * {@see ShooterGameState} is not {@see ShooterStatus::Playing}.
 */
class ShooterControllerSystem extends AbstractSystem
{
    private const KEY_LEFT    = [65, 263]; // A, Left
    private const KEY_RIGHT   = [68, 262]; // D, Right
    private const KEY_FORWARD = [87, 265]; // W, Up
    private const KEY_BACK    = [83, 264]; // S, Down
    private const KEY_FIRE    = [32];      // Space
    private const MOUSE_LEFT  = 0;         // GLFW_MOUSE_BUTTON_LEFT

    private bool $captured = false;
    private bool $firstMouse = true;
    private float $lastMouseX = 0.0;
    private float $lastMouseY = 0.0;

    public function __construct(
        private readonly InputInterface $input,
        private readonly ?Window $window = null,
    ) {}

    public function update(World $world, float $dt): void
    {
        $state = $this->findState($world);
        $frozen = $state !== null && $state->status !== ShooterStatus::Playing;

        foreach ($world->query(ShooterController::class, Transform3D::class) as $entity) {
            $sc = $entity->get(ShooterController::class);
            $tf = $entity->get(Transform3D::class);
            $weapon = $entity->tryGet(Weapon::class);

            if ($frozen) {
                if ($weapon !== null) {
                    $weapon->firing = false;
                }
                continue;
            }

            $aim = $sc->mode === ShooterMovement::FirstPerson
                ? $this->stepFirstPerson($sc, $tf, $entity->tryGet(Camera3DComponent::class))
                : $this->stepPlanar($sc, $tf);

            if ($weapon !== null) {
                $weapon->aim = $aim;
                $weapon->firing = $this->firePressed();
            }
        }
    }

    /** Strafe on X/Y, clamped to the controller bounds; aim straight down -Z. */
    private function stepPlanar(ShooterController $sc, Transform3D $tf): Vec3
    {
        $ax = ($this->anyDown(self::KEY_RIGHT) ? 1.0 : 0.0) + ($this->anyDown(self::KEY_LEFT) ? -1.0 : 0.0);
        $ay = ($this->anyDown(self::KEY_FORWARD) ? 1.0 : 0.0) + ($this->anyDown(self::KEY_BACK) ? -1.0 : 0.0);

        $p = $tf->position;
        $x = $this->clamp($p->x + $ax * $sc->moveSpeed, $sc->boundsMin->x, $sc->boundsMax->x);
        $y = $this->clamp($p->y + $ay * $sc->moveSpeed, $sc->boundsMin->y, $sc->boundsMax->y);
        $tf->position = new Vec3($x, $y, $p->z);

        return new Vec3(0.0, 0.0, -1.0);
    }

    /** Mouse-look + WASD on the ground plane; aim follows the look direction. */
    private function stepFirstPerson(ShooterController $sc, Transform3D $tf, ?Camera3DComponent $cam): Vec3
    {
        if (!$this->captured && $this->window !== null) {
            $this->window->setCursorDisabled();
            $this->captured = true;
            $this->firstMouse = true;
        }

        $mx = $this->input->getMouseX();
        $my = $this->input->getMouseY();
        if ($this->firstMouse) {
            $this->lastMouseX = $mx;
            $this->lastMouseY = $my;
            $this->firstMouse = false;
        }
        $dx = $mx - $this->lastMouseX;
        $dy = $my - $this->lastMouseY;
        $this->lastMouseX = $mx;
        $this->lastMouseY = $my;

        $sc->yaw -= $dx * $sc->sensitivity;
        $sc->pitch -= $dy * $sc->sensitivity;
        $limit = M_PI / 2 * 0.95;
        $sc->pitch = max(-$limit, min($limit, $sc->pitch));

        $rot = Quaternion::fromEuler($sc->pitch, $sc->yaw, 0.0);
        $tf->rotation = $rot;

        $forward = $rot->rotateVec3(new Vec3(0.0, 0.0, -1.0));
        $right = $rot->rotateVec3(new Vec3(1.0, 0.0, 0.0));

        // Flatten to the ground plane for movement.
        $fwd = $this->flatNorm($forward->x, $forward->z);
        $rgt = $this->flatNorm($right->x, $right->z);

        $mz = ($this->anyDown(self::KEY_FORWARD) ? 1.0 : 0.0) + ($this->anyDown(self::KEY_BACK) ? -1.0 : 0.0);
        $mx2 = ($this->anyDown(self::KEY_RIGHT) ? 1.0 : 0.0) + ($this->anyDown(self::KEY_LEFT) ? -1.0 : 0.0);

        $vx = $fwd[0] * $mz + $rgt[0] * $mx2;
        $vz = $fwd[1] * $mz + $rgt[1] * $mx2;
        $len = sqrt($vx * $vx + $vz * $vz);
        if ($len > 1e-6) {
            $vx = $vx / $len * $sc->moveSpeed;
            $vz = $vz / $len * $sc->moveSpeed;
            $p = $tf->position;
            $tf->position = new Vec3($p->x + $vx, $p->y, $p->z + $vz);
        }

        if ($cam !== null) {
            $cam->eyeOffset = new Vec3(0.0, $sc->eyeHeight, 0.0);
        }

        return $forward;
    }

    private function firePressed(): bool
    {
        if ($this->input->isMouseButtonDown(self::MOUSE_LEFT)) {
            return true;
        }
        return $this->anyDown(self::KEY_FIRE);
    }

    /** @return array{0: float, 1: float} unit (x,z) on the ground plane */
    private function flatNorm(float $x, float $z): array
    {
        $len = sqrt($x * $x + $z * $z);
        return $len > 1e-6 ? [$x / $len, $z / $len] : [0.0, 0.0];
    }

    private function clamp(float $v, float $lo, float $hi): float
    {
        if ($lo > $hi) {
            return $v;
        }
        return max($lo, min($hi, $v));
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

    private function findState(World $world): ?ShooterGameState
    {
        foreach ($world->query(ShooterGameState::class) as $entity) {
            return $entity->get(ShooterGameState::class);
        }
        return null;
    }
}
