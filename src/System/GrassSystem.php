<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\GrassBender;
use PHPolygon\Component\GrassField;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetGrass;
use PHPolygon\Rendering\Frustum;
use PHPolygon\Rendering\GraphicsSettingsManager;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\GrassQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Runtime\PerfProfiler;
use PHPolygon\Vegetation\GrassDetail;
use PHPolygon\Vegetation\GrassTuftMesh;

/**
 * Grass blades near the camera for every {@see GrassField}.
 *
 * Tufts ({@see GrassTuftMesh}) are generated lazily in square cells around the
 * camera - deterministic per field seed and grid position, at most
 * {@see GrassDetail::$generateBudgetMs} per frame, nearest cells first - and kept
 * as packed instance matrices sorted by a per-tuft hash. Per frame, the cells in
 * the view frustum and within {@see GrassDetail::$fadeRange} contribute a prefix
 * of their tufts (the ones whose hash is below the density at the cell's nearest
 * point - always a superset of what the shader keeps), and all of them go out as
 * one instanced draw per tuft variant and LOD: `variants x 2` draws per field.
 *
 * The shader does the rest per tuft and per frame (Material::$grass): density
 * thinning and shrinking with the distance, wind, bending away from
 * {@see GrassBender}s. The instance hash rides in the unused fourth row of the
 * instance matrix (`m[3]`), which the grass vertex stage reads and clears.
 *
 * CPU cost at rest is near zero: when the camera moved less than
 * {@see $rebuildDistance} and the visible cells did not change, the previous
 * commands are re-sent. Blades never cast shadows.
 *
 * ORDERING CONTRACT (same as {@see FoliageInstanceSystem}): register after
 * Camera3DSystem and before Renderer3DSystem.
 */
class GrassSystem extends AbstractSystem
{
    /** Bytes of one packed instance matrix. */
    private const int STRIDE = 64;

    /** Draws emitted last frame. */
    public int $lastDrawCount = 0;

    /** Tufts drawn last frame (all fields). */
    public int $lastInstanceCount = 0;

    /** Triangles submitted last frame (before the shader thins the far tufts). */
    public int $lastTriangleCount = 0;

    /** Whether last frame rebuilt its batches (false = re-sent). */
    public bool $lastRebuilt = false;

    /** Cells generated last frame. */
    public int $lastGeneratedCells = 0;

    /** Milliseconds spent generating cells last frame. */
    public float $lastGenerateMs = 0.0;

    private ?GrassDetail $detail;

    /** Detail the meshes / materials were prepared for. */
    private string $preparedKey = '';

    /** @var list<array{0: string, 1: string}> per variant: [near mesh id, far mesh id] */
    private array $meshIds = [];

    /**
     * @var array<int, array{
     *     key: string,
     *     cells: array<string, array{ix: int, iz: int, y0: float, y1: float, hashes: list<list<float>>, packed: list<string>}>,
     *     material: string,
     *     signature: string,
     *     anchor: array{0: float, 1: float, 2: float},
     *     commands: list<DrawMeshInstanced>,
     *     instances: int,
     *     triangles: int,
     *     view: array<float>,
     *     complete: bool
     * }>
     */
    private array $state = [];

    public function __construct(
        private readonly RenderCommandList $commandList,
        GrassQuality|GrassDetail|null $quality = GrassQuality::Medium,
        /** When set, the quality follows GraphicsSettings::$grassQuality every frame. */
        public ?GraphicsSettingsManager $graphics = null,
        public float $cellSize = 12.0,
        public float $rebuildDistance = 1.5,
        public bool $frustumCulling = true,
        /** Cells kept beyond the fade range before they are dropped from the cache. */
        public int $maxCachedCells = 900,
    ) {
        $this->detail = $quality instanceof GrassQuality ? $quality->detail() : $quality;
    }

    public function setQuality(GrassQuality $quality): void
    {
        $this->graphics = null;
        $this->detail = $quality->detail();
    }

    /** A custom parameter set (null = off). Detaches from the graphics settings. */
    public function setDetail(?GrassDetail $detail): void
    {
        $this->graphics = null;
        $this->detail = $detail;
    }

    public function detail(): ?GrassDetail
    {
        if ($this->graphics !== null) {
            return $this->graphics->settings()->grassQuality->detail();
        }
        return $this->detail;
    }

    public function render(World $world): void
    {
        $this->lastDrawCount = 0;
        $this->lastInstanceCount = 0;
        $this->lastTriangleCount = 0;
        $this->lastRebuilt = false;
        $this->lastGeneratedCells = 0;
        $this->lastGenerateMs = 0.0;
        $detail = $this->detail();
        if ($detail === null) {
            $this->state = [];
            return;
        }
        $camera = $this->findCamera();
        if ($camera === null) {
            return;
        }
        PerfProfiler::begin('ecs.system.grass');
        $this->prepare($detail);
        $eye = $camera->viewMatrix->inverse()->getTranslation();
        $view = array_merge($camera->viewMatrix->toArray(), $camera->projectionMatrix->toArray());
        $planes = $this->frustumCulling ? Frustum::planes($camera->projectionMatrix, $camera->viewMatrix) : [];

        $this->commandList->add(new SetGrass(
            fullRange: $detail->fullRange,
            fadeRange: $detail->fadeRange,
            farHeight: $detail->farHeight,
            benders: $detail->interaction ? $this->benders($world, $eye->x, $eye->y, $eye->z, $detail->fadeRange) : [],
            pushStrength: $detail->pushStrength,
        ));

        $budgetEnd = hrtime(true) + (int) ($detail->generateBudgetMs * 1e6);
        $alive = [];
        foreach ($world->query(GrassField::class) as $entity) {
            $field = $entity->get(GrassField::class);
            $oid = spl_object_id($field);
            $alive[$oid] = true;
            $this->emitField($oid, $field, $detail, $eye->x, $eye->y, $eye->z, $planes, $budgetEnd, $view);
        }
        foreach (array_keys($this->state) as $oid) {
            if (!isset($alive[$oid])) {
                unset($this->state[$oid]);
            }
        }
        PerfProfiler::end();
    }

    /**
     * Generate every cell of every field within `$radius` of (x, z) now (loading
     * screens, teleports), ignoring the per-frame budget.
     */
    public function prewarm(World $world, float $x, float $z, ?float $radius = null): int
    {
        $detail = $this->detail();
        if ($detail === null) {
            return 0;
        }
        $this->prepare($detail);
        $radius ??= $detail->fadeRange;
        $made = 0;
        foreach ($world->query(GrassField::class) as $entity) {
            $field = $entity->get(GrassField::class);
            $oid = spl_object_id($field);
            $st = $this->fieldState($oid, $field, $detail);
            foreach ($this->candidateCells($field, $x, $z, $radius) as [$ix, $iz]) {
                $key = $ix . ':' . $iz;
                if (!isset($st['cells'][$key])) {
                    $st['cells'][$key] = $this->generateCell($field, $detail, $ix, $iz);
                    $made++;
                }
            }
            $this->state[$oid] = $st;
        }
        return $made;
    }

    /** Cells currently cached for all fields (for tests and profiling). */
    public function cachedCellCount(): int
    {
        $n = 0;
        foreach ($this->state as $st) {
            $n += count($st['cells']);
        }
        return $n;
    }

    // ── per field ─────────────────────────────────────────────────────────

    /**
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $planes
     * @param array<float> $view view and projection matrix of the frame
     */
    private function emitField(int $oid, GrassField $field, GrassDetail $detail, float $ex, float $ey, float $ez, array $planes, int $budgetEnd, array $view): void
    {
        $st = $this->fieldState($oid, $field, $detail);
        // At rest: the very same camera, nothing changed, every cell built.
        if ($st['complete'] && $st['view'] === $view && $st['signature'] !== '') {
            $this->send($st);
            return;
        }
        $cs = max(1.0, $this->cellSize);
        $fade = $detail->fadeRange;
        $tallest = $detail->shape->heightMax * $field->height * (1.0 + $field->heightJitter) + 0.2;

        // Visible cells and the ones still missing (nearest first).
        $visible = [];
        $missing = [];
        foreach ($this->candidateCells($field, $ex, $ez, $fade) as [$ix, $iz]) {
            $key = $ix . ':' . $iz;
            $x0 = $ix * $cs;
            $z0 = $iz * $cs;
            $dx = max($x0 - $ex, 0.0, $ex - ($x0 + $cs));
            $dz = max($z0 - $ez, 0.0, $ez - ($z0 + $cs));
            $dh = sqrt($dx * $dx + $dz * $dz);
            if ($dh > $fade) {
                continue;
            }
            if (!isset($st['cells'][$key])) {
                $missing[] = [$dh, $ix, $iz];
                continue;
            }
            $visible[$key] = $dh;
        }
        $complete = true;
        if ($missing !== []) {
            usort($missing, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            $t0 = hrtime(true);
            foreach ($missing as $i => [$dh, $ix, $iz]) {
                // Always at least the nearest cell, then within the budget.
                if ($i > 0 && hrtime(true) > $budgetEnd) {
                    $complete = false;
                    break;
                }
                $key = $ix . ':' . $iz;
                $st['cells'][$key] = $this->generateCell($field, $detail, $ix, $iz);
                $visible[$key] = $dh;
                $this->lastGeneratedCells++;
            }
            $this->lastGenerateMs += (hrtime(true) - $t0) / 1e6;
        }

        // Frustum and 3D distance per cell: the visible set.
        $shown = [];
        $signature = '';
        $half = $cs * 0.5;
        foreach ($visible as $key => $dh) {
            $cell = $st['cells'][$key];
            if ($cell['packed'] === [] || $cell['hashes'] === []) {
                continue;
            }
            $cy0 = $cell['y0'];
            $cy1 = $cell['y1'] + $tallest;
            if ($planes !== []) {
                $hy = ($cy1 - $cy0) * 0.5;
                if (Frustum::sphereOutside($planes, $cell['ix'] * $cs + $half, $cy0 + $hy, $cell['iz'] * $cs + $half, sqrt(2.0 * $half * $half + $hy * $hy))) {
                    continue;
                }
            }
            $dy = max($cy0 - $ey, 0.0, $ey - $cy1);
            $d = sqrt($dh * $dh + $dy * $dy);
            if ($d > $fade) {
                continue;
            }
            $shown[$key] = $d;
            $signature .= $key . ';';
        }

        // Within the rebuild distance the batches built at the anchor still hold
        // (their prefixes are cut with that margin): re-send them while the same
        // cells are on screen.
        [$ax, $ay, $az] = $st['anchor'];
        $moved = ($ex - $ax) ** 2 + ($ey - $ay) ** 2 + ($ez - $az) ** 2 > $this->rebuildDistance ** 2;
        $st['view'] = $view;
        $st['complete'] = $complete;
        if (!$moved && $signature === $st['signature']) {
            $this->send($st);
            $this->state[$oid] = $st;
            return;
        }

        // LOD and the prefix of every visible cell (tufts whose hash is below
        // the density at the cell's nearest point, minus the rebuild margin).
        $parts = [];
        foreach ($shown as $key => $d) {
            $cell = $st['cells'][$key];
            $density = 1.0 - self::smoothstep($detail->fullRange, $fade, max(0.0, $d - $this->rebuildDistance));
            $threshold = min(1.0, $density * 1.25);
            $lod = $d < $detail->lodDistance ? 0 : 1;
            $counts = [];
            foreach ($cell['hashes'] as $v => $hashes) {
                $counts[$v] = $threshold >= 1.0 ? count($hashes) : self::countBelow($hashes, $threshold);
            }
            $parts[] = [$key, $lod, $counts];
        }

        // Budget: scale every prefix down when the frame would draw too many.
        $total = 0;
        foreach ($parts as [, , $counts]) {
            $total += array_sum($counts);
        }
        $scale = $total > $detail->maxInstances && $total > 0 ? $detail->maxInstances / $total : 1.0;

        /** @var array<string, list<string>> $chunks "variant:lod" => byte strings */
        $chunks = [];
        $countOf = [];
        foreach ($parts as [$key, $lod, $counts]) {
            $cell = $st['cells'][$key];
            foreach ($counts as $v => $n) {
                $n = $scale < 1.0 ? (int) floor($n * $scale) : $n;
                if ($n <= 0) {
                    continue;
                }
                $bucket = $v . ':' . $lod;
                $chunks[$bucket][] = $n === count($cell['hashes'][$v])
                    ? $cell['packed'][$v]
                    : substr($cell['packed'][$v], 0, $n * self::STRIDE);
                $countOf[$bucket] = ($countOf[$bucket] ?? 0) + $n;
            }
        }
        ksort($chunks);
        $commands = [];
        $instances = 0;
        $triangles = 0;
        $nearTris = $detail->shape->trianglesPerTuft();
        $farTris = $detail->farShape()->trianglesPerTuft();
        foreach ($chunks as $bucket => $bytes) {
            [$v, $lod] = array_map('intval', explode(':', $bucket));
            $n = $countOf[$bucket];
            $commands[] = new DrawMeshInstanced(
                meshId: $this->meshIds[$v][$lod],
                materialId: $st['material'],
                matrices: [],
                isStatic: false,
                instanceCount: $n,
                packedMatrices: implode('', $bytes),
                castShadows: false,
            );
            $instances += $n;
            $triangles += $n * ($lod === 0 ? $nearTris : $farTris);
        }
        $st['signature'] = $signature;
        $st['anchor'] = [$ex, $ey, $ez];
        $st['commands'] = $commands;
        $st['instances'] = $instances;
        $st['triangles'] = $triangles;
        $st['cells'] = $this->evict($st['cells'], $ex, $ez, $fade);
        $this->state[$oid] = $st;
        $this->lastRebuilt = true;
        $this->send($st);
    }

    /**
     * @param array{commands: list<DrawMeshInstanced>, instances: int, triangles: int} $st
     */
    private function send(array $st): void
    {
        foreach ($st['commands'] as $cmd) {
            $this->commandList->add($cmd);
        }
        $this->lastDrawCount += count($st['commands']);
        $this->lastInstanceCount += $st['instances'];
        $this->lastTriangleCount += $st['triangles'];
    }

    /**
     * @return array{
     *     key: string,
     *     cells: array<string, array{ix: int, iz: int, y0: float, y1: float, hashes: list<list<float>>, packed: list<string>}>,
     *     material: string,
     *     signature: string,
     *     anchor: array{0: float, 1: float, 2: float},
     *     commands: list<DrawMeshInstanced>,
     *     instances: int,
     *     triangles: int,
     *     view: array<float>,
     *     complete: bool
     * }
     */
    private function fieldState(int $oid, GrassField $field, GrassDetail $detail): array
    {
        $key = sprintf(
            '%d|%d|%s|%.3f|%d|%.4f|%.4f|%.4f|%.4f|%.4f|%.4f|%.4f|%.4f',
            $field->version, $field->mask->version, $detail->instanceKey(), $this->cellSize, $field->seed,
            $field->density, $field->height, $field->heightJitter, $field->sink,
            $field->minX, $field->minZ, $field->maxX, $field->maxZ,
        );
        $material = $this->materialFor($field, $detail);
        $st = $this->state[$oid] ?? null;
        if ($st !== null && $st['key'] === $key) {
            if ($st['material'] !== $material) {
                $st['material'] = $material;
                $st['signature'] = '';
                $st['view'] = [];
            }
            return $st;
        }
        return [
            'key' => $key,
            'cells' => [],
            'material' => $material,
            'signature' => '',
            'anchor' => [INF, INF, INF],
            'commands' => [],
            'instances' => 0,
            'triangles' => 0,
            'view' => [],
            'complete' => false,
        ];
    }

    /**
     * Cell indices of the field within `$radius` (XZ box) of a point.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function candidateCells(GrassField $field, float $x, float $z, float $radius): array
    {
        $cs = max(1.0, $this->cellSize);
        $ix0 = (int) floor(max($field->minX, $x - $radius) / $cs);
        $ix1 = (int) floor(min($field->maxX, $x + $radius) / $cs);
        $iz0 = (int) floor(max($field->minZ, $z - $radius) / $cs);
        $iz1 = (int) floor(min($field->maxZ, $z + $radius) / $cs);
        $out = [];
        for ($ix = $ix0; $ix <= $ix1; $ix++) {
            for ($iz = $iz0; $iz <= $iz1; $iz++) {
                $out[] = [$ix, $iz];
            }
        }
        return $out;
    }

    /**
     * Tufts of one cell: a jittered grid on world coordinates (seamless across
     * cells), kept by the mask and the field density, set on the ground.
     *
     * @return array{ix: int, iz: int, y0: float, y1: float, hashes: list<list<float>>, packed: list<string>}
     */
    private function generateCell(GrassField $field, GrassDetail $detail, int $ix, int $iz): array
    {
        $cs = max(1.0, $this->cellSize);
        $variants = max(1, $detail->variants);
        $tufts = $detail->tuftsPerSquareMetre * max(1.0, $field->density);
        $keepBase = min(1.0, max(0.0, $field->density));
        /** @var list<list<array{0: float, 1: string}>> $rows per variant: [hash, packed] */
        $rows = array_fill(0, $variants, []);
        $y0 = INF;
        $y1 = -INF;
        if ($tufts > 0.0 && $keepBase > 0.0) {
            $a = 1.0 / sqrt($tufts);
            $x0 = $ix * $cs;
            $z0 = $iz * $cs;
            $gx0 = (int) ceil(max($x0, $field->minX) / $a - 0.5);
            $gx1 = (int) ceil(min($x0 + $cs, $field->maxX) / $a - 0.5) - 1;
            $gz0 = (int) ceil(max($z0, $field->minZ) / $a - 0.5);
            $gz1 = (int) ceil(min($z0 + $cs, $field->maxZ) / $a - 0.5) - 1;
            $seed = $field->seed;
            $height = $field->height;
            $jitter = max(0.0, min(0.9, $field->heightJitter));
            $sink = $field->sink;
            $maskOpen = $field->mask->isOpen();
            for ($gx = $gx0; $gx <= $gx1; $gx++) {
                for ($gz = $gz0; $gz <= $gz1; $gz++) {
                    $h = crc32($seed . ':' . $gx . ':' . $gz);
                    $h = ($h * 1103515245 + 12345) & 0x7fffffff;
                    $rx = $h / 2147483648.0;
                    $h = ($h * 1103515245 + 12345) & 0x7fffffff;
                    $rz = $h / 2147483648.0;
                    $x = ($gx + 0.5 + ($rx - 0.5) * 0.9) * $a;
                    $z = ($gz + 0.5 + ($rz - 0.5) * 0.9) * $a;
                    $h = ($h * 1103515245 + 12345) & 0x7fffffff;
                    $keep = $h / 2147483648.0;
                    $m = $maskOpen ? 1.0 : $field->mask->factor($x, $z);
                    if ($keep >= $m * $keepBase) {
                        continue;
                    }
                    $y = $field->groundHeight($x, $z);
                    if ($y === null) {
                        continue;
                    }
                    $y -= $sink;
                    $h = ($h * 1103515245 + 12345) & 0x7fffffff;
                    $yaw = $h / 2147483648.0 * 2.0 * M_PI;
                    $h = ($h * 1103515245 + 12345) & 0x7fffffff;
                    $sc = $height * (1.0 + ($h / 2147483648.0 - 0.5) * 2.0 * $jitter) * (0.55 + 0.45 * $m);
                    $h = ($h * 1103515245 + 12345) & 0x7fffffff;
                    $hash = $h / 2147483648.0;
                    $h = ($h * 1103515245 + 12345) & 0x7fffffff;
                    $variant = $h % $variants;
                    $c = cos($yaw) * $sc;
                    $s = sin($yaw) * $sc;
                    // T * Ry(yaw) * S(sc), column-major; m[3] carries the tuft hash.
                    $rows[$variant][] = [$hash, pack('f16', $c, 0.0, -$s, $hash, 0.0, $sc, 0.0, 0.0, $s, 0.0, $c, 0.0, $x, $y, $z, 1.0)];
                    $y0 = min($y0, $y);
                    $y1 = max($y1, $y);
                }
            }
        }
        $hashes = [];
        $packed = [];
        $any = false;
        foreach ($rows as $v => $list) {
            usort($list, static fn(array $p, array $q): int => $p[0] <=> $q[0]);
            $hashes[$v] = array_column($list, 0);
            $packed[$v] = implode('', array_column($list, 1));
            $any = $any || $list !== [];
        }
        if (!$any) {
            return ['ix' => $ix, 'iz' => $iz, 'y0' => 0.0, 'y1' => 0.0, 'hashes' => [], 'packed' => []];
        }
        return ['ix' => $ix, 'iz' => $iz, 'y0' => $y0, 'y1' => $y1, 'hashes' => array_values($hashes), 'packed' => array_values($packed)];
    }

    /**
     * Drop cached cells far behind the fade range when the cache is too big.
     *
     * @param array<string, array{ix: int, iz: int, y0: float, y1: float, hashes: list<list<float>>, packed: list<string>}> $cells
     * @return array<string, array{ix: int, iz: int, y0: float, y1: float, hashes: list<list<float>>, packed: list<string>}>
     */
    private function evict(array $cells, float $ex, float $ez, float $fade): array
    {
        if (count($cells) <= $this->maxCachedCells) {
            return $cells;
        }
        $cs = max(1.0, $this->cellSize);
        $keep = $fade + 2.0 * $cs;
        foreach ($cells as $key => $cell) {
            $cx = ($cell['ix'] + 0.5) * $cs;
            $cz = ($cell['iz'] + 0.5) * $cs;
            if (($cx - $ex) ** 2 + ($cz - $ez) ** 2 > $keep * $keep) {
                unset($cells[$key]);
            }
        }
        return $cells;
    }

    // ── shared resources ───────────────────────────────────────────────────

    /** Register the tuft meshes of a detail level (once per shape). */
    private function prepare(GrassDetail $detail): void
    {
        $key = $detail->shape->key() . '|' . $detail->farShape()->key() . '|' . $detail->variants;
        if ($key === $this->preparedKey && $this->meshIds !== [] && MeshRegistry::has($this->meshIds[0][0])) {
            return;
        }
        $this->preparedKey = $key;
        $this->meshIds = [];
        $near = $detail->shape;
        $far = $detail->farShape();
        $tag = substr(md5($near->key()), 0, 10);
        $farTag = substr(md5($far->key()), 0, 10);
        for ($v = 0, $n = max(1, $detail->variants); $v < $n; $v++) {
            $seed = 7919 * ($v + 1);
            $nearId = "grass_tuft_{$tag}_v{$v}";
            $farId = "grass_tuft_{$farTag}_v{$v}";
            if (!MeshRegistry::has($nearId)) {
                MeshRegistry::register($nearId, GrassTuftMesh::generate($near, $seed));
            }
            if (!MeshRegistry::has($farId)) {
                MeshRegistry::register($farId, GrassTuftMesh::generate($far, $seed));
            }
            $this->meshIds[] = [$nearId, $farId];
        }
    }

    /** The blade material of a field at a detail level (registered on first use). */
    private function materialFor(GrassField $field, GrassDetail $detail): string
    {
        $b = $field->baseColor;
        $t = $field->tipColor;
        $look = sprintf(
            '%.4f,%.4f,%.4f|%.4f,%.4f,%.4f|%.3f|%.3f|%d|%.3f|%.3f|%.3f',
            $b->r, $b->g, $b->b, $t->r, $t->g, $t->b,
            $detail->translucency, $detail->bend, $detail->colorBands, $detail->variation, $detail->rootShade, $detail->patchScale,
        );
        $id = 'blades#' . substr(md5($look), 0, 12);
        if (!MaterialRegistry::has($id)) {
            MaterialRegistry::register($id, Material::grass(
                base: new Color($b->r, $b->g, $b->b),
                tip: new Color($t->r, $t->g, $t->b),
                translucency: $detail->translucency,
                bend: $detail->bend,
                bands: $detail->colorBands,
                variation: $detail->variation,
                rootShade: $detail->rootShade,
                patchScale: $detail->patchScale,
            ));
        }
        return $id;
    }

    /**
     * The benders nearest to the camera, `[x, y, z, radius]`.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    private function benders(World $world, float $ex, float $ey, float $ez, float $range): array
    {
        $found = [];
        foreach ($world->query(GrassBender::class, Transform3D::class) as $entity) {
            $bender = $entity->get(GrassBender::class);
            if (!$bender->enabled || $bender->radius <= 0.0) {
                continue;
            }
            $p = $entity->get(Transform3D::class)->getWorldPosition();
            $d2 = ($p->x - $ex) ** 2 + ($p->y - $ey) ** 2 + ($p->z - $ez) ** 2;
            if ($d2 > $range * $range) {
                continue;
            }
            $found[] = [$d2, [$p->x, $p->y, $p->z, $bender->radius]];
        }
        usort($found, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return array_column(array_slice($found, 0, SetGrass::MAX_BENDERS), 1);
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

    /**
     * Number of leading entries of an ascending list below `$limit`.
     *
     * @param list<float> $sorted
     */
    public static function countBelow(array $sorted, float $limit): int
    {
        $lo = 0;
        $hi = count($sorted);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($sorted[$mid] < $limit) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    /** GLSL smoothstep - the shader uses the same curve. */
    public static function smoothstep(float $edge0, float $edge1, float $x): float
    {
        $t = max(0.0, min(1.0, ($x - $edge0) / max(1e-6, $edge1 - $edge0)));
        return $t * $t * (3.0 - 2.0 * $t);
    }
}
