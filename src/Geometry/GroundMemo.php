<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * A terrain height function with a memo, shareable between several
 * {@see ConformingSurfaceSet}s: a staged family of sets (every stage lays the
 * paving of the stages before it again) samples the same corners over and over,
 * and a ground query is usually the dearest call in the whole build.
 *
 *     $ground = new GroundMemo($terrainHeightAt);
 *     foreach ($stages as $stage) {
 *         $set = new ConformingSurfaceSet($ground, cell: 0.5);   // one memo for all
 *         …
 *     }
 *
 * Points are keyed at 0.1 mm (the same resolution the set's own memo always
 * had): two queries closer than that return the first answer. The wrapped
 * function must be pure – the same point, the same height.
 *
 * A set given a plain callable wraps it in a private GroundMemo of its own, so
 * a lone set behaves exactly as before.
 */
final class GroundMemo
{
    /** @var \Closure(float, float): float */
    private \Closure $ground;

    /** @var array<int, float> */
    private array $memo = [];

    private int $queries = 0;
    private int $misses = 0;

    /** @param callable(float, float): float $ground */
    public function __construct(callable $ground)
    {
        $this->ground = $ground instanceof \Closure ? $ground : \Closure::fromCallable($ground);
    }

    public function __invoke(float $x, float $z): float
    {
        $this->queries++;
        // Integer key: x and z at 0.1 mm side by side (|coordinate| < 100 km).
        $k = (int) round($x * 1e4) * 2147483648 + (int) round($z * 1e4);
        if (isset($this->memo[$k])) {
            return $this->memo[$k];
        }
        $this->misses++;

        return $this->memo[$k] = (float) ($this->ground)($x, $z);
    }

    /** Calls so far, answered from the memo or not. */
    public function queries(): int
    {
        return $this->queries;
    }

    /** Calls that reached the wrapped function. */
    public function misses(): int
    {
        return $this->misses;
    }

    /** Points held in the memo. */
    public function size(): int
    {
        return count($this->memo);
    }
}
