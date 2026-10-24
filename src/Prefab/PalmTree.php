<?php

declare(strict_types=1);

namespace PHPolygon\Prefab;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\Vegetation\GrowthStage;
use PHPolygon\Vegetation\PlantGenerator;
use PHPolygon\Vegetation\PlantLod;
use PHPolygon\Vegetation\PlantSpec;

/**
 * A single palm as a few entities - the compatibility wrapper around the
 * vegetation family ({@see PlantGenerator}, palm form). Same constructor and
 * `build()` as before; instead of ~70 entities (trunk segments, rings, box
 * leaves) it now declares one trunk mesh, one frond mesh (two-sided, swaying
 * with the scene wind), coconuts on every third palm and a slim trunk
 * collider. Meshes are shared by every palm with the same parameters.
 *
 * For more than a handful of palms use {@see \PHPolygon\Vegetation\PlantVariant}
 * with {@see \PHPolygon\Component\FoliageInstances} (instanced, LOD, growth).
 *
 * Materials: `palm_trunk`, `palm_leaves` / `palm_leaves_light` (alternating)
 * and `coconut`, as before. Missing ones get neutral defaults; a frond
 * material that is not two-sided is used through a two-sided copy
 * (`<id>_foliage`) so frond backs are never unlit.
 */
class PalmTree
{
    public function __construct(
        private readonly string $prefix,
        private readonly Vec3 $basePos,
        private readonly float $height = 5.5,
        private readonly float $lean = 0.12,
        private readonly int $treeIndex = 0,
        private readonly int $frondCount = 10,
    ) {}

    public function build(SceneBuilder $builder): void
    {
        $spec = PlantSpec::palm(
            height: $this->height,
            seed: 1 + ($this->treeIndex % 4),
            lean: $this->lean,
            frondCount: max(4, $this->frondCount),
            frondLength: 2.2 + max(0.0, $this->height - 4.5) * 0.25,
            accentCount: 2 + ($this->treeIndex % 2),
        );
        $withNuts = $this->treeIndex % 3 === 0;
        $key = sprintf(
            'palm_tree_h%d_l%d_s%d_f%d_c%d',
            (int) round($this->height * 10),
            (int) round($this->lean * 100),
            $spec->seed,
            $spec->frondCount,
            $withNuts ? $spec->accentCount : 0,
        );
        if (!MeshRegistry::has("{$key}_wood")) {
            $parts = PlantGenerator::generate($spec, $withNuts ? GrowthStage::Bloom : GrowthStage::Full, PlantLod::Lod0);
            MeshRegistry::register("{$key}_wood", $parts->wood);
            MeshRegistry::register("{$key}_leaf", $parts->leaf);
            if ($parts->accent->indices !== []) {
                MeshRegistry::register("{$key}_accent", $parts->accent);
            }
        }

        $rotation = Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), $this->treeIndex * 0.55);
        $trunkMaterial = self::ensureMaterial('palm_trunk', static fn (): Material => Material::bark(Color::srgb('#7A5A3A')));
        $frondMaterial = self::frondMaterial(($this->treeIndex % 2 === 0) ? 'palm_leaves' : 'palm_leaves_light');

        $builder->entity("{$this->prefix}_Trunk")
            ->with(new Transform3D(position: $this->basePos, rotation: $rotation))
            ->with(new MeshRenderer(meshId: "{$key}_wood", materialId: $trunkMaterial));
        $builder->entity("{$this->prefix}_Fronds")
            ->with(new Transform3D(position: $this->basePos, rotation: $rotation))
            ->with(new MeshRenderer(meshId: "{$key}_leaf", materialId: $frondMaterial));
        if ($withNuts && MeshRegistry::has("{$key}_accent")) {
            $nut = self::ensureMaterial('coconut', static fn (): Material => Material::bark(Color::srgb('#5A3D22')));
            $builder->entity("{$this->prefix}_Coconuts")
                ->with(new Transform3D(position: $this->basePos, rotation: $rotation))
                ->with(new MeshRenderer(meshId: "{$key}_accent", materialId: $nut));
        }

        // Slim collider along the lower trunk (the palm leans with height).
        $h = $this->height * 0.6;
        $t = 0.3;
        $off = $this->lean * $this->height * (0.35 * $t + 0.65 * $t * $t);
        $leanDir = $rotation->rotateVec3(new Vec3(cos($spec->leanDirection), 0.0, sin($spec->leanDirection)));
        $width = $spec->trunkRadiusResolved() * 2.4;
        $builder->entity("{$this->prefix}_Collider")
            ->with(new Transform3D(position: new Vec3(
                $this->basePos->x + $leanDir->x * $off,
                $this->basePos->y + $h * 0.5,
                $this->basePos->z + $leanDir->z * $off,
            )))
            ->with(new BoxCollider3D(size: new Vec3($width, $h, $width), isStatic: true));
    }

    /** @param \Closure(): Material $default */
    private static function ensureMaterial(string $id, \Closure $default): string
    {
        if (!MaterialRegistry::has($id)) {
            MaterialRegistry::register($id, $default());
        }
        return $id;
    }

    private static function frondMaterial(string $id): string
    {
        $material = MaterialRegistry::get($id);
        if ($material === null) {
            $color = $id === 'palm_leaves_light' ? '#5FA544' : '#3F8A3A';
            MaterialRegistry::register($id, Material::foliage(Color::srgb($color), flutter: 0.22));
            return $id;
        }
        if ($material->twoSided && $material->foliageWind) {
            return $id;
        }
        $foliage = "{$id}_foliage";
        if (!MaterialRegistry::has($foliage)) {
            MaterialRegistry::register($foliage, $material->withFoliage(0.22));
        }
        return $foliage;
    }
}
