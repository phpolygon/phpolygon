<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\InteriorVolume;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ScreenProjection;

/**
 * Hands the {@see InteriorVolume}s nearest to the camera to the mesh shader's interior
 * light mask (u_interior_box_k / u_interior_ext_k, {@see SLOTS} of them; unused slots
 * get strength 0). Reads SetCamera: register after {@see Camera3DSystem} and before
 * {@see Renderer3DSystem}. Sole writer of those uniforms.
 */
class InteriorVolumeSystem extends AbstractSystem
{
    public const int SLOTS = 4;

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
     * The uniform values for an eye at $eye, nearest volume first.
     *
     * @return array<string, list<float>>
     */
    public static function uniforms(World $world, Vec3 $eye): array
    {
        $found = [];
        foreach ($world->query(InteriorVolume::class, Transform3D::class) as $entity) {
            $volume = $entity->get(InteriorVolume::class);
            if ($volume->strength <= 0.0) {
                continue;
            }
            $transform = $entity->get(Transform3D::class);
            $c = $transform->getWorldPosition();
            $q = $transform->rotation;
            // Yaw about +Y from the rotation quaternion.
            $yaw = atan2(2.0 * ($q->w * $q->y + $q->x * $q->z), 1.0 - 2.0 * ($q->y * $q->y + $q->z * $q->z));
            $h = $volume->halfExtents;
            $r = max($h->x, $h->z);
            $dx = max(0.0, abs($eye->x - $c->x) - $r);
            $dy = max(0.0, abs($eye->y - $c->y) - $h->y);
            $dz = max(0.0, abs($eye->z - $c->z) - $r);
            $found[] = [$dx * $dx + $dy * $dy + $dz * $dz, $entity->id, [$c->x, $c->y, $c->z, $yaw], [$h->x, $h->y, $h->z, min(1.0, $volume->strength)]];
        }
        usort($found, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $out = [];
        for ($k = 0; $k < self::SLOTS; $k++) {
            $f = $found[$k] ?? null;
            $out['u_interior_box_' . $k] = $f[2] ?? [0.0, 0.0, 0.0, 0.0];
            $out['u_interior_ext_' . $k] = $f[3] ?? [0.0, 0.0, 0.0, 0.0];
        }

        return $out;
    }
}
