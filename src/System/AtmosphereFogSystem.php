<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\FogVolume;
use PHPolygon\Component\HeightFog;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ScreenProjection;

/**
 * Hands the scene's {@see HeightFog} and the {@see FogVolume}s nearest to the camera
 * to the mesh shader's scene fog (shader plan 4.2): u_height_fog (density, falloff,
 * base height, on), u_height_fog_color (rgb, 1 = own colour), u_fog_volume_count and
 * u_fog_volumes[3 * k + 0..2] = (centre, yaw), (half extents, density), (colour,
 * edge). One vec4 array keeps the volumes to a single uniform entry. Reads
 * SetCamera: register after Camera3DSystem and before Renderer3DSystem. Sole
 * writer of those uniforms.
 */
class AtmosphereFogSystem extends AbstractSystem
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
     * @return array<string, int|float|list<float>>
     */
    public static function uniforms(World $world, Vec3 $eye): array
    {
        $out = [
            'u_height_fog' => [0.0, 0.1, 0.0, 0.0],
            'u_height_fog_color' => [0.0, 0.0, 0.0, 0.0],
            'u_light_halo' => 0.0,
        ];
        foreach ($world->query(HeightFog::class) as $entity) {
            $fog = $entity->get(HeightFog::class);
            $out['u_light_halo'] = max(0.0, $fog->lightHalo);
            if ($fog->density > 0.0) {
                $out['u_height_fog'] = [$fog->density, max(0.001, $fog->falloff), $fog->baseHeight, 1.0];
                if ($fog->color !== null) {
                    $out['u_height_fog_color'] = [$fog->color->r, $fog->color->g, $fog->color->b, 1.0];
                }
            }
            break;
        }

        $found = [];
        foreach ($world->query(FogVolume::class, Transform3D::class) as $entity) {
            $volume = $entity->get(FogVolume::class);
            if ($volume->density <= 0.0) {
                continue;
            }
            $transform = $entity->get(Transform3D::class);
            $c = $transform->getWorldPosition();
            $q = $transform->rotation;
            $yaw = atan2(2.0 * ($q->w * $q->y + $q->x * $q->z), 1.0 - 2.0 * ($q->y * $q->y + $q->z * $q->z));
            $h = $volume->halfExtents;
            $r = max($h->x, $h->z);
            $dx = max(0.0, abs($eye->x - $c->x) - $r);
            $dy = max(0.0, abs($eye->y - $c->y) - $h->y);
            $dz = max(0.0, abs($eye->z - $c->z) - $r);
            $found[] = [$dx * $dx + $dy * $dy + $dz * $dz, $entity->id, [
                [$c->x, $c->y, $c->z, $yaw],
                [$h->x, $h->y, $h->z, $volume->density],
                [$volume->color->r, $volume->color->g, $volume->color->b, max(0.01, min(1.0, $volume->edge))],
            ]];
        }
        usort($found, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $found = array_slice($found, 0, self::SLOTS);
        $out['u_fog_volume_count'] = count($found);
        for ($k = 0; $k < self::SLOTS; $k++) {
            foreach ([0, 1, 2] as $j) {
                $out['u_fog_volumes[' . ($k * 3 + $j) . ']'] = $found[$k][2][$j] ?? [0.0, 0.0, 0.0, 0.0];
            }
        }

        return $out;
    }
}
