<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Command\DrawMeshInstanced;

/**
 * Persistent packed instance-matrix buffers, one per (cell, mesh, material),
 * for a world that streams in cells: the instances of a cell arrive as ONE
 * string from a worker ({@see set()}), single instances are added, replaced
 * and removed in place, and the buffers turn into {@see DrawMeshInstanced}
 * commands that already carry their culling bounds.
 *
 * ## Data
 *
 * A buffer is `count * 64` bytes: column-major f32 matrices, the format of
 * {@see DrawMeshInstanced::packed()}. 1,000,000 instances are 61 MB of PHP
 * strings (measured, `benchmarks/streaming-glue.php instances`; a `Mat4[]` of
 * the same needs several hundred bytes per instance), so residency is a number
 * you can budget: {@see instanceCount()}, {@see byteSize()}, and an optional
 * hard cap ({@see __construct() $maxInstances}) that makes {@see set()} /
 * {@see append()} throw instead of growing past it ({@see fits()} asks first).
 *
 * Instances are addressed by index. {@see removeAt()} swaps the LAST instance
 * into the hole (O(1) bookkeeping, no gap) and tells the caller which index
 * moved; a caller that tracks identities keeps its own id-to-index map. PHP
 * strings are values: an edit made after the buffer was admitted (the
 * renderer-side snapshot shares the string) copies the whole buffer once, so a
 * single edit costs time proportional to the buffer, and many edits between two
 * {@see commands()} calls cost one copy. Rebuild a cell through {@see set()}
 * when many instances change.
 *
 * ## Bounds
 *
 * Every emitted command carries a {@see BatchBounds} sphere, so the renderer
 * culls a cell as one unit without walking its matrices (a non-static batch
 * above {@see InstancedBatchCuller::DYNAMIC_DERIVE_MAX} would otherwise never
 * be culled). The buffer keeps the box around its translations and the
 * largest stretch ({@see BatchBounds::extentsOfPacked()}) - a worker can
 * measure that next to the bytes and pass it to {@see set()} - and applies the
 * mesh radius when the command is built. A mesh that is not registered gives
 * no bounds (the renderer then derives its own or draws the batch). Appending
 * and replacing grow the box in place; removing leaves it as it was (larger is
 * still correct), and {@see commands()} re-measures a small buffer (up to
 * {@see REMEASURE_MAX} instances) once a quarter of its instances were removed
 * or replaced. Measuring costs about 0.7 microseconds per instance, so
 * {@see set()} without extents on a big buffer stalls the calling thread: have
 * the worker measure.
 *
 * ## Upload budget
 *
 * Changed bytes do not reach the renderer all at once: {@see commands()} takes
 * the cells nearest-first and a byte budget, and "admits" a buffer's pending
 * change (the range of bytes written since its last admission) only while the
 * budget lasts; the first change of a frame is always admitted, so a buffer
 * larger than the budget cannot starve. A deferred buffer keeps drawing its
 * last admitted state (instances and count both), a buffer never admitted is
 * not drawn yet. {@see removeCell()} takes effect at once.
 *
 * What the budget really throttles depends on the path:
 *  - With an {@see InstanceBufferSink} the admitted range is exactly what is
 *    written into the GPU-resident buffer, an append costs 64 bytes. This is
 *    the persistent path.
 *  - Without a sink the commands are plain packed batches and the renderer
 *    copies the WHOLE string of every drawn buffer to the GPU on every frame
 *    (`VioRenderer3D` never caches packed batches; php-vio's `vio_draw_instanced`
 *    memcpys them into a shared dynamic buffer per draw). The budget then only
 *    paces when new data becomes visible; it does not bound GPU traffic. The
 *    visible-cell count does.
 *
 * @phpstan-import-type Extents from CellInstanceBuffer
 */
final class CellInstanceBuffers
{
    public const int MATRIX_BYTES = 64;

    /**
     * Largest buffer {@see commands()} re-measures on the calling thread once a
     * quarter of it was removed or replaced (about 0.7 microseconds per instance,
     * measured). Bigger buffers keep their old, larger-than-needed bounds until the
     * caller rebuilds them with {@see set()} and extents measured in a worker.
     */
    public const int REMEASURE_MAX = 1024;

    /** @var array<string, array<string, CellInstanceBuffer>> cell => "mesh\0material" => buffer */
    private array $cells = [];

    private int $instances = 0;

    private int $bytes = 0;

    /** @var array{admittedBytes: int, admittedBuffers: int, deferredBuffers: int, deferredBytes: int, emitted: int} */
    private array $frame = ['admittedBytes' => 0, 'admittedBuffers' => 0, 'deferredBuffers' => 0, 'deferredBytes' => 0, 'emitted' => 0];

    public function __construct(
        private readonly int $maxInstances = PHP_INT_MAX,
        private readonly ?InstanceBufferSink $sink = null,
    ) {}

    /**
     * Replace the whole buffer of (cell, mesh, material).
     *
     * @param string $packed  count * 64 bytes of column-major f32 matrices
     * @param Extents|null $extents {@see BatchBounds::extentsOfPacked()} of $packed when the caller (a worker) already measured it
     * @throws \InvalidArgumentException when the length is not a multiple of 64
     * @throws \OverflowException when the resident instance cap would be exceeded
     */
    public function set(string $cell, string $meshId, string $materialId, string $packed, ?array $extents = null): void
    {
        $len = strlen($packed);
        if ($len % self::MATRIX_BYTES !== 0) {
            throw new \InvalidArgumentException("CellInstanceBuffers: {$len} bytes is not a whole number of 64-byte matrices.");
        }
        $count = intdiv($len, self::MATRIX_BYTES);
        $this->guardCapacity($count - ($this->cells[$cell][self::sub($meshId, $materialId)]->count ?? 0));
        $buf = $this->buffer($cell, $meshId, $materialId);

        $this->instances += $count - $buf->count;
        $this->bytes += $len - strlen($buf->data);
        $buf->data = $packed;
        $buf->count = $count;
        $buf->extents = $count === 0 ? null : ($extents ?? BatchBounds::extentsOfPacked($packed, $count));
        $buf->loose = 0;
        $buf->dirtyFrom = 0;
        $buf->dirtyTo = $len;
    }

    /**
     * Add one instance at the end.
     *
     * @param string|Mat4|array<float> $matrix 64 packed bytes, a Mat4 or 16 floats (column-major)
     * @return int the new instance's index
     * @throws \OverflowException when the resident instance cap would be exceeded
     */
    public function append(string $cell, string $meshId, string $materialId, string|Mat4|array $matrix): int
    {
        $bytes = self::matrixBytes($matrix);
        $this->guardCapacity(1);

        $buf = $this->buffer($cell, $meshId, $materialId);
        $index = $buf->count;
        $from = strlen($buf->data);
        $buf->data .= $bytes;
        $buf->count++;
        $buf->extents = self::mergeExtents($buf->extents, BatchBounds::extentsOfPacked($bytes, 1));
        $buf->markDirty($from, $from + self::MATRIX_BYTES);
        $this->instances++;
        $this->bytes += self::MATRIX_BYTES;

        return $index;
    }

    /**
     * Overwrite instance $index.
     *
     * @param string|Mat4|array<float> $matrix
     * @throws \OutOfRangeException
     */
    public function replaceAt(string $cell, string $meshId, string $materialId, int $index, string|Mat4|array $matrix): void
    {
        $bytes = self::matrixBytes($matrix);
        $buf = $this->existing($cell, $meshId, $materialId, $index);
        $at = $index * self::MATRIX_BYTES;
        $buf->data = substr_replace($buf->data, $bytes, $at, self::MATRIX_BYTES);
        $buf->extents = self::mergeExtents($buf->extents, BatchBounds::extentsOfPacked($bytes, 1));
        $buf->loose++;
        $buf->markDirty($at, $at + self::MATRIX_BYTES);
    }

    /**
     * Remove instance $index by moving the last instance into its place.
     *
     * @return int|null the index the moved instance came from (it now lives at
     *                  $index), or null when the removed one was the last
     * @throws \OutOfRangeException
     */
    public function removeAt(string $cell, string $meshId, string $materialId, int $index): ?int
    {
        $buf = $this->existing($cell, $meshId, $materialId, $index);
        $last = $buf->count - 1;
        $moved = null;
        if ($index !== $last) {
            $buf->data = substr_replace($buf->data, substr($buf->data, $last * self::MATRIX_BYTES, self::MATRIX_BYTES), $index * self::MATRIX_BYTES, self::MATRIX_BYTES);
            $buf->markDirty($index * self::MATRIX_BYTES, ($index + 1) * self::MATRIX_BYTES);
            $moved = $last;
        }
        $buf->data = substr($buf->data, 0, $last * self::MATRIX_BYTES);
        $buf->count = $last;
        $buf->loose++;
        // Bytes beyond the new end are never written; a pending range must not reach past it.
        $buf->dirtyTo = min($buf->dirtyTo, $last * self::MATRIX_BYTES);
        $this->instances--;
        $this->bytes -= self::MATRIX_BYTES;

        return $moved;
    }

    /**
     * Drop every buffer of a cell (the cell streamed out). Takes effect at the
     * next {@see commands()}, and frees the sink's buffers.
     *
     * @return int instances freed
     */
    public function removeCell(string $cell): int
    {
        $freed = 0;
        foreach ($this->cells[$cell] ?? [] as $buf) {
            $freed += $buf->count;
            $this->bytes -= strlen($buf->data);
            $this->sink?->release($buf->key());
        }
        $this->instances -= $freed;
        unset($this->cells[$cell]);

        return $freed;
    }

    public function hasCell(string $cell): bool
    {
        return isset($this->cells[$cell]);
    }

    /** @return list<string> */
    public function cells(): array
    {
        return array_keys($this->cells);
    }

    /** Resident instances, of one cell or all. */
    public function instanceCount(?string $cell = null): int
    {
        if ($cell === null) {
            return $this->instances;
        }
        $n = 0;
        foreach ($this->cells[$cell] ?? [] as $buf) {
            $n += $buf->count;
        }

        return $n;
    }

    /** Bytes held in buffer strings (64 per instance). */
    public function byteSize(): int
    {
        return $this->bytes;
    }

    /** Whether $extra more instances stay within the cap. */
    public function fits(int $extra): bool
    {
        return $this->instances + $extra <= $this->maxInstances;
    }

    /** The packed matrices of one buffer, or null when it does not exist. */
    public function packed(string $cell, string $meshId, string $materialId): ?string
    {
        return ($this->cells[$cell][self::sub($meshId, $materialId)] ?? null)?->data;
    }

    /** Bytes written since the last admission, summed over all buffers (what the next frames still have to admit). */
    public function pendingBytes(): int
    {
        $n = 0;
        foreach ($this->cells as $buffers) {
            foreach ($buffers as $buf) {
                $n += $buf->dirtyBytes();
            }
        }

        return $n;
    }

    /**
     * What the last {@see commands()} did: bytes and buffers admitted, buffers
     * left waiting (and their bytes), commands emitted.
     *
     * @return array{admittedBytes: int, admittedBuffers: int, deferredBuffers: int, deferredBytes: int, emitted: int}
     */
    public function lastFrame(): array
    {
        return $this->frame;
    }

    /**
     * Admit pending changes within $uploadBudgetBytes and return the draw
     * commands of the given cells.
     *
     * @param iterable<string>|null $cellsNearestFirst cells to draw, in the order they should be served
     *        when the budget runs out (null: every resident cell, in insertion order)
     * @return list<DrawMeshInstanced>
     */
    public function commands(?iterable $cellsNearestFirst = null, int $uploadBudgetBytes = PHP_INT_MAX): array
    {
        $this->frame = ['admittedBytes' => 0, 'admittedBuffers' => 0, 'deferredBuffers' => 0, 'deferredBytes' => 0, 'emitted' => 0];
        $out = [];
        foreach ($cellsNearestFirst ?? array_keys($this->cells) as $cell) {
            foreach ($this->cells[$cell] ?? [] as $buf) {
                $cost = $buf->dirtyBytes();
                if ($cost > 0 || $buf->count !== $buf->admittedCount) {
                    if ($this->frame['admittedBuffers'] === 0 || $cost <= $uploadBudgetBytes - $this->frame['admittedBytes']) {
                        $this->admit($buf);
                        $this->frame['admittedBytes'] += $cost;
                        $this->frame['admittedBuffers']++;
                    } else {
                        $this->frame['deferredBuffers']++;
                        $this->frame['deferredBytes'] += $cost;
                    }
                }
                if ($buf->admittedCount === 0) {
                    continue;
                }
                $cmd = $this->command($buf);
                if ($cmd !== null) {
                    $out[] = $cmd;
                    $this->frame['emitted']++;
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------

    private function admit(CellInstanceBuffer $buf): void
    {
        if ($buf->loose * 4 > max(1, $buf->count) && $buf->count > 0 && $buf->count <= self::REMEASURE_MAX) {
            $buf->extents = BatchBounds::extentsOfPacked($buf->data, $buf->count);
            $buf->loose = 0;
        }
        if ($this->sink !== null) {
            if ($buf->dirtyBytes() > 0) {
                $buf->capacity = max($buf->capacity, strlen($buf->data));
                $this->sink->write($buf->key(), $buf->dirtyFrom, substr($buf->data, $buf->dirtyFrom, $buf->dirtyBytes()), $buf->capacity);
            }
        } else {
            $buf->admittedData = $buf->data;
        }
        $buf->admittedCount = $buf->count;
        $buf->admittedExtents = $buf->extents;
        $buf->clearDirty();
    }

    private function command(CellInstanceBuffer $buf): ?DrawMeshInstanced
    {
        $bounds = null;
        $radius = BatchBounds::meshRadius($buf->meshId);
        if ($radius !== null && $buf->admittedExtents !== null) {
            $bounds = BatchBounds::sphereOfExtents($buf->admittedExtents, $radius);
        }
        if ($this->sink !== null) {
            return $this->sink->drawCommand($buf->key(), $buf->meshId, $buf->materialId, $buf->admittedCount, $bounds);
        }

        // The admitted snapshot, not the live string: the live one may hold changes the budget has not let through yet.
        // Not isStatic: the renderer never caches packed batches anyway, and a static flag would make the batch
        // culler and the ray-traced scene key remember an entry per cell that nothing releases when the cell
        // streams out. The explicit bounds are what culls it.
        return new DrawMeshInstanced(
            meshId: $buf->meshId,
            materialId: $buf->materialId,
            matrices: [],
            isStatic: false,
            instanceCount: $buf->admittedCount,
            packedMatrices: $buf->admittedData ?? '',
            staticKey: $buf->key(),
            bounds: $bounds,
        );
    }

    /** The buffer of (cell, mesh, material), created empty on first use. */
    private function buffer(string $cell, string $meshId, string $materialId): CellInstanceBuffer
    {
        return $this->cells[$cell][self::sub($meshId, $materialId)] ??= new CellInstanceBuffer($cell, $meshId, $materialId);
    }

    private function existing(string $cell, string $meshId, string $materialId, int $index): CellInstanceBuffer
    {
        $buf = $this->cells[$cell][self::sub($meshId, $materialId)] ?? null;
        if ($buf === null || $index < 0 || $index >= $buf->count) {
            throw new \OutOfRangeException("CellInstanceBuffers: no instance {$index} in ({$cell}, {$meshId}, {$materialId}).");
        }

        return $buf;
    }

    /**
     * @param Extents|null $a
     * @param Extents|null $b
     * @return Extents|null
     */
    private static function mergeExtents(?array $a, ?array $b): ?array
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return [
            min($a[0], $b[0]), min($a[1], $b[1]), min($a[2], $b[2]),
            max($a[3], $b[3]), max($a[4], $b[4]), max($a[5], $b[5]),
            max($a[6], $b[6]),
        ];
    }

    /** @param string|Mat4|array<float> $matrix */
    private static function matrixBytes(string|Mat4|array $matrix): string
    {
        if (is_string($matrix)) {
            if (strlen($matrix) !== self::MATRIX_BYTES) {
                throw new \InvalidArgumentException('CellInstanceBuffers: a matrix string must be exactly 64 bytes.');
            }

            return $matrix;
        }
        $floats = $matrix instanceof Mat4 ? $matrix->toArray() : $matrix;
        if (count($floats) !== 16) {
            throw new \InvalidArgumentException('CellInstanceBuffers: a matrix array must hold 16 floats.');
        }

        return pack('g16', ...$floats);
    }

    private static function sub(string $meshId, string $materialId): string
    {
        return $meshId . "\0" . $materialId;
    }

    private function guardCapacity(int $extra): void
    {
        if ($extra > 0 && !$this->fits($extra)) {
            throw new \OverflowException("CellInstanceBuffers: {$extra} more instances would pass the cap of {$this->maxInstances} (resident: {$this->instances}).");
        }
    }
}
