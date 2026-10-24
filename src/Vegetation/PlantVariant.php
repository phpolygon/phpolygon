<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;

/**
 * One baked plant variant: a {@see PlantSpec} plus the materials its parts
 * are drawn with. Generates (lazily, once) the meshes of every growth stage
 * and LOD and registers them in the {@see MeshRegistry} under
 * `{id}_s{stage}_l{lod}_{part}`, so a whole forest of this variant is a
 * handful of instanced draws ({@see \PHPolygon\Component\FoliageInstances}).
 *
 * Everything game-specific - sizes, colours, which material a stage uses -
 * comes in as parameters; the engine names nothing.
 */
final class PlantVariant
{
    /** @var array<string, PlantParts> "stage:lod" => generated parts */
    private array $parts = [];

    /**
     * @param string             $id                 Unique snake_case id; prefix of the mesh ids.
     * @param string             $woodMaterial       Material of trunk, branches, stake.
     * @param string             $leafMaterial       Material of the foliage.
     * @param string|null        $accentMaterial     Material of blossoms / fruit (Bloom stage);
     *                                               null draws no accent.
     * @param list<string>       $leafTints          Extra foliage materials; each instance picks
     *                                               one of [leafMaterial, ...leafTints] by a hash
     *                                               of its position (variation without per-plant
     *                                               materials; every tint is one more draw).
     * @param array<int, string> $stageLeafMaterials GrowthStage value => foliage material for that
     *                                               stage (e.g. fresh green while sprouting, gold
     *                                               in bloom); replaces leafMaterial and tints.
     * @param Color|null         $accentGlow         Emission of the accent at full glow
     *                                               ({@see \PHPolygon\Component\FoliageInstances::$glow}).
     * @param bool               $trunkCollider      Whether {@see FoliageColliders} gives trees a
     *                                               slim trunk collider.
     * @param bool               $hedgeCollider      Whether {@see FoliageColliders} gives a hedge a
     *                                               full-height box (only where it should block).
     */
    public function __construct(
        public readonly string $id,
        public readonly PlantSpec $spec,
        public readonly string $woodMaterial,
        public readonly string $leafMaterial,
        public readonly ?string $accentMaterial = null,
        public readonly array $leafTints = [],
        public readonly array $stageLeafMaterials = [],
        public readonly ?Color $accentGlow = null,
        public readonly bool $trunkCollider = true,
        public readonly bool $hedgeCollider = false,
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('PlantVariant: id must not be empty');
        }
    }

    /** Generated parts of one stage and LOD (generated on first use, then cached). */
    public function parts(GrowthStage $stage, PlantLod $lod): PlantParts
    {
        return $this->parts[$stage->value . ':' . $lod->value] ??= PlantGenerator::generate($this->spec, $stage, $lod);
    }

    public function mesh(GrowthStage $stage, PlantLod $lod, PlantPart $part): MeshData
    {
        return $this->parts($stage, $lod)->part($part);
    }

    /** Whether the part has geometry at that stage and LOD (e.g. no foliage when bare). */
    public function hasPart(GrowthStage $stage, PlantLod $lod, PlantPart $part): bool
    {
        if ($part === PlantPart::Accent && ($this->accentMaterial === null || !$stage->hasAccent())) {
            return false;
        }
        return $this->mesh($stage, $lod, $part)->indices !== [];
    }

    public function meshId(GrowthStage $stage, PlantLod $lod, PlantPart $part): string
    {
        return "{$this->id}_s{$stage->value}_l{$lod->value}_{$part->value}";
    }

    /**
     * Register every stage x LOD x part mesh lazily in the MeshRegistry
     * (generated on first upload). Called by {@see PlantLibrary::register()}.
     */
    public function registerMeshes(): void
    {
        foreach (GrowthStage::cases() as $stage) {
            foreach (PlantLod::cases() as $lod) {
                foreach (PlantPart::cases() as $part) {
                    MeshRegistry::registerLazy(
                        $this->meshId($stage, $lod, $part),
                        fn (): MeshData => $this->mesh($stage, $lod, $part),
                    );
                }
            }
        }
    }

    /** Foliage material of an instance at a stage; `$tint` picks among the tints. */
    public function leafMaterial(GrowthStage $stage, int $tint = 0): string
    {
        if (isset($this->stageLeafMaterials[$stage->value])) {
            return $this->stageLeafMaterials[$stage->value];
        }
        if ($tint <= 0 || $this->leafTints === []) {
            return $this->leafMaterial;
        }
        return $this->leafTints[($tint - 1) % count($this->leafTints)];
    }

    /** Number of foliage tints instances spread over (1 = no tinting). */
    public function tintCount(): int
    {
        return 1 + count($this->leafTints);
    }

    public function partMaterial(PlantPart $part, GrowthStage $stage, int $tint = 0): ?string
    {
        return match ($part) {
            PlantPart::Wood => $this->woodMaterial,
            PlantPart::Leaf => $this->leafMaterial($stage, $tint),
            PlantPart::Accent => $this->accentMaterial,
        };
    }

    /**
     * Collision box of one plant at a stage in plant-local space (before the
     * instance yaw/scale), or null when it should not collide: a slim box the
     * width of the trunk up to its first branches (trees, palms), a
     * full-height box for a blocking hedge, nothing for bushes and foliage.
     *
     * @return array{center: Vec3, size: Vec3}|null
     */
    public function collider(GrowthStage $stage = GrowthStage::Full): ?array
    {
        $form = $this->spec->form;
        if ($form === PlantForm::Hedge) {
            if (!$this->hedgeCollider || $stage === GrowthStage::Bare) {
                return null;
            }
            $p = $this->parts($stage, PlantLod::Lod2);
            $h = max(0.3, $p->height);
            return [
                'center' => new Vec3(0.0, $h * 0.5, 0.0),
                'size' => new Vec3($this->spec->length, $h, $this->spec->depth),
            ];
        }
        if (!$form->hasTrunk() || !$this->trunkCollider) {
            return null;
        }
        $p = $this->parts($stage, PlantLod::Lod0);
        if ($p->trunkRadius <= 0.0 || $p->trunkHeight <= 0.0) {
            return null;
        }
        $h = min($p->trunkHeight, max(1.0, $p->height));
        $w = $p->trunkRadius * 2.2;
        $lean = $form === PlantForm::Palm ? 0.0 : $this->spec->lean;
        $off = $lean * ($h * 0.5) * ($h * 0.5) / max(0.1, $this->spec->height);
        return [
            'center' => new Vec3(cos($this->spec->leanDirection) * $off, $h * 0.5, sin($this->spec->leanDirection) * $off),
            'size' => new Vec3($w, $h, $w),
        ];
    }

    /**
     * Ground radius the plant occupies (trunk with root flare, bush or hedge
     * half-depth) - what lamps, benches and paths must keep clear of.
     */
    public function footprintRadius(): float
    {
        return match ($this->spec->form) {
            PlantForm::Bush => $this->spec->crownRadius,
            PlantForm::Hedge => $this->spec->depth * 0.5,
            default => $this->spec->trunkRadiusResolved() * 1.7,
        };
    }

    /** Radius of a sphere around the plant origin that holds it at full size (culling). */
    public function boundingRadius(): float
    {
        $p = $this->parts(GrowthStage::Bloom, PlantLod::Lod2);
        $half = $this->spec->form === PlantForm::Hedge ? $this->spec->length * 0.5 : 0.0;
        return sqrt(max($p->crownRadius, $half) ** 2 + ($p->height * 0.5) ** 2) + 0.5;
    }
}
