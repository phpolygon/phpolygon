<?php

declare(strict_types=1);

namespace PHPolygon\Streaming;

/**
 * Tuning of a {@see CellStreamer}. Radii are in cells (Chebyshev rings around
 * the focus cell), distances in cells, budgets per frame. The defaults of the
 * budgets are design values, not measurements: the owner of a world sets them
 * from its own profile.
 */
final readonly class CellStreamerConfig
{
    /** @var list<int> */
    public array $lodByRing;

    public int $unloadRadius;

    /**
     * @param float     $cellSize            side of a cell in world units
     * @param int       $loadRadius          rings around the focus cell that are wanted (0 = only the cell the focus stands in)
     * @param int|null  $unloadRadius        resident cells beyond this ring are evicted; null = loadRadius + 1 (hysteresis: a cell is
     *                                       not evicted the moment it leaves the load ring, so walking back and forth at a cell
     *                                       border does not reload it)
     * @param list<int> $lodByRing           LOD wanted per ring, index = ring, the last entry repeats; 0 is the finest level.
     *                                       A resident cell is upgraded when its ring wants a finer level, never downgraded
     *                                       (it is evicted instead when it leaves the unload ring)
     * @param int       $prefetchRadius      rings around the look-ahead cell that are wanted as well; 0 switches prefetching off
     * @param float     $prefetchDistance    how far ahead along the movement direction the look-ahead point lies, in cells
     * @param float     $prefetchPenalty     cells added to the priority of look-ahead cells, so the ring around the focus is
     *                                       served first
     * @param int       $maxInFlight         jobs outstanding at once: submitted to the pool plus finished and waiting for their turn to apply
     * @param int       $maxRequestsPerFrame most new jobs one frame submits
     * @param int       $uploadBudgetBytes   bytes of results installed per frame; the first result of a frame is installed even when it alone
     *                                       is larger, so a big cell cannot starve
     * @param float     $frameBudgetMs       milliseconds of streaming glue per frame; installing results stops when it is used up (one
     *                                       result is not interruptible: a single big install can overshoot)
     * @param int       $maxRetries          times a failed cell is requested again before it is given up until the next invalidate()
     * @param int|null  $gridWidth           cells along x (null = unbounded); cells outside the grid are never wanted
     * @param int|null  $gridHeight          cells along z (null = unbounded)
     */
    public function __construct(
        public float $cellSize,
        public int $loadRadius = 3,
        ?int $unloadRadius = null,
        array $lodByRing = [0],
        public int $prefetchRadius = 1,
        public float $prefetchDistance = 1.5,
        public float $prefetchPenalty = 1.0,
        public int $maxInFlight = 8,
        public int $maxRequestsPerFrame = 4,
        public int $uploadBudgetBytes = 8 * 1024 * 1024,
        public float $frameBudgetMs = 2.0,
        public int $maxRetries = 1,
        public ?int $gridWidth = null,
        public ?int $gridHeight = null,
        public float $originX = 0.0,
        public float $originZ = 0.0,
    ) {
        if ($cellSize <= 0.0) {
            throw new \InvalidArgumentException('CellStreamerConfig: cellSize must be positive.');
        }
        if ($loadRadius < 0 || $prefetchRadius < 0) {
            throw new \InvalidArgumentException('CellStreamerConfig: radii must not be negative.');
        }
        $unload = $unloadRadius ?? $loadRadius + 1;
        if ($unload < $loadRadius) {
            throw new \InvalidArgumentException('CellStreamerConfig: unloadRadius must not be smaller than loadRadius.');
        }
        if ($lodByRing === []) {
            throw new \InvalidArgumentException('CellStreamerConfig: lodByRing must not be empty.');
        }
        if ($maxInFlight < 1 || $maxRequestsPerFrame < 1) {
            throw new \InvalidArgumentException('CellStreamerConfig: maxInFlight and maxRequestsPerFrame must be at least 1.');
        }
        $this->unloadRadius = $unload;
        $this->lodByRing = $lodByRing;
    }

    public function lodForRing(int $ring): int
    {
        return $this->lodByRing[min($ring, count($this->lodByRing) - 1)];
    }
}
