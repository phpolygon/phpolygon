<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Impostor;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\MaterialRegistry;

/**
 * A baked impostor (shader plan 9.1, {@see ImpostorBaker}): one quad mesh and one
 * albedo texture per view around the vertical axis. Far instances are drawn as quads
 * turned to the camera, each showing the view nearest to where the camera stands
 * relative to the instance - one batch through DrawMeshInstanced::$albedoTextures
 * (one draw with bindless materials, one per view without).
 */
final readonly class ImpostorSet
{
    /**
     * @param list<string> $textureIds view k seen from azimuth 2*pi*k/views (k = 0: from +Z)
     * @param list<string> $normalTextureIds the views' normal maps (empty without impostor normals)
     */
    public function __construct(
        public string $quadMeshId,
        public array $textureIds,
        public float $centerX,
        public float $centerZ,
        public array $normalTextureIds = [],
    ) {}

    public function views(): int
    {
        return count($this->textureIds);
    }

    /**
     * The batch for $instances seen from $camera. Each instance: position (the mesh
     * origin), yaw around Y in radians, uniform scale. Use a material with the albedo
     * texture's alpha as cutout ({@see \PHPolygon\Rendering\Material::$alphaCutoff})
     * and no environment reflection.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float, 4: float}> $instances x, y, z, yaw, scale
     */
    public function command(string $materialId, Vec3 $camera, array $instances, bool $castShadows = false): DrawMeshInstanced
    {
        [$flat, $views] = $this->place($camera, $instances);
        return new DrawMeshInstanced(
            meshId: $this->quadMeshId,
            materialId: $materialId,
            matrices: [],
            instanceCount: count($instances),
            flatMatrices: $flat,
            castShadows: $castShadows,
            albedoTextures: array_map(fn (int $k): string => $this->textureIds[$k], $views),
        );
    }

    /**
     * The batches for $instances with the views' normal maps (impostor normals): one
     * DrawMeshInstanced per view in use, each with a material derived from $materialId
     * that carries the view's albedo and normal map (registered as "$materialId#view{k}").
     * Without baked normals the same as [command()].
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float, 4: float}> $instances x, y, z, yaw, scale
     * @return list<DrawMeshInstanced>
     */
    public function commands(string $materialId, Vec3 $camera, array $instances, bool $castShadows = false): array
    {
        $base = MaterialRegistry::get($materialId);
        if ($this->normalTextureIds === [] || $base === null) {
            return [$this->command($materialId, $camera, $instances, $castShadows)];
        }
        [$flat, $views] = $this->place($camera, $instances);
        $groups = [];
        foreach ($views as $i => $k) {
            $groups[$k] ??= [];
            array_push($groups[$k], ...array_slice($flat, $i * 16, 16));
        }
        ksort($groups);
        $out = [];
        foreach ($groups as $k => $matrices) {
            $id = $materialId . '#view' . $k;
            if (!MaterialRegistry::has($id)) {
                MaterialRegistry::register($id, $base->withAlbedoTexture($this->textureIds[$k])->withNormalTexture($this->normalTextureIds[$k]));
            }
            $out[] = new DrawMeshInstanced(
                meshId: $this->quadMeshId,
                materialId: $id,
                matrices: [],
                instanceCount: intdiv(count($matrices), 16),
                flatMatrices: $matrices,
                castShadows: $castShadows,
            );
        }
        return $out;
    }

    /**
     * Billboard matrices (flat, 16 per instance) and the view each instance shows.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float, 4: float}> $instances
     * @return array{0: list<float>, 1: list<int>}
     */
    private function place(Vec3 $camera, array $instances): array
    {
        $views = $this->views();
        $step = 2.0 * M_PI / $views;
        $flat = [];
        $shown = [];
        foreach ($instances as [$x, $y, $z, $yaw, $scale]) {
            $c = cos($yaw);
            $s = sin($yaw);
            // The billboard turns around the mesh's own centre, not its origin.
            $px = $x + ($c * $this->centerX + $s * $this->centerZ) * $scale;
            $pz = $z + (-$s * $this->centerX + $c * $this->centerZ) * $scale;
            $face = atan2($camera->x - $px, $camera->z - $pz);
            $k = (int) round(($face - $yaw) / $step) % $views;
            if ($k < 0) {
                $k += $views;
            }
            $m = Mat4::translation($px, $y, $pz)->multiply(Mat4::rotationY($face))->multiply(Mat4::scaling($scale, $scale, $scale));
            array_push($flat, ...$m->toArray());
            $shown[] = $k;
        }
        return [$flat, $shown];
    }
}
