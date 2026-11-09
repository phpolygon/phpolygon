<?php

declare(strict_types=1);

namespace PHPolygon\Terrain;

/**
 * One or more coasts baked into an RGBA8 raster a water shader can sample: how far
 * a point lies from the shoreline and how deep the water is there.
 *
 * Channels (linear, no colour space):
 *  - R: signed shoreline distance, `(d + landRange) / (landRange + seaRange)`;
 *       d > 0 in open water, d < 0 on land and in sunken basins.
 *  - G: water depth `depth / maxDepth`, 0 on land.
 *  - B: coverage, 1 where a coast raster speaks, 0 where none does (open sea
 *       far from every coast: the shader takes it as deep, distant water).
 *  - A: 1.
 *
 * Row 0 is the minimum z, column 0 the minimum x; texel centres sit at
 * `min + (i + 0.5) * size / count`. The shader maps a world position with
 * {@see mapUniform()} and decodes with {@see rangeUniform()}; {@see sample()} is the
 * same decode on the CPU (bilinear, like the sampler), for tests and gameplay
 * queries. Baking is meant for a build step: the coast rasters behind it are slow.
 */
final class CoastTexture
{
    private const string MAGIC = 'PHPOLYGON-COAST-1';

    /** @var list<array{CoastField, float, float}> field, world offset x, world offset z */
    private array $fields = [];

    private string $rgba = '';

    public function __construct(
        public readonly float $minX,
        public readonly float $minZ,
        public readonly float $maxX,
        public readonly float $maxZ,
        public readonly int $width,
        public readonly int $height,
        public readonly float $landRange = 16.0,
        public readonly float $seaRange = 48.0,
        public readonly float $maxDepth = 32.0,
    ) {
        if ($width < 2 || $height < 2 || $maxX <= $minX || $maxZ <= $minZ) {
            throw new \InvalidArgumentException('A coast texture needs an area and at least 2x2 texels');
        }
        if ($landRange <= 0.0 || $seaRange <= 0.0 || $maxDepth <= 0.0) {
            throw new \InvalidArgumentException('Coast texture ranges must be positive');
        }
    }

    /**
     * Add a coast. $offsetX / $offsetZ move the field's own coordinates into world
     * space (a raster built around a local origin). Where two rasters overlap, the
     * nearer shoreline wins.
     */
    public function addField(CoastField $field, float $offsetX = 0.0, float $offsetZ = 0.0): self
    {
        $this->fields[] = [$field, $offsetX, $offsetZ];

        return $this;
    }

    /**
     * Fill the raster. $depth gives the water depth at a world position in metres
     * (surface minus seabed), null where there is no water; without it the depth
     * channel holds 0 everywhere.
     *
     * The rasters answer with the distance to their nearest cell, so a shore running
     * at a shallow angle to the grid comes out in steps one cell high. $smooth runs
     * that many box-filter passes (radius 2 texels) over the distance: a distance
     * field is close to linear, so the filter keeps the values and rounds off the
     * steps (and sharp corners of the shore).
     *
     * @param (callable(float, float): ?float)|null $depth
     */
    public function bake(?callable $depth = null, int $smooth = 0): self
    {
        $span = $this->landRange + $this->seaRange;
        $sx = ($this->maxX - $this->minX) / $this->width;
        $sz = ($this->maxZ - $this->minZ) / $this->height;
        /** @var list<float> $distance */
        $distance = [];
        /** @var list<bool> $covered */
        $covered = [];
        for ($j = 0; $j < $this->height; $j++) {
            $z = $this->minZ + ($j + 0.5) * $sz;
            for ($i = 0; $i < $this->width; $i++) {
                $x = $this->minX + ($i + 0.5) * $sx;
                $best = null;
                foreach ($this->fields as [$field, $ox, $oz]) {
                    $here = $field->at($x - $ox, $z - $oz);
                    if ($here === null) {
                        continue;
                    }
                    $d = $here['distance'];
                    if ($best === null || abs($d) < abs($best)) {
                        $best = $d;
                    }
                }
                $covered[] = $best !== null;
                $distance[] = max(-$this->landRange, min($this->seaRange, $best ?? $this->seaRange));
            }
        }
        for ($pass = 0; $pass < $smooth; $pass++) {
            $distance = $this->boxFilter($distance);
        }

        $out = '';
        $k = 0;
        for ($j = 0; $j < $this->height; $j++) {
            $z = $this->minZ + ($j + 0.5) * $sz;
            $row = '';
            for ($i = 0; $i < $this->width; $i++, $k++) {
                $x = $this->minX + ($i + 0.5) * $sx;
                $d = $distance[$k];
                $w = $d > 0.0 && $depth !== null ? ($depth($x, $z) ?? 0.0) : 0.0;
                $row .= chr(self::byte(($d + $this->landRange) / $span)) . chr(self::byte($w / $this->maxDepth))
                    . ($covered[$k] ? "\xFF" : "\x00") . "\xFF";
            }
            $out .= $row;
        }
        $this->rgba = $out;

        return $this;
    }

    /**
     * One separable box-filter pass, radius 2 texels, clamped at the edges.
     *
     * @param list<float> $v
     * @return list<float>
     */
    private function boxFilter(array $v): array
    {
        $w = $this->width;
        $h = $this->height;
        $r = 2;
        $n = 2 * $r + 1;
        $tmp = $v;
        for ($j = 0; $j < $h; $j++) {
            $o = $j * $w;
            for ($i = 0; $i < $w; $i++) {
                $s = 0.0;
                for ($d = -$r; $d <= $r; $d++) {
                    $s += $v[$o + max(0, min($w - 1, $i + $d))];
                }
                $tmp[$o + $i] = $s / $n;
            }
        }
        $out = $tmp;
        for ($j = 0; $j < $h; $j++) {
            for ($i = 0; $i < $w; $i++) {
                $s = 0.0;
                for ($d = -$r; $d <= $r; $d++) {
                    $s += $tmp[max(0, min($h - 1, $j + $d)) * $w + $i];
                }
                $out[$j * $w + $i] = $s / $n;
            }
        }

        return array_values($out);
    }
    /** The texels, `width * height * 4` bytes, row 0 = minimum z. */
    public function rgba(): string
    {
        return $this->rgba;
    }

    /**
     * `vec4(minX, minZ, 1 / sizeX, 1 / sizeZ)`: world xz to texture uv.
     *
     * @return list<float>
     */
    public function mapUniform(): array
    {
        return [$this->minX, $this->minZ, 1.0 / ($this->maxX - $this->minX), 1.0 / ($this->maxZ - $this->minZ)];
    }

    /**
     * `vec4(landRange, seaRange, maxDepth, 0)`: the decode of R and G.
     *
     * @return list<float>
     */
    public function rangeUniform(): array
    {
        return [$this->landRange, $this->seaRange, $this->maxDepth, 0.0];
    }

    /**
     * Decoded values at a world position, filtered like the GPU sampler (bilinear,
     * clamped to the edge).
     *
     * @return array{distance: float, depth: float, coverage: float}
     */
    public function sample(float $x, float $z): array
    {
        if ($this->rgba === '') {
            throw new \LogicException('The coast texture is not baked');
        }
        $u = ($x - $this->minX) / ($this->maxX - $this->minX) * $this->width - 0.5;
        $v = ($z - $this->minZ) / ($this->maxZ - $this->minZ) * $this->height - 0.5;
        $i0 = (int) floor($u);
        $j0 = (int) floor($v);
        $fu = $u - $i0;
        $fv = $v - $j0;
        $acc = [0.0, 0.0, 0.0];
        foreach ([[0, 0, (1 - $fu) * (1 - $fv)], [1, 0, $fu * (1 - $fv)], [0, 1, (1 - $fu) * $fv], [1, 1, $fu * $fv]] as [$di, $dj, $wgt]) {
            $i = max(0, min($this->width - 1, $i0 + $di));
            $j = max(0, min($this->height - 1, $j0 + $dj));
            $o = ($j * $this->width + $i) * 4;
            for ($c = 0; $c < 3; $c++) {
                $acc[$c] += $wgt * ord($this->rgba[$o + $c]) / 255.0;
            }
        }

        return [
            'distance' => $acc[0] * ($this->landRange + $this->seaRange) - $this->landRange,
            'depth' => $acc[1] * $this->maxDepth,
            'coverage' => $acc[2],
        ];
    }

    /**
     * A 0..1 value as one byte.
     *
     * @return int<0, 255>
     */
    private static function byte(float $v): int
    {
        return max(0, min(255, (int) round(255.0 * $v)));
    }

    /** Write the baked raster: one JSON header line, then the zlib-compressed texels. */
    public function save(string $path): void
    {
        if ($this->rgba === '') {
            throw new \LogicException('The coast texture is not baked');
        }
        $compressed = gzcompress($this->rgba, 9);
        if ($compressed === false) {
            throw new \RuntimeException('Could not compress the coast texture');
        }
        $header = json_encode([
            'magic' => self::MAGIC,
            'bounds' => [$this->minX, $this->minZ, $this->maxX, $this->maxZ],
            'size' => [$this->width, $this->height],
            'ranges' => [$this->landRange, $this->seaRange, $this->maxDepth],
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (file_put_contents($path, $header . "\n" . $compressed) === false) {
            throw new \RuntimeException("Could not write {$path}");
        }
    }

    /** Read a raster written by {@see save()}. */
    public static function load(string $path): self
    {
        $bytes = @file_get_contents($path);
        $cut = $bytes === false ? false : strpos($bytes, "\n");
        if ($bytes === false || $cut === false) {
            throw new \RuntimeException("No coast texture at {$path}");
        }
        /** @var array{magic?: string, bounds: list<float>, size: list<int>, ranges: list<float>} $h */
        $h = json_decode(substr($bytes, 0, $cut), true, 4, JSON_THROW_ON_ERROR);
        if (($h['magic'] ?? '') !== self::MAGIC) {
            throw new \RuntimeException("{$path} is not a coast texture");
        }
        $texture = new self(
            (float) $h['bounds'][0],
            (float) $h['bounds'][1],
            (float) $h['bounds'][2],
            (float) $h['bounds'][3],
            (int) $h['size'][0],
            (int) $h['size'][1],
            (float) $h['ranges'][0],
            (float) $h['ranges'][1],
            (float) $h['ranges'][2],
        );
        $rgba = gzuncompress(substr($bytes, $cut + 1));
        if ($rgba === false || strlen($rgba) !== $texture->width * $texture->height * 4) {
            throw new \RuntimeException("{$path}: texel data does not match its header");
        }
        $texture->rgba = $rgba;

        return $texture;
    }
}
