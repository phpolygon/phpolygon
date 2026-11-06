<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

/**
 * A 3D colour lookup table of the image chain: N³ RGBA8 entries, red along x, green along
 * y, blue along z (the slice order of `vio_texture_3d`). Input and output are the
 * display-referred image (sRGB-encoded 0..1). Built from numbers ({@see fromGrade()}) or
 * from the text of an Adobe/Resolve `.cube` file ({@see fromCube()}), so a look stays text.
 */
final class ColorLut
{
    public const DEFAULT_SIZE = 32;
    public const MAX_SIZE = 64;

    /** @param string $data size³ x 4 bytes, red fastest */
    private function __construct(
        public readonly int $size,
        public readonly string $data,
    ) {
    }

    public static function identity(int $size = self::DEFAULT_SIZE): self
    {
        return self::fromFunction(static fn (float $r, float $g, float $b): array => [$r, $g, $b], $size);
    }

    public static function fromGrade(ColorGrade $grade, int $size = self::DEFAULT_SIZE): self
    {
        return self::fromFunction(static fn (float $r, float $g, float $b): array => $grade->apply([$r, $g, $b]), $size);
    }

    /**
     * @param callable(float, float, float): array{0: float, 1: float, 2: float} $map encoded in, encoded out
     */
    public static function fromFunction(callable $map, int $size = self::DEFAULT_SIZE): self
    {
        self::assertSize($size);
        $n = $size - 1;
        $data = '';
        for ($b = 0; $b < $size; $b++) {
            for ($g = 0; $g < $size; $g++) {
                for ($r = 0; $r < $size; $r++) {
                    $c = $map($r / $n, $g / $n, $b / $n);
                    $data .= chr(self::byte($c[0])) . chr(self::byte($c[1])) . chr(self::byte($c[2])) . "\xff";
                }
            }
        }
        return new self($size, $data);
    }

    /**
     * Parse the text of a `.cube` file (LUT_3D_SIZE, optional DOMAIN_MIN / DOMAIN_MAX, rows
     * of three floats with red changing fastest). The table is taken as it stands: it must
     * map the encoded image, like a LUT made for a display-referred grade.
     */
    public static function fromCube(string $text): self
    {
        $size = 0;
        $min = [0.0, 0.0, 0.0];
        $max = [1.0, 1.0, 1.0];
        $rows = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^LUT_3D_SIZE\s+(\d+)$/i', $line, $m) === 1) {
                $size = (int) $m[1];
            } elseif (preg_match('/^DOMAIN_(MIN|MAX)\s+(\S+)\s+(\S+)\s+(\S+)$/i', $line, $m) === 1) {
                $v = [(float) $m[2], (float) $m[3], (float) $m[4]];
                strtoupper($m[1]) === 'MIN' ? $min = $v : $max = $v;
            } elseif (preg_match('/^(-?[\d.eE+-]+)\s+(-?[\d.eE+-]+)\s+(-?[\d.eE+-]+)$/', $line, $m) === 1) {
                $rows[] = [(float) $m[1], (float) $m[2], (float) $m[3]];
            }
            // TITLE, LUT_1D_SIZE and other keywords are ignored; a 1D table has no LUT_3D_SIZE.
        }
        if ($size < 2) {
            throw new \InvalidArgumentException('.cube text without LUT_3D_SIZE');
        }
        self::assertSize($size);
        if (count($rows) !== $size ** 3) {
            throw new \InvalidArgumentException(sprintf('.cube LUT_3D_SIZE %d needs %d rows, found %d', $size, $size ** 3, count($rows)));
        }
        $data = '';
        foreach ($rows as $row) {
            for ($i = 0; $i < 3; $i++) {
                $span = $max[$i] - $min[$i];
                $data .= chr(self::byte($span != 0.0 ? ($row[$i] - $min[$i]) / $span : 0.0));
            }
            $data .= "\xff";
        }
        return new self($size, $data);
    }

    /**
     * Look an encoded colour up the way the shader does (trilinear between the entries).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function sample(float $r, float $g, float $b): array
    {
        $n = $this->size - 1;
        $p = [max(0.0, min(1.0, $r)) * $n, max(0.0, min(1.0, $g)) * $n, max(0.0, min(1.0, $b)) * $n];
        $i0 = array_map(static fn (float $x): int => min($n - 1, (int) floor($x)), $p);
        $f = [$p[0] - $i0[0], $p[1] - $i0[1], $p[2] - $i0[2]];
        $out = [0.0, 0.0, 0.0];
        for ($corner = 0; $corner < 8; $corner++) {
            $o = [$corner & 1, ($corner >> 1) & 1, ($corner >> 2) & 1];
            $w = ($o[0] ? $f[0] : 1 - $f[0]) * ($o[1] ? $f[1] : 1 - $f[1]) * ($o[2] ? $f[2] : 1 - $f[2]);
            $at = 4 * (($i0[0] + $o[0]) + $this->size * (($i0[1] + $o[1]) + $this->size * ($i0[2] + $o[2])));
            for ($c = 0; $c < 3; $c++) {
                $out[$c] += $w * ord($this->data[$at + $c]) / 255.0;
            }
        }
        return [$out[0], $out[1], $out[2]];
    }

    /** @return int<0, 255> */
    private static function byte(float $v): int
    {
        return max(0, min(255, (int) round($v * 255.0)));
    }

    private static function assertSize(int $size): void
    {
        if ($size < 2 || $size > self::MAX_SIZE) {
            throw new \InvalidArgumentException("ColorLut size {$size} outside 2.." . self::MAX_SIZE);
        }
    }
}
