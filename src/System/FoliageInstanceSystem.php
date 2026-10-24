<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\FoliageInstances;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Frustum;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Runtime\PerfProfiler;
use PHPolygon\Vegetation\GrowthStage;
use PHPolygon\Vegetation\PlantLibrary;
use PHPolygon\Vegetation\PlantLod;
use PHPolygon\Vegetation\PlantPart;
use PHPolygon\Vegetation\PlantVariant;

/**
 * Draws every {@see FoliageInstances} component as a few static instanced
 * batches: instances are grouped by (growth stage, LOD by camera distance,
 * foliage tint, shadow) and each group becomes one DrawMeshInstanced per
 * plant part (wood, leaf, accent). LOD0/LOD1 within the shadow distance cast
 * shadows, everything else does not.
 *
 * Cost control: instances are binned into square cells; cells whose bounding
 * sphere lies outside the view frustum are skipped, and the batches are only
 * rebuilt when the camera moved more than {@see $rebuildDistance}, a cell
 * entered or left the view, the instances changed (component version) or the
 * glow level changed - otherwise the same float arrays are sent again, which
 * VioRenderer3D answers from its static-batch cache without re-uploading.
 *
 * ORDERING CONTRACT (same as {@see InstancedTerrainSystem}): register after
 * Camera3DSystem (it reads the frame's SetCamera from the shared list) and
 * before Renderer3DSystem (which submits and clears the list).
 */
class FoliageInstanceSystem extends AbstractSystem
{
    /** Number of quantised glow levels (each level is one derived material). */
    public const int GLOW_LEVELS = 8;

    /**
     * @var array<int, array{
     *     version: int,
     *     variant: string,
     *     count: int,
     *     base: list<list<float>>,
     *     leaf: list<list<float>>,
     *     stage: list<int>,
     *     tint: list<int>,
     *     pos: list<array{0: float, 1: float, 2: float}>,
     *     cells: array<string, list<int>>,
     *     spheres: array<string, array{0: float, 1: float, 2: float, 3: float}>,
     *     signature: string,
     *     anchor: array{0: float, 1: float, 2: float},
     *     commands: list<DrawMeshInstanced>
     * }>
     */
    private array $state = [];

    /** Batches emitted last frame (for tests and profiling). */
    public int $lastDrawCount = 0;

    /** Batches rebuilt (not re-sent) last frame. */
    public int $lastRebuildCount = 0;

    public function __construct(
        private readonly RenderCommandList $commandList,
        public float $cellSize = 32.0,
        public float $rebuildDistance = 2.0,
        public bool $frustumCulling = true,
        /** Extra radius on every cell sphere so plants just off screen still cast their shadow into view. */
        public float $cullMargin = 12.0,
    ) {}

    public function render(World $world): void
    {
        $this->lastDrawCount = 0;
        $this->lastRebuildCount = 0;
        $camera = $this->findCamera();
        if ($camera === null) {
            return;
        }
        PerfProfiler::begin('ecs.system.foliage');
        $view = $camera->viewMatrix;
        $eye = $view->inverse()->getTranslation();
        $planes = $this->frustumCulling ? Frustum::planes($camera->projectionMatrix, $view) : [];
        $alive = [];
        foreach ($world->query(FoliageInstances::class) as $entity) {
            $foliage = $entity->get(FoliageInstances::class);
            $variant = PlantLibrary::get($foliage->variant);
            if ($variant === null || $foliage->instances === []) {
                continue;
            }
            $oid = spl_object_id($foliage);
            $alive[$oid] = true;
            $this->emit($oid, $foliage, $variant, $eye->x, $eye->y, $eye->z, $planes);
        }
        foreach (array_keys($this->state) as $oid) {
            if (!isset($alive[$oid])) {
                unset($this->state[$oid]);
            }
        }
        PerfProfiler::end();
    }

    /**
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $planes
     */
    private function emit(int $oid, FoliageInstances $f, PlantVariant $variant, float $ex, float $ey, float $ez, array $planes): void
    {
        $st = $this->state[$oid] ?? null;
        if ($st === null || $st['version'] !== $f->version || $st['variant'] !== $variant->id || $st['count'] !== count($f->instances)) {
            $st = $this->prepare($f, $variant);
        }

        // Which cells are on screen, and where (quantised) the camera is.
        $visible = [];
        foreach ($st['spheres'] as $key => [$cx, $cy, $cz, $r]) {
            if ($planes !== [] && Frustum::sphereOutside($planes, $cx, $cy, $cz, $r + $this->cullMargin)) {
                continue;
            }
            $visible[] = $key;
        }
        $glowLevel = $variant->accentGlow !== null ? (int) round(max(0.0, min(1.0, $f->glow)) * self::GLOW_LEVELS) : 0;
        $signature = $glowLevel
            . '|' . sprintf('%.2f,%.2f,%.2f,%.2f,%.2f,%d', $f->lod1Distance, $f->lod2Distance, $f->impostorDistance, $f->maxDistance, $f->shadowDistance, $f->castShadows ? 1 : 0)
            . '|' . implode(';', $visible);
        [$ax, $ay, $az] = $st['anchor'];
        $moved = ($ex - $ax) ** 2 + ($ey - $ay) ** 2 + ($ez - $az) ** 2 > $this->rebuildDistance ** 2;

        if (!$moved && $signature === $st['signature']) {
            foreach ($st['commands'] as $cmd) {
                $this->commandList->add($cmd);
            }
            $this->lastDrawCount += count($st['commands']);
            $this->state[$oid] = $st;
            return;
        }

        // Group visible instances by stage / LOD / tint / shadow.
        $lod1 = $f->lod1Distance;
        $lod2 = max($lod1, $f->lod2Distance);
        $imp = max($lod2, $f->impostorDistance);
        $max = $f->maxDistance;
        $shadowDist = $f->castShadows ? $f->shadowDistance : -1.0;
        /** @var array<int, list<int>> $groups */
        $groups = [];
        foreach ($visible as $key) {
            foreach ($st['cells'][$key] as $i) {
                [$x, $y, $z] = $st['pos'][$i];
                $dx = $x - $ex;
                $dy = $y - $ey;
                $dz = $z - $ez;
                $d = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
                if ($max > 0.0 && $d > $max) {
                    continue;
                }
                $lod = $d < $lod1 ? 0 : ($d < $lod2 ? 1 : ($d < $imp ? 2 : 3));
                $shadow = ($lod <= 1 && $d < $shadowDist) ? 1 : 0;
                $groups[$st['stage'][$i] * 1000 + $lod * 100 + $st['tint'][$i] * 2 + $shadow][] = $i;
            }
        }
        ksort($groups);

        $commands = [];
        foreach ($groups as $gkey => $members) {
            $stage = GrowthStage::from(intdiv($gkey, 1000));
            $lod = PlantLod::from(intdiv($gkey % 1000, 100));
            $tint = intdiv($gkey % 100, 2);
            $shadow = ($gkey % 2) === 1;
            foreach (PlantPart::cases() as $part) {
                if (!$variant->hasPart($stage, $lod, $part)) {
                    continue;
                }
                $material = $variant->partMaterial($part, $stage, $tint);
                if ($material === null) {
                    continue;
                }
                if ($part === PlantPart::Accent && $variant->accentGlow !== null) {
                    $material = self::glowMaterial($material, $variant->accentGlow, $glowLevel);
                }
                $source = $part === PlantPart::Wood ? $st['base'] : $st['leaf'];
                $flat = [];
                foreach ($members as $i) {
                    array_push($flat, ...$source[$i]);
                }
                $commands[] = DrawMeshInstanced::flat(
                    $variant->meshId($stage, $lod, $part),
                    $material,
                    $flat,
                    count($members),
                    isStatic: true,
                    staticKey: "foliage:{$oid}:{$gkey}:{$part->value}",
                    castShadows: $shadow,
                );
            }
        }

        $st['signature'] = $signature;
        $st['anchor'] = [$ex, $ey, $ez];
        $st['commands'] = $commands;
        $this->state[$oid] = $st;
        foreach ($commands as $cmd) {
            $this->commandList->add($cmd);
        }
        $this->lastDrawCount += count($commands);
        $this->lastRebuildCount += count($commands);
    }

    /**
     * Instance matrices, cells and per-instance stage / tint of a component.
     *
     * @return array{
     *     version: int,
     *     variant: string,
     *     count: int,
     *     base: list<list<float>>,
     *     leaf: list<list<float>>,
     *     stage: list<int>,
     *     tint: list<int>,
     *     pos: list<array{0: float, 1: float, 2: float}>,
     *     cells: array<string, list<int>>,
     *     spheres: array<string, array{0: float, 1: float, 2: float, 3: float}>,
     *     signature: string,
     *     anchor: array{0: float, 1: float, 2: float},
     *     commands: list<DrawMeshInstanced>
     * }
     */
    private function prepare(FoliageInstances $f, PlantVariant $variant): array
    {
        $tints = $variant->tintCount();
        $radius = $variant->boundingRadius();
        $cell = max(1.0, $this->cellSize);
        $base = [];
        $leaf = [];
        $stages = [];
        $tintOf = [];
        $pos = [];
        /** @var array<string, list<int>> $cells */
        $cells = [];
        /** @var array<string, array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}> $bounds */
        $bounds = [];
        /** @var array<int, array{0: float, 1: float, 2: float}> $crowns */
        $crowns = [];
        foreach ($f->instances as $i => $row) {
            [$x, $y, $z, $yaw, $scale, $stageValue, $leafScale] = $row;
            $stage = GrowthStage::tryFrom($stageValue) ?? GrowthStage::Full;
            $c = cos($yaw) * $scale;
            $s = sin($yaw) * $scale;
            // T * Ry(yaw) * S(scale), column-major (Mat4::rotationY layout).
            $m = [$c, 0.0, -$s, 0.0, 0.0, $scale, 0.0, 0.0, $s, 0.0, $c, 0.0, $x, $y, $z, 1.0];
            $base[] = $m;
            if (abs($leafScale - 1.0) < 1e-6) {
                $leaf[] = $m;
            } else {
                $crown = $crowns[$stage->value] ??= (static function () use ($variant, $stage): array {
                    $cc = $variant->parts($stage, PlantLod::Lod0)->crownCenter;
                    return [$cc->x, $cc->y, $cc->z];
                })();
                $k = 1.0 - $leafScale;
                // Scale the foliage around the crown centre: M * T(c) * S(ls) * T(-c).
                $l = $m;
                foreach ([0, 1, 2, 4, 5, 6, 8, 9, 10] as $j) {
                    $l[$j] *= $leafScale;
                }
                $l[12] += $k * ($m[0] * $crown[0] + $m[4] * $crown[1] + $m[8] * $crown[2]);
                $l[13] += $k * ($m[1] * $crown[0] + $m[5] * $crown[1] + $m[9] * $crown[2]);
                $l[14] += $k * ($m[2] * $crown[0] + $m[6] * $crown[1] + $m[10] * $crown[2]);
                $leaf[] = $l;
            }
            $stages[] = $stage->value;
            $tintOf[] = $tints > 1 ? (crc32(sprintf('%.2f:%.2f', $x, $z)) % $tints) : 0;
            $pos[] = [$x, $y, $z];
            $key = (int) floor($x / $cell) . ':' . (int) floor($z / $cell);
            $cells[$key][] = $i;
            $r = $radius * $scale;
            $b = $bounds[$key] ?? [INF, INF, INF, -INF, -INF, -INF];
            $bounds[$key] = [
                min($b[0], $x - $r), min($b[1], $y - $r * 0.2), min($b[2], $z - $r),
                max($b[3], $x + $r), max($b[4], $y + $r * 1.6), max($b[5], $z + $r),
            ];
        }
        $spheres = [];
        foreach ($bounds as $key => [$x0, $y0, $z0, $x1, $y1, $z1]) {
            $hx = ($x1 - $x0) * 0.5;
            $hy = ($y1 - $y0) * 0.5;
            $hz = ($z1 - $z0) * 0.5;
            $spheres[$key] = [$x0 + $hx, $y0 + $hy, $z0 + $hz, sqrt($hx * $hx + $hy * $hy + $hz * $hz)];
        }
        ksort($cells);
        ksort($spheres);
        return [
            'version' => $f->version,
            'variant' => $variant->id,
            'count' => count($f->instances),
            'base' => $base,
            'leaf' => $leaf,
            'stage' => $stages,
            'tint' => $tintOf,
            'pos' => $pos,
            'cells' => $cells,
            'spheres' => $spheres,
            'signature' => '',
            'anchor' => [INF, INF, INF],
            'commands' => [],
        ];
    }

    /**
     * The material id of `$base` glowing at `$level` of {@see GLOW_LEVELS}:
     * level 0 is the base material, every other level a derived material with
     * the emission scaled, registered on first use.
     */
    public static function glowMaterial(string $base, Color $glow, int $level): string
    {
        $level = max(0, min(self::GLOW_LEVELS, $level));
        if ($level === 0) {
            return $base;
        }
        $id = "{$base}#glow{$level}";
        if (!MaterialRegistry::has($id)) {
            $material = MaterialRegistry::get($base);
            if ($material === null) {
                return $base;
            }
            $k = $level / self::GLOW_LEVELS;
            MaterialRegistry::register($id, $material->withEmission(new Color($glow->r * $k, $glow->g * $k, $glow->b * $k)));
        }
        return $id;
    }

    private function findCamera(): ?SetCamera
    {
        $commands = $this->commandList->getCommands();
        for ($i = count($commands) - 1; $i >= 0; $i--) {
            $cmd = $commands[$i] ?? null;
            if ($cmd instanceof SetCamera) {
                return $cmd;
            }
        }
        return null;
    }

    /** Test hook: the instance matrix row of instance `$index` as a Mat4. */
    public static function instanceMatrix(FoliageInstances $f, int $index): Mat4
    {
        $row = $f->instances[$index] ?? null;
        if ($row === null) {
            throw new \OutOfRangeException("no instance {$index}");
        }
        return Mat4::translation($row[0], $row[1], $row[2])
            ->multiply(Mat4::rotationY($row[3]))
            ->multiply(Mat4::scaling($row[4], $row[4], $row[4]));
    }
}
