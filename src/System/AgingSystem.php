<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\AgingVolume;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ScreenProjection;

/**
 * Hands the {@see AgingVolume}s nearest to the camera to the mesh shader's weathering
 * (shader plan 5.2): u_age_zone_count, u_age_zones[2 * k + 0..1] = (centre, yaw),
 * (half extents, age), and u_age_params = (north x, north z, waterline y, edge)
 * - moss grows on the side facing north; with a waterline, vertical surfaces
 * just above it get a salt crust and just below it algae. Reads SetCamera:
 * register after Camera3DSystem and before Renderer3DSystem.
 */
class AgingSystem extends AbstractSystem
{
    public const int SLOTS = 8;

    /**
     * @param Vec3   $north      world direction the mossy side faces
     * @param ?float $waterlineY sea level for salt crust and algae, null = none
     */
    public function __construct(
        private readonly RenderCommandList $commandList,
        private readonly Vec3 $north = new Vec3(0.0, 0.0, -1.0),
        private readonly ?float $waterlineY = null,
    ) {
    }

    public function update(World $world, float $dt): void
    {
        // Render-only.
    }

    public function render(World $world): void
    {
        $cameras = $this->commandList->ofType(SetCamera::class);
        $eye = $cameras !== [] ? ScreenProjection::cameraPosition($cameras[0]->viewMatrix) : new Vec3(0.0, 0.0, 0.0);
        foreach (self::uniforms($world, $eye, $this->north, $this->waterlineY) as $name => $value) {
            $this->commandList->add(new SetShaderParameter($name, $value));
        }
    }

    /**
     * @return array<string, int|list<float>>
     */
    public static function uniforms(World $world, Vec3 $eye, Vec3 $north, ?float $waterlineY): array
    {
        $nl = sqrt($north->x * $north->x + $north->z * $north->z);
        $nx = $nl > 1e-6 ? $north->x / $nl : 0.0;
        $nz = $nl > 1e-6 ? $north->z / $nl : -1.0;
        $found = [];
        $edge = 0.15;
        foreach ($world->query(AgingVolume::class, Transform3D::class) as $entity) {
            $volume = $entity->get(AgingVolume::class);
            if ($volume->age <= 0.0) {
                continue;
            }
            $transform = $entity->get(Transform3D::class);
            $c = $transform->getWorldPosition();
            $q = $transform->rotation;
            $yaw = atan2(2.0 * ($q->w * $q->y + $q->x * $q->z), 1.0 - 2.0 * ($q->y * $q->y + $q->z * $q->z));
            $h = $volume->halfExtents;
            $r = max($h->x, $h->z);
            $dx = max(0.0, abs($eye->x - $c->x) - $r);
            $dz = max(0.0, abs($eye->z - $c->z) - $r);
            $edge = $volume->edge;
            $found[] = [$dx * $dx + $dz * $dz, $entity->id, [
                [$c->x, $c->y, $c->z, $yaw],
                [$h->x, $h->y, $h->z, min(1.0, $volume->age)],
            ]];
        }
        usort($found, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $found = array_slice($found, 0, self::SLOTS);
        $out = [
            'u_age_zone_count' => count($found),
            'u_age_params' => [$nx, $nz, $waterlineY ?? -1.0e6, max(0.01, min(1.0, $edge))],
        ];
        for ($k = 0; $k < self::SLOTS; $k++) {
            foreach ([0, 1] as $j) {
                $out['u_age_zones[' . ($k * 2 + $j) . ']'] = $found[$k][2][$j] ?? [0.0, 0.0, 0.0, 0.0];
            }
        }

        return $out;
    }
}
