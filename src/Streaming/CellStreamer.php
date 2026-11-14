<?php

declare(strict_types=1);

namespace PHPolygon\Streaming;

use PHPolygon\Thread\AsyncJobPool;
use PHPolygon\Thread\AsyncJobResult;

/**
 * Decides which cells of a gridded world should be resident around a moving
 * focus, asks a worker pool to build the missing ones, and installs the
 * results on the main thread within a per-frame time and byte budget.
 *
 * It owns no geometry, no GPU and no game rules: what a cell IS lives in two
 * closures the game passes in - `payload` builds the job input, `apply`
 * installs a finished result (register meshes, {@see \PHPolygon\Rendering\CellInstanceBuffers::set()},
 * {@see \PHPolygon\Physics\CellColliderGrid::add()}), `evict` takes a cell
 * out again. The building itself is a handler registered on the
 * {@see AsyncJobPool} the streamer is given.
 *
 * ## What one update() does
 *
 *  1. Works out the focus cell and a look-ahead cell (the focus position
 *     pushed along the smoothed movement direction), and from them the WANTED
 *     set: every cell within `loadRadius` rings of the focus cell and within
 *     `prefetchRadius` rings of the look-ahead cell, each with the LOD of its
 *     ring ({@see CellStreamerConfig::$lodByRing}) and a priority (distance in
 *     cells, look-ahead cells plus a penalty). Recomputed only when one of the
 *     two cells changes.
 *  2. Collects finished jobs. A result is kept only if it is of the current
 *     epoch and its cell is still wanted at that LOD or finer, or at least
 *     kept (inside the unload ring: a cell that just left the load ring is not
 *     abandoned, or a focus jittering over a cell border would cancel and
 *     re-request the same cells every frame); anything else is dropped and
 *     counted.
 *  3. Evicts resident cells outside `unloadRadius` rings of the focus (and of
 *     the look-ahead cell's prefetch rings), cancelling their jobs.
 *  4. Installs ready results nearest-first until the byte budget or the time
 *     budget is spent. The first result of a frame is always installed.
 *  5. Submits jobs for wanted cells that are neither resident (at the wanted
 *     LOD or finer, current epoch) nor already in flight, nearest first, up to
 *     `maxRequestsPerFrame` and `maxInFlight`.
 *
 * ## One pool per streamer
 *
 * {@see invalidate()} raises the pool's epoch floor ({@see AsyncJobPool::cancelOlderThan()}) and the
 * streamer cancels jobs by cell key across every handler, so the pool must not be shared with another
 * user whose jobs carry their own epochs or keys: they would be cancelled along with the streamer's.
 * Give each streamer its own pool (or a second streamer its own).
 *
 * ## Guarantees
 *
 *  - A (cell, lod, epoch) is requested at most once while it is resident or in
 *    flight. It is requested again only after the cell was evicted, or after
 *    {@see invalidate()} raised the epoch, or after a failure (up to `maxRetries`).
 *  - After {@see invalidate()} no result of an older epoch is ever installed.
 *  - Per frame, installed bytes stay within `uploadBudgetBytes` except for the
 *    first install, and installing stops once `frameBudgetMs` is used up.
 *
 * ## Epochs
 *
 * The epoch changes when the CONTENT of cells changed (a quality setting, an
 * edited world), not when the focus moves: a teleport needs no invalidate,
 * cells that are no longer wanted simply never get installed. Resident cells of
 * an older epoch keep being drawn until their replacement is installed.
 *
 * Priorities are per cell and fixed when the wanted set is computed: nearest
 * first at cell granularity, not re-sorted every frame as the focus moves
 * inside a cell.
 */
final class CellStreamer
{
    private int $epoch = 1;

    /** @var array<string, array{lod: int, epoch: int, cx: int, cz: int}> */
    private array $resident = [];

    /** @var array<string, array{lod: int, epoch: int, cx: int, cz: int}> */
    private array $inflight = [];

    /** @var array<string, array{lod: int, epoch: int, cx: int, cz: int, value: mixed, size: int}> */
    private array $ready = [];

    /** @var array<string, int> failed attempts per cell */
    private array $failed = [];

    /** @var array<string, array{cx: int, cz: int, lod: int, prio: int}> */
    private array $wanted = [];

    /** @var list<string> wanted keys, nearest first */
    private array $wantedOrder = [];

    private ?int $focusCx = null;

    private ?int $focusCz = null;

    private ?int $aheadCx = null;

    private ?int $aheadCz = null;

    private bool $hasPosition = false;

    private float $lastX = 0.0;

    private float $lastZ = 0.0;

    private float $dirX = 0.0;

    private float $dirZ = 0.0;

    private bool $hasDirection = false;

    private bool $wantedDirty = true;

    private bool $needRequest = true;

    private int $staleCarry = 0;

    /** @var list<string> */
    private array $evictedCarry = [];

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @var \Closure(mixed): int */
    private \Closure $sizeOf;

    /**
     * @param \Closure(int, int, int, int): mixed         $payload job input for (cx, cz, lod, epoch); must be copyable across threads
     * @param \Closure(int, int, int, mixed, int): void   $apply   install a result: (cx, cz, lod, value, epoch)
     * @param \Closure(int, int): void                    $evict   release a cell: (cx, cz)
     * @param \Closure(mixed): int|null                   $sizeOf  bytes a result costs against the upload budget (default: the string length,
     *                                                             or the summed string lengths of an array result, 0 otherwise)
     * @param \Closure(): int|null                        $clock   nanoseconds (default `hrtime(true)`); tests inject a fake one
     */
    public function __construct(
        private readonly CellStreamerConfig $config,
        private readonly AsyncJobPool $pool,
        private readonly string $handler,
        private readonly \Closure $payload,
        private readonly \Closure $apply,
        private readonly \Closure $evict,
        ?\Closure $sizeOf = null,
        ?\Closure $clock = null,
    ) {
        $this->sizeOf = $sizeOf ?? self::defaultSize(...);
        $this->clock = $clock ?? static fn (): int => hrtime(true);
    }

    public static function key(int $cx, int $cz): string
    {
        return $cx . ':' . $cz;
    }

    /** Default upload cost of a result: its length when it is a string, the summed lengths of its string values (one level) when it is an array, else 0. */
    private static function defaultSize(mixed $value): int
    {
        if (is_string($value)) {
            return strlen($value);
        }
        $n = 0;
        if (is_array($value)) {
            foreach ($value as $part) {
                if (is_string($part)) {
                    $n += strlen($part);
                }
            }
        }

        return $n;
    }

    /**
     * Run one frame of streaming around the focus at world position ($x, $z).
     */
    public function update(float $x, float $z): StreamFrame
    {
        $clock = $this->clock;
        $t0 = $clock();
        $budgetNs = (int) ($this->config->frameBudgetMs * 1_000_000);
        $stale = $this->staleCarry;
        $this->staleCarry = 0;
        $failed = 0;
        $evicted = $this->evictedCarry;
        $this->evictedCarry = [];

        $this->track($x, $z);

        foreach ($this->pool->collect() as $result) {
            $outcome = $this->take($result);
            if ($outcome === 'stale') {
                $stale++;
            } elseif ($outcome === 'failed') {
                $failed++;
            }
        }

        // Cells the focus has moved clear of: their pending work is moot. Cells that merely left the
        // load ring (still inside the unload ring) keep theirs, or a focus jittering over a cell border
        // would cancel and re-request the same cells every frame.
        foreach ($this->inflight as $key => $job) {
            if (!$this->kept($job['cx'], $job['cz'])) {
                $this->pool->cancel($key);
                unset($this->inflight[$key]);
                $this->needRequest = true;
            }
        }
        foreach ($this->ready as $key => $item) {
            if (!$this->kept($item['cx'], $item['cz'])) {
                unset($this->ready[$key]);
                $stale++;
                $this->needRequest = true;
            }
        }

        array_push($evicted, ...$this->evictFar());

        [$applied, $appliedBytes, $droppedAtApply] = $this->install($t0, $budgetNs);
        $stale += $droppedAtApply;

        $requested = $this->request();

        return new StreamFrame(
            $this->epoch,
            $requested,
            $applied,
            $evicted,
            $stale,
            $failed,
            $appliedBytes,
            $clock() - $t0,
            count($this->inflight) + count($this->ready),
        );
    }

    /**
     * The content of cells changed: results of every older epoch are void.
     * Pending jobs are cancelled, finished-but-uninstalled results are dropped,
     * and every wanted cell is requested again at the new epoch. Resident cells
     * stay (and keep drawing) until their replacement is installed, unless
     * $dropResident evicts them at once - the evictions are reported by the next
     * update().
     */
    public function invalidate(bool $dropResident = false): void
    {
        $this->epoch++;
        $this->pool->cancelOlderThan($this->epoch);
        $this->inflight = [];
        $this->staleCarry += count($this->ready);
        $this->ready = [];
        $this->failed = [];
        if ($dropResident) {
            foreach ($this->resident as $key => $cell) {
                ($this->evict)($cell['cx'], $cell['cz']);
                $this->evictedCarry[] = $key;
            }
            $this->resident = [];
        }
        $this->needRequest = true;
    }

    /**
     * Evict every resident cell (a scene change); keeps the epoch.
     *
     * @return list<string> the evicted keys
     */
    public function clear(): array
    {
        $out = [];
        foreach ($this->resident as $key => $cell) {
            ($this->evict)($cell['cx'], $cell['cz']);
            $out[] = $key;
        }
        $this->resident = [];
        foreach (array_keys($this->inflight) as $key) {
            $this->pool->cancel($key);
        }
        $this->inflight = [];
        $this->ready = [];
        $this->needRequest = true;

        return $out;
    }

    public function epoch(): int
    {
        return $this->epoch;
    }

    /** @return array<string, int> resident cell key => LOD */
    public function residentCells(): array
    {
        return array_map(static fn (array $c): int => $c['lod'], $this->resident);
    }

    /** @return array<string, int> wanted cell key => LOD */
    public function wantedCells(): array
    {
        return array_map(static fn (array $c): int => $c['lod'], $this->wanted);
    }

    /** Whether every wanted cell is resident at its wanted LOD and nothing is in flight. */
    public function isSettled(): bool
    {
        if ($this->inflight !== [] || $this->ready !== []) {
            return false;
        }
        foreach ($this->wanted as $key => $w) {
            $r = $this->resident[$key] ?? null;
            if ($r === null || $r['lod'] > $w['lod'] || $r['epoch'] !== $this->epoch) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------

    private function track(float $x, float $z): void
    {
        $cs = $this->config->cellSize;
        if ($this->hasPosition) {
            $dx = $x - $this->lastX;
            $dz = $z - $this->lastZ;
            $len = sqrt($dx * $dx + $dz * $dz);
            if ($len > 1e-9) {
                $nx = $dx / $len;
                $nz = $dz / $len;
                if ($this->hasDirection) {
                    // Smoothed, so a jittering focus does not flip the look-ahead cell every frame.
                    $mx = 0.7 * $this->dirX + 0.3 * $nx;
                    $mz = 0.7 * $this->dirZ + 0.3 * $nz;
                    $ml = sqrt($mx * $mx + $mz * $mz);
                    if ($ml > 1e-9) {
                        $nx = $mx / $ml;
                        $nz = $mz / $ml;
                    }
                }
                $this->dirX = $nx;
                $this->dirZ = $nz;
                $this->hasDirection = true;
            }
        }
        $this->lastX = $x;
        $this->lastZ = $z;
        $this->hasPosition = true;

        $cx = (int) floor(($x - $this->config->originX) / $cs);
        $cz = (int) floor(($z - $this->config->originZ) / $cs);
        $ax = $az = null;
        if ($this->config->prefetchRadius > 0 && $this->hasDirection) {
            $reach = $this->config->prefetchDistance * $cs;
            $ax = (int) floor(($x + $this->dirX * $reach - $this->config->originX) / $cs);
            $az = (int) floor(($z + $this->dirZ * $reach - $this->config->originZ) / $cs);
        }

        if ($this->wantedDirty || $cx !== $this->focusCx || $cz !== $this->focusCz || $ax !== $this->aheadCx || $az !== $this->aheadCz) {
            $this->focusCx = $cx;
            $this->focusCz = $cz;
            $this->aheadCx = $ax;
            $this->aheadCz = $az;
            $this->computeWanted($x, $z);
            $this->wantedDirty = false;
            $this->needRequest = true;
        }
    }

    private function computeWanted(float $x, float $z): void
    {
        $cfg = $this->config;
        $cs = $cfg->cellSize;
        $fx = $this->focusCx ?? 0;
        $fz = $this->focusCz ?? 0;
        $wanted = [];

        $consider = function (int $cx, int $cz, float $dist) use (&$wanted, $fx, $fz, $cfg): void {
            if (($cfg->gridWidth !== null && ($cx < 0 || $cx >= $cfg->gridWidth))
                || ($cfg->gridHeight !== null && ($cz < 0 || $cz >= $cfg->gridHeight))
            ) {
                return;
            }
            $key = self::key($cx, $cz);
            $prio = (int) round($dist * 100.0);
            if (isset($wanted[$key]) && $wanted[$key]['prio'] <= $prio) {
                return;
            }
            $ring = max(abs($cx - $fx), abs($cz - $fz));
            $wanted[$key] = ['cx' => $cx, 'cz' => $cz, 'lod' => $cfg->lodForRing($ring), 'prio' => $prio];
        };

        $px = ($x - $cfg->originX) / $cs;
        $pz = ($z - $cfg->originZ) / $cs;
        $r = $cfg->loadRadius;
        for ($dz = -$r; $dz <= $r; $dz++) {
            for ($dx = -$r; $dx <= $r; $dx++) {
                $cx = $fx + $dx;
                $cz = $fz + $dz;
                $consider($cx, $cz, sqrt(($cx + 0.5 - $px) ** 2 + ($cz + 0.5 - $pz) ** 2));
            }
        }

        if ($this->aheadCx !== null && $this->aheadCz !== null) {
            $reach = $cfg->prefetchDistance * $cs;
            $qx = $px + $this->dirX * $reach / $cs;
            $qz = $pz + $this->dirZ * $reach / $cs;
            $p = $cfg->prefetchRadius;
            for ($dz = -$p; $dz <= $p; $dz++) {
                for ($dx = -$p; $dx <= $p; $dx++) {
                    $cx = $this->aheadCx + $dx;
                    $cz = $this->aheadCz + $dz;
                    $consider($cx, $cz, sqrt(($cx + 0.5 - $qx) ** 2 + ($cz + 0.5 - $qz) ** 2) + $cfg->prefetchPenalty);
                }
            }
        }

        uasort($wanted, static fn (array $a, array $b): int => [$a['prio'], $a['cx'], $a['cz']] <=> [$b['prio'], $b['cx'], $b['cz']]);
        $this->wanted = $wanted;
        $this->wantedOrder = array_keys($wanted);
    }

    /** @return 'kept'|'stale'|'failed' */
    private function take(AsyncJobResult $r): string
    {
        $key = $r->key;
        $flight = $this->inflight[$key] ?? null;
        if ($flight === null || $flight['lod'] !== $r->lod || $flight['epoch'] !== $r->epoch) {
            // Replaced or cancelled after the result was already on its way.
            return 'stale';
        }
        unset($this->inflight[$key]);
        $this->needRequest = true;

        if (!$r->isOk()) {
            $this->failed[$key] = ($this->failed[$key] ?? 0) + 1;

            return 'failed';
        }
        if (!$this->acceptable($key, $r->lod, $r->epoch, $flight['cx'], $flight['cz'])) {
            return 'stale';
        }
        $this->ready[$key] = [
            'lod' => $r->lod,
            'epoch' => $r->epoch,
            'cx' => $flight['cx'],
            'cz' => $flight['cz'],
            'value' => $r->value,
            'size' => ($this->sizeOf)($r->value),
        ];

        return 'kept';
    }

    /**
     * Whether a finished result is still worth installing: current epoch, and either
     * its cell is wanted at this LOD or finer, or it is merely kept (inside the unload ring).
     */
    private function acceptable(string $key, int $lod, int $epoch, int $cx, int $cz): bool
    {
        if ($epoch !== $this->epoch) {
            return false;
        }
        $w = $this->wanted[$key] ?? null;

        return $w !== null ? $lod <= $w['lod'] : $this->kept($cx, $cz);
    }

    /** Inside the unload ring of the focus, or inside a prefetch ring of the look-ahead cell. */
    private function kept(int $cx, int $cz): bool
    {
        if (max(abs($cx - ($this->focusCx ?? 0)), abs($cz - ($this->focusCz ?? 0))) <= $this->config->unloadRadius) {
            return true;
        }

        return $this->aheadCx !== null && $this->aheadCz !== null
            && max(abs($cx - $this->aheadCx), abs($cz - $this->aheadCz)) <= $this->config->prefetchRadius;
    }

    /** @return list<string> */
    private function evictFar(): array
    {
        $out = [];
        foreach ($this->resident as $key => $c) {
            if ($this->kept($c['cx'], $c['cz'])) {
                continue;
            }
            ($this->evict)($c['cx'], $c['cz']);
            unset($this->resident[$key]);
            $out[] = $key;
            $this->needRequest = true;
        }

        return $out;
    }

    /**
     * @return array{0: list<array{0: string, 1: int}>, 1: int, 2: int} applied, bytes, dropped
     */
    private function install(int $t0, int $budgetNs): array
    {
        if ($this->ready === []) {
            return [[], 0, 0];
        }
        // Wanted cells nearest first, then the ones that are only kept (they left the load ring but arrived anyway).
        $order = [];
        foreach ($this->wantedOrder as $key) {
            if (isset($this->ready[$key])) {
                $order[] = $key;
            }
        }
        foreach (array_keys($this->ready) as $key) {
            if (!isset($this->wanted[$key])) {
                $order[] = $key;
            }
        }
        $applied = [];
        $bytes = 0;
        $dropped = 0;
        $clock = $this->clock;
        foreach ($order as $key) {
            $item = $this->ready[$key];
            if (!$this->acceptable($key, $item['lod'], $item['epoch'], $item['cx'], $item['cz'])) {
                unset($this->ready[$key]);
                $dropped++;
                continue;
            }
            if ($applied !== []) {
                if ($bytes + $item['size'] > $this->config->uploadBudgetBytes || $clock() - $t0 >= $budgetNs) {
                    break;
                }
            }
            unset($this->ready[$key]);
            ($this->apply)($item['cx'], $item['cz'], $item['lod'], $item['value'], $item['epoch']);
            $this->resident[$key] = ['lod' => $item['lod'], 'epoch' => $item['epoch'], 'cx' => $item['cx'], 'cz' => $item['cz']];
            unset($this->failed[$key]);
            $applied[] = [$key, $item['lod']];
            $bytes += $item['size'];
            $this->needRequest = true;
        }

        return [$applied, $bytes, $dropped];
    }

    /** @return list<array{0: string, 1: int}> */
    private function request(): array
    {
        if (!$this->needRequest) {
            return [];
        }
        $cfg = $this->config;
        $slots = min($cfg->maxRequestsPerFrame, $cfg->maxInFlight - count($this->inflight) - count($this->ready));
        $out = [];
        $leftover = false;
        foreach ($this->wantedOrder as $key) {
            $w = $this->wanted[$key];
            $res = $this->resident[$key] ?? null;
            if ($res !== null && $res['epoch'] === $this->epoch && $res['lod'] <= $w['lod']) {
                continue;
            }
            $fly = $this->inflight[$key] ?? null;
            if ($fly !== null && $fly['epoch'] === $this->epoch && $fly['lod'] <= $w['lod']) {
                continue;
            }
            $rdy = $this->ready[$key] ?? null;
            if ($rdy !== null && $rdy['lod'] <= $w['lod']) {
                continue;
            }
            if (($this->failed[$key] ?? 0) > $cfg->maxRetries) {
                continue;
            }
            if ($slots <= 0) {
                $leftover = true;
                break;
            }
            if ($fly !== null) {
                // In flight but coarser than wanted now: replace it.
                $this->pool->cancel($key);
                unset($this->inflight[$key]);
            }
            $input = ($this->payload)($w['cx'], $w['cz'], $w['lod'], $this->epoch);
            if ($this->pool->submit($key, $w['lod'], $this->epoch, $this->handler, $input, $w['prio'])) {
                $this->inflight[$key] = ['lod' => $w['lod'], 'epoch' => $this->epoch, 'cx' => $w['cx'], 'cz' => $w['cz']];
                $out[] = [$key, $w['lod']];
                $slots--;
            }
        }
        $this->needRequest = $leftover;

        return $out;
    }
}
