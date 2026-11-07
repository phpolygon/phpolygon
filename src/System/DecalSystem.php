<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\DecalVolume;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ScreenProjection;

/**
 * Hands the {@see DecalVolume}s nearest to the camera (up to {@see SLOTS}, within
 * {@see MAX_DISTANCE}) to the mesh shader: u_decal_count and u_decals[3 * k + 0..2]
 * = (centre, yaw), (half extents, strength), (colour, kind). Reads SetCamera:
 * register after Camera3DSystem and before Renderer3DSystem.
 */
class DecalSystem extends AbstractSystem
{
    public const int SLOTS = 8;
    public const float MAX_DISTANCE = 120.0;

    public function __construct(private readonly RenderCommandList $commandList) {}

    public function update(World $world, float $dt): void
    {
        // Render-only.
    }

    public function render(World $world): void
    {
        $cameras = $this->commandList->ofType(SetCamera::class);
        $eye = $cameras !== [] ? ScreenProjection::cameraPosition($cameras[0]->viewMatrix) : new Vec3(0.0, 0.0, 0.0);
        foreach (self::uniforms($world, $eye) as $name => $value) {
            $this->commandList->add(new SetShaderParameter($name, $value));
        }
    }

    /**
     * @return array<string, int|list<float>>
     */
    public static function uniforms(World $world, Vec3 $eye): array
    {
        $found = [];
        foreach ($world->query(DecalVolume::class, Transform3D::class) as $entity) {
            $decal = $entity->get(DecalVolume::class);
            if ($decal->strength <= 0.0) {
                continue;
            }
            if ($decal->followsLight && (!$entity->has(PointLight::class) || $entity->get(PointLight::class)->intensity <= 0.001)) {
                continue;
            }
            $transform = $entity->get(Transform3D::class);
            $c = $transform->getWorldPosition();
            $h = $decal->halfExtents;
            $dist = max(0.0, $c->sub($eye)->length() - max($h->x, $h->y, $h->z));
            if ($dist > self::MAX_DISTANCE) {
                continue;
            }
            $q = $transform->rotation;
            $yaw = atan2(2.0 * ($q->w * $q->y + $q->x * $q->z), 1.0 - 2.0 * ($q->y * $q->y + $q->z * $q->z));
            $found[] = [$dist, $entity->id, [
                [$c->x, $c->y, $c->z, $yaw],
                [$h->x, $h->y, $h->z, min(1.0, $decal->strength)],
                [$decal->color->r, $decal->color->g, $decal->color->b, (float) $decal->kind],
            ]];
        }
        usort($found, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $found = array_slice($found, 0, self::SLOTS);
        $out = ['u_decal_count' => count($found)];
        for ($k = 0; $k < self::SLOTS; $k++) {
            foreach ([0, 1, 2] as $j) {
                $out['u_decals[' . ($k * 3 + $j) . ']'] = $found[$k][2][$j] ?? [0.0, 0.0, 0.0, 0.0];
            }
        }

        return $out;
    }
}
