<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

/**
 * A slow ground as a raster: height and ground kind per grid point, measured on
 * first use, read bilinearly.
 *
 * {@see \PHPolygon\System\GrassSystem} asks the ground and the mask once per tuft
 * candidate, about a thousand times per cell. A ground that walks real geometry
 * per point turns that into a frame hitch; through the raster a cell measures its
 * grid points once and every candidate reads four corners. A point is measured
 * when a corner needs it, never a block ahead, so the most a read can cost is four
 * measurements and a cell in slices stays in slices. Hand the same raster to all
 * fields over this ground: the second field measures nothing.
 *
 * Points live in blocks of a fixed size. A block never grows and the table of
 * blocks ends at `$maxBlocks`, the oldest block going first, so memory has a limit
 * and no read pays for a table that doubles. A dropped point is measured again
 * when it is asked for: same sampler, same value.
 *
 * The sampler returns `[height, kind]` per point: height null = no ground there,
 * kind 0 = nothing grows here, 1..n = what lies on top (grass types, materials,
 * whatever the game tells apart).
 *
 * ```php
 * $raster = new GrassGroundRaster($sample);
 * $mask->multiply(static fn(float $x, float $z): float => $raster->coverage($x, $z, 1));
 * new GrassField(groundAt: $raster->heightAt(...), mask: $mask);
 * ```
 *
 * Edges of a height or a kind are soft over one raster step. Call {@see clear()}
 * (and `GrassField::touch()`) when the ground changes.
 */
final class GrassGroundRaster
{
    /** Points per block edge, as a power of two. */
    private const int BLOCK_BITS = 4;
    private const int BLOCK_MASK = (1 << self::BLOCK_BITS) - 1;
    private const int BLOCK_ROW = 1 << self::BLOCK_BITS;
    private const int BLOCK_POINTS = self::BLOCK_ROW * self::BLOCK_ROW;

    /** A block key holds two 32-bit block indices. */
    private const int KEY_SHIFT = 32;
    private const int KEY_MASK = 0xFFFFFFFF;

    /** Largest grid index a key can tell apart, either way. */
    public const float MAX_INDEX = 34359738367.0; // 2^35 - 1

    /** Kind of a point not measured yet. */
    private const int UNMEASURED = -1;

    /** @var array<int, array<int, float|null>> block key => height per point */
    private array $heights = [];

    /** @var array<int, array<int, int>> block key => kind per point */
    private array $kinds = [];

    /** @var \Closure(float, float): array{0: float|null, 1: int} */
    private \Closure $sample;

    /** Grid points measured so far (tests and profiles). */
    public int $samples = 0;

    /**
     * @param callable(float, float): array{0: float|null, 1: int} $sample
     * @param float $step metres between grid points
     * @param int $maxBlocks limit of blocks of 16 x 16 points; 16 kB each, so the
     *     default is about 65 MB or 1 x 1 km at a step of 1 m
     */
    public function __construct(callable $sample, public readonly float $step = 1.0, public readonly int $maxBlocks = 4096)
    {
        if ($step <= 0.0) {
            throw new \InvalidArgumentException('The raster step must be positive.');
        }
        if ($maxBlocks < 1) {
            throw new \InvalidArgumentException('The raster needs at least one block.');
        }
        $this->sample = \Closure::fromCallable($sample);
    }

    /**
     * A raster over a plain height function: kind 1 wherever there is ground.
     *
     * @param callable(float, float): ?float $height
     */
    public static function fromHeight(callable $height, float $step = 1.0, int $maxBlocks = 4096): self
    {
        return new self(static function (float $x, float $z) use ($height): array {
            $y = $height($x, $z);
            return [$y, $y === null ? 0 : 1];
        }, $step, $maxBlocks);
    }

    /**
     * Height at (x, z), bilinear over the corners with ground; null without any.
     *
     * @throws \OutOfRangeException beyond {@see MAX_INDEX} steps
     */
    public function heightAt(float $x, float $z): ?float
    {
        $gx = $x / $this->step;
        $gz = $z / $this->step;
        if (!(abs($gx) < self::MAX_INDEX && abs($gz) < self::MAX_INDEX)) {
            throw self::outOfReach($x, $z, $this->step);
        }
        $ix = (int) floor($gx);
        $iz = (int) floor($gz);
        $fx = $gx - $ix;
        $fz = $gz - $iz;
        $w00 = (1.0 - $fx) * (1.0 - $fz);
        $w10 = $fx * (1.0 - $fz);
        $w01 = (1.0 - $fx) * $fz;
        $w11 = $fx * $fz;

        // Nearly every mesh lies inside one block, measured and with ground throughout.
        $lx = $ix & self::BLOCK_MASK;
        $lz = $iz & self::BLOCK_MASK;
        if ($lx !== self::BLOCK_MASK && $lz !== self::BLOCK_MASK) {
            $key = ($ix >> self::BLOCK_BITS) << self::KEY_SHIFT | (($iz >> self::BLOCK_BITS) & self::KEY_MASK);
            $block = $this->heights[$key] ?? null;
            if ($block !== null) {
                $i = ($lz << self::BLOCK_BITS) | $lx;
                $y00 = $block[$i];
                $y10 = $block[$i + 1];
                $y01 = $block[$i + self::BLOCK_ROW];
                $y11 = $block[$i + self::BLOCK_ROW + 1];
                if ($y00 !== null && $y10 !== null && $y01 !== null && $y11 !== null) {
                    return ($w00 * $y00 + $w10 * $y10 + $w01 * $y01 + $w11 * $y11) / ($w00 + $w10 + $w01 + $w11);
                }
            }
        }

        $sum = 0.0;
        $weight = 0.0;
        foreach ([[$ix, $iz, $w00], [$ix + 1, $iz, $w10], [$ix, $iz + 1, $w01], [$ix + 1, $iz + 1, $w11]] as [$cx, $cz, $w]) {
            $key = ($cx >> self::BLOCK_BITS) << self::KEY_SHIFT | (($cz >> self::BLOCK_BITS) & self::KEY_MASK);
            $i = (($cz & self::BLOCK_MASK) << self::BLOCK_BITS) | ($cx & self::BLOCK_MASK);
            if (($this->kinds[$key][$i] ?? self::UNMEASURED) === self::UNMEASURED) {
                $this->measure($key, $i, $cx, $cz);
            }
            $y = $this->heights[$key][$i];
            if ($y !== null) {
                $sum += $w * $y;
                $weight += $w;
            }
        }
        return $weight > 1e-6 ? $sum / $weight : null;
    }

    /**
     * Share of ground kind `$kind` at (x, z), 0..1, bilinear over the four corners.
     *
     * @throws \OutOfRangeException beyond {@see MAX_INDEX} steps
     */
    public function coverage(float $x, float $z, int $kind): float
    {
        $gx = $x / $this->step;
        $gz = $z / $this->step;
        if (!(abs($gx) < self::MAX_INDEX && abs($gz) < self::MAX_INDEX)) {
            throw self::outOfReach($x, $z, $this->step);
        }
        $ix = (int) floor($gx);
        $iz = (int) floor($gz);
        $fx = $gx - $ix;
        $fz = $gz - $iz;
        return (1.0 - $fx) * (1.0 - $fz) * ($this->kind($ix, $iz) === $kind ? 1.0 : 0.0)
            + $fx * (1.0 - $fz) * ($this->kind($ix + 1, $iz) === $kind ? 1.0 : 0.0)
            + (1.0 - $fx) * $fz * ($this->kind($ix, $iz + 1) === $kind ? 1.0 : 0.0)
            + $fx * $fz * ($this->kind($ix + 1, $iz + 1) === $kind ? 1.0 : 0.0);
    }

    /**
     * Share of any kind above 0 at (x, z), 0..1.
     *
     * @throws \OutOfRangeException beyond {@see MAX_INDEX} steps
     */
    public function anyCoverage(float $x, float $z): float
    {
        $gx = $x / $this->step;
        $gz = $z / $this->step;
        if (!(abs($gx) < self::MAX_INDEX && abs($gz) < self::MAX_INDEX)) {
            throw self::outOfReach($x, $z, $this->step);
        }
        $ix = (int) floor($gx);
        $iz = (int) floor($gz);
        $fx = $gx - $ix;
        $fz = $gz - $iz;
        return (1.0 - $fx) * (1.0 - $fz) * ($this->kind($ix, $iz) > 0 ? 1.0 : 0.0)
            + $fx * (1.0 - $fz) * ($this->kind($ix + 1, $iz) > 0 ? 1.0 : 0.0)
            + (1.0 - $fx) * $fz * ($this->kind($ix, $iz + 1) > 0 ? 1.0 : 0.0)
            + $fx * $fz * ($this->kind($ix + 1, $iz + 1) > 0 ? 1.0 : 0.0);
    }

    /** Blocks in memory (tests and profiles). */
    public function blockCount(): int
    {
        return count($this->kinds);
    }

    /** Forget every measured point (the ground changed). */
    public function clear(): void
    {
        $this->heights = [];
        $this->kinds = [];
    }

    /** A point beyond the keys would read the block of another point: refused on every read. */
    private static function outOfReach(float $x, float $z, float $step): \OutOfRangeException
    {
        return new \OutOfRangeException(sprintf('(%g, %g) is out of reach of a grass raster with a step of %g.', $x, $z, $step));
    }

    private function kind(int $ix, int $iz): int
    {
        $key = ($ix >> self::BLOCK_BITS) << self::KEY_SHIFT | (($iz >> self::BLOCK_BITS) & self::KEY_MASK);
        $i = (($iz & self::BLOCK_MASK) << self::BLOCK_BITS) | ($ix & self::BLOCK_MASK);
        $kind = $this->kinds[$key][$i] ?? self::UNMEASURED;
        return $kind === self::UNMEASURED ? $this->measure($key, $i, $ix, $iz) : $kind;
    }

    private function measure(int $key, int $i, int $ix, int $iz): int
    {
        [$y, $kind] = ($this->sample)($ix * $this->step, $iz * $this->step);
        $this->samples++;
        if (!isset($this->kinds[$key])) {
            if (count($this->kinds) >= $this->maxBlocks) {
                $oldest = (int) array_key_first($this->kinds);
                unset($this->kinds[$oldest], $this->heights[$oldest]);
            }
            $this->kinds[$key] = array_fill(0, self::BLOCK_POINTS, self::UNMEASURED);
            $this->heights[$key] = array_fill(0, self::BLOCK_POINTS, null);
        }
        $this->heights[$key][$i] = $y;
        return $this->kinds[$key][$i] = $y === null ? 0 : $kind;
    }
}
