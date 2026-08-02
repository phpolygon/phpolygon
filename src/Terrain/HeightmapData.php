<?php

declare(strict_types=1);

namespace PHPolygon\Terrain;

use InvalidArgumentException;

/**
 * A regular grid of terrain height samples plus the world-space mapping that
 * turns them into positions.
 *
 * Samples are stored *normalised* to [0, 1] and mapped to world Y through
 * {@see $minHeight}/{@see $maxHeight}. Keeping the payload normalised means the
 * height range is an editable parameter rather than being baked into the data:
 * dragging a "max height" slider rescales the terrain without resampling, and
 * the quantised on-disk form stays lossless with respect to that slider.
 *
 * The grid is row-major with Z as the outer axis:
 *   sample index = z * gridWidth + x
 *
 * Terrain grids are conventionally sized 2^n + 1 (33, 65, 129, 257, …) so that
 * a power-of-two chunk grid divides them evenly and neighbouring chunks *share*
 * their border vertex column/row instead of duplicating it.
 */
final class HeightmapData
{
    /** @var list<float> Normalised samples in [0, 1], indexed z * gridWidth + x. */
    private array $samples;

    /**
     * @param list<float> $samples Normalised [0, 1] height samples, row-major (z-major).
     *                             Length must be exactly gridWidth * gridDepth.
     */
    public function __construct(
        public readonly int $gridWidth,
        public readonly int $gridDepth,
        public readonly float $sizeX,
        public readonly float $sizeZ,
        public readonly float $minHeight,
        public readonly float $maxHeight,
        array $samples,
    ) {
        if ($gridWidth < 2 || $gridDepth < 2) {
            throw new InvalidArgumentException("Heightmap grid must be at least 2x2, got {$gridWidth}x{$gridDepth}");
        }

        $expected = $gridWidth * $gridDepth;
        if (count($samples) !== $expected) {
            throw new InvalidArgumentException(
                'Heightmap sample count mismatch: expected '.$expected.', got '.count($samples)
            );
        }

        $this->samples = $samples;
    }

    /** A flat heightmap with every sample at the given normalised level. */
    public static function flat(
        int $gridWidth = 129,
        int $gridDepth = 129,
        float $sizeX = 256.0,
        float $sizeZ = 256.0,
        float $minHeight = 0.0,
        float $maxHeight = 50.0,
        float $level = 0.0,
    ): self {
        $level = max(0.0, min(1.0, $level));

        return new self(
            $gridWidth,
            $gridDepth,
            $sizeX,
            $sizeZ,
            $minHeight,
            $maxHeight,
            array_fill(0, max(2, $gridWidth) * max(2, $gridDepth), $level),
        );
    }

    /** Normalised sample at a grid cell; out-of-range coordinates clamp to the edge. */
    public function sampleAt(int $x, int $z): float
    {
        $x = max(0, min($this->gridWidth - 1, $x));
        $z = max(0, min($this->gridDepth - 1, $z));

        return $this->samples[$z * $this->gridWidth + $x];
    }

    /** World-space Y at a grid cell; out-of-range coordinates clamp to the edge. */
    public function heightAt(int $x, int $z): float
    {
        return $this->minHeight + $this->sampleAt($x, $z) * ($this->maxHeight - $this->minHeight);
    }

    /**
     * World-space Y at an arbitrary world (X, Z), bilinearly interpolated.
     *
     * The grid is centred on the entity origin, so X spans [-sizeX/2, +sizeX/2]
     * and Z spans [-sizeZ/2, +sizeZ/2]. Positions outside clamp to the border.
     */
    public function heightAtWorld(float $worldX, float $worldZ): float
    {
        $u = ($worldX + $this->sizeX * 0.5) / $this->sizeX;
        $v = ($worldZ + $this->sizeZ * 0.5) / $this->sizeZ;

        $gx = max(0.0, min((float) ($this->gridWidth - 1), $u * ($this->gridWidth - 1)));
        $gz = max(0.0, min((float) ($this->gridDepth - 1), $v * ($this->gridDepth - 1)));

        $x0 = (int) $gx;
        $z0 = (int) $gz;
        $fx = $gx - $x0;
        $fz = $gz - $z0;

        $h00 = $this->heightAt($x0, $z0);
        $h10 = $this->heightAt($x0 + 1, $z0);
        $h01 = $this->heightAt($x0, $z0 + 1);
        $h11 = $this->heightAt($x0 + 1, $z0 + 1);

        return $h00 * (1.0 - $fx) * (1.0 - $fz)
            + $h10 * $fx * (1.0 - $fz)
            + $h01 * (1.0 - $fx) * $fz
            + $h11 * $fx * $fz;
    }

    /**
     * Surface normal at a grid cell, from central differences on the heightmap.
     *
     * Deriving normals from the *height field* rather than from triangle faces
     * is what keeps chunk borders seamless: two adjacent chunks compute the
     * identical normal for their shared vertex, because the computation only
     * looks at heightmap samples and never at which chunk it is building.
     *
     * @return array{float, float, float} Unit-length (nx, ny, nz).
     */
    public function normalAt(int $x, int $z): array
    {
        $stepX = $this->sizeX / ($this->gridWidth - 1);
        $stepZ = $this->sizeZ / ($this->gridDepth - 1);

        // Central difference; clamped sampling at the border degrades to a
        // one-sided difference over a full step, which is the correct slope
        // for the edge quad.
        $dhdx = ($this->heightAt($x + 1, $z) - $this->heightAt($x - 1, $z)) / (2.0 * $stepX);
        $dhdz = ($this->heightAt($x, $z + 1) - $this->heightAt($x, $z - 1)) / (2.0 * $stepZ);

        // n = normalize(cross(tangentZ, tangentX)) for tangents (1,dhdx,0) and
        // (0,dhdz,1), which reduces to (-dhdx, 1, -dhdz).
        $nx = -$dhdx;
        $ny = 1.0;
        $nz = -$dhdz;

        $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
        if ($len < 1e-12) {
            return [0.0, 1.0, 0.0];
        }

        return [$nx / $len, $ny / $len, $nz / $len];
    }

    /** World-space X of grid column $x (grid centred on the origin). */
    public function worldX(int $x): float
    {
        return -$this->sizeX * 0.5 + ($x / ($this->gridWidth - 1)) * $this->sizeX;
    }

    /** World-space Z of grid row $z (grid centred on the origin). */
    public function worldZ(int $z): float
    {
        return -$this->sizeZ * 0.5 + ($z / ($this->gridDepth - 1)) * $this->sizeZ;
    }

    /** @return list<float> Normalised samples, row-major (z-major). */
    public function samples(): array
    {
        return $this->samples;
    }

    /**
     * Encode the normalised samples as base64 of little-endian uint16.
     *
     * 16 bits over the height range is ~1.5 mm of precision on a 100 m range —
     * far below anything visible — at a quarter the size of base64'd float32,
     * which matters because this payload is embedded in the scene document.
     */
    public function encode(): string
    {
        $binary = '';
        foreach ($this->samples as $sample) {
            $quantised = (int) round(max(0.0, min(1.0, $sample)) * 65535.0);
            $binary .= pack('v', $quantised);
        }

        return base64_encode($binary);
    }

    /**
     * Decode a base64 uint16 payload produced by {@see encode()}.
     *
     * A payload that is empty or the wrong length for the grid yields a flat
     * heightmap rather than an error: the component's grid dimensions are
     * independently editable, and a half-typed resolution should not crash the
     * scene — the next rebuild resamples anyway.
     */
    public static function decode(
        string $encoded,
        int $gridWidth,
        int $gridDepth,
        float $sizeX,
        float $sizeZ,
        float $minHeight,
        float $maxHeight,
    ): self {
        $gridWidth = max(2, $gridWidth);
        $gridDepth = max(2, $gridDepth);
        $expected = $gridWidth * $gridDepth;

        $binary = $encoded === '' ? false : base64_decode($encoded, true);
        if ($binary === false || strlen($binary) !== $expected * 2) {
            return self::flat($gridWidth, $gridDepth, $sizeX, $sizeZ, $minHeight, $maxHeight);
        }

        /** @var array<int, int>|false $unpacked */
        $unpacked = unpack('v*', $binary);
        if ($unpacked === false) {
            return self::flat($gridWidth, $gridDepth, $sizeX, $sizeZ, $minHeight, $maxHeight);
        }

        $samples = [];
        foreach ($unpacked as $value) {
            $samples[] = $value / 65535.0;
        }

        return new self($gridWidth, $gridDepth, $sizeX, $sizeZ, $minHeight, $maxHeight, $samples);
    }

    /**
     * Build a heightmap by sampling $fn(worldX, worldZ) → world Y at every grid
     * cell, normalising the result into the given height range.
     *
     * @param callable(float, float): float $fn
     */
    public static function fromFunction(
        callable $fn,
        int $gridWidth = 129,
        int $gridDepth = 129,
        float $sizeX = 256.0,
        float $sizeZ = 256.0,
        float $minHeight = 0.0,
        float $maxHeight = 50.0,
    ): self {
        $gridWidth = max(2, $gridWidth);
        $gridDepth = max(2, $gridDepth);
        $range = $maxHeight - $minHeight;

        $samples = [];
        for ($z = 0; $z < $gridDepth; $z++) {
            $wz = -$sizeZ * 0.5 + ($z / ($gridDepth - 1)) * $sizeZ;
            for ($x = 0; $x < $gridWidth; $x++) {
                $wx = -$sizeX * 0.5 + ($x / ($gridWidth - 1)) * $sizeX;
                $height = (float) $fn($wx, $wz);
                $samples[] = abs($range) < 1e-12
                    ? 0.0
                    : max(0.0, min(1.0, ($height - $minHeight) / $range));
            }
        }

        return new self($gridWidth, $gridDepth, $sizeX, $sizeZ, $minHeight, $maxHeight, $samples);
    }
}
