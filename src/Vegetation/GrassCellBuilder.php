<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

use PHPolygon\Component\GrassField;

/**
 * The tufts of one grass cell, built in slices: {@see run()} works until a
 * deadline and picks up where it stopped, so a cell whose mask or ground is slow
 * spreads over as many frames as it needs. The result depends only on field,
 * detail and cell, never on how the work was cut.
 *
 * A cell is a jittered grid on world coordinates (seamless across cells), kept
 * by the mask and the field density, set on the ground, sorted by a per-tuft
 * hash. One candidate is the smallest slice: what a single mask or ground call
 * costs is not divided further.
 *
 * @phpstan-type GrassCell array{ix: int, iz: int, y0: float, y1: float, hashes: list<list<float>>, packed: list<string>}
 */
final class GrassCellBuilder
{
    private float $spacing = 0.0;
    private int $gx0 = 0;
    private int $gx1 = -1;
    private int $gz0 = 0;
    private int $gz1 = -1;
    private int $gx = 0;
    private int $gz = 0;

    private readonly int $variants;
    private readonly float $keepBase;

    /** @var list<list<float>> per variant */
    private array $hashes;

    /** @var list<list<string>> per variant */
    private array $tufts;

    private float $y0 = INF;
    private float $y1 = -INF;

    /** @var GrassCell|null */
    private ?array $result = null;

    public function __construct(
        private readonly GrassField $field,
        GrassDetail $detail,
        float $cellSize,
        private readonly int $ix,
        private readonly int $iz,
    ) {
        $this->variants = max(1, $detail->variants);
        $this->hashes = array_fill(0, $this->variants, []);
        $this->tufts = array_fill(0, $this->variants, []);
        $cs = max(1.0, $cellSize);
        $tufts = $detail->tuftsPerSquareMetre * max(1.0, $field->density);
        $this->keepBase = min(1.0, max(0.0, $field->density));
        if ($tufts > 0.0 && $this->keepBase > 0.0) {
            $a = 1.0 / sqrt($tufts);
            $x0 = $ix * $cs;
            $z0 = $iz * $cs;
            $this->spacing = $a;
            $this->gx0 = (int) ceil(max($x0, $field->minX) / $a - 0.5);
            $this->gx1 = (int) ceil(min($x0 + $cs, $field->maxX) / $a - 0.5) - 1;
            $this->gz0 = (int) ceil(max($z0, $field->minZ) / $a - 0.5);
            $this->gz1 = (int) ceil(min($z0 + $cs, $field->maxZ) / $a - 0.5) - 1;
        }
        $this->gx = $this->gx0;
        $this->gz = $this->gz0;
    }

    /**
     * Work until `$clock()` reaches `$deadline` (same unit). Always handles at
     * least one candidate, so any budget makes progress.
     *
     * @param \Closure(): int $clock
     * @return bool whether the cell is finished
     */
    public function run(int $deadline, \Closure $clock): bool
    {
        if ($this->result !== null) {
            return true;
        }
        $field = $this->field;
        $mask = $field->mask;
        $maskOpen = $mask->isOpen();
        $a = $this->spacing;
        $keepBase = $this->keepBase;
        $variants = $this->variants;
        $seed = $field->seed;
        $height = $field->height;
        $jitter = max(0.0, min(0.9, $field->heightJitter));
        $sink = $field->sink;
        $gx1 = $this->gx1;
        $gz0 = $this->gz0;
        $gz1 = $this->gz1;
        $gz = $this->gz;
        $free = true;
        for ($gx = $this->gx; $gx <= $gx1; $gx++, $gz = $gz0) {
            for (; $gz <= $gz1; $gz++) {
                if ($free) {
                    $free = false;
                } elseif ($clock() >= $deadline) {
                    $this->gx = $gx;
                    $this->gz = $gz;
                    return false;
                }
                $h = crc32($seed . ':' . $gx . ':' . $gz);
                $h = ($h * 1103515245 + 12345) & 0x7fffffff;
                $rx = $h / 2147483648.0;
                $h = ($h * 1103515245 + 12345) & 0x7fffffff;
                $rz = $h / 2147483648.0;
                $h = ($h * 1103515245 + 12345) & 0x7fffffff;
                $keep = $h / 2147483648.0;
                // The factor is at most 1: this roll fails whatever the mask says.
                if ($keep >= $keepBase) {
                    continue;
                }
                $x = ($gx + 0.5 + ($rx - 0.5) * 0.9) * $a;
                $z = ($gz + 0.5 + ($rz - 0.5) * 0.9) * $a;
                $m = $maskOpen ? 1.0 : $mask->factor($x, $z);
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
                $this->hashes[$variant][] = $hash;
                $this->tufts[$variant][] = pack('f16', $c, 0.0, -$s, $hash, 0.0, $sc, 0.0, 0.0, $s, 0.0, $c, 0.0, $x, $y, $z, 1.0);
                $this->y0 = min($this->y0, $y);
                $this->y1 = max($this->y1, $y);
            }
        }
        $this->gx = $gx;
        $this->gz = $gz;
        $this->finish();
        return true;
    }

    /**
     * The finished cell. Only valid after {@see run()} returned true.
     *
     * @return GrassCell
     */
    public function result(): array
    {
        return $this->result ?? throw new \LogicException('The grass cell is not finished.');
    }

    /** Tufts sorted by hash, packed per variant. */
    private function finish(): void
    {
        $hashes = [];
        $packed = [];
        $any = false;
        foreach ($this->hashes as $v => $list) {
            asort($list);
            $tufts = $this->tufts[$v];
            $bytes = [];
            foreach ($list as $i => $_) {
                $bytes[] = $tufts[$i];
            }
            $hashes[] = array_values($list);
            $packed[] = implode('', $bytes);
            $any = $any || $list !== [];
        }
        $this->hashes = [];
        $this->tufts = [];
        $this->result = $any
            ? ['ix' => $this->ix, 'iz' => $this->iz, 'y0' => $this->y0, 'y1' => $this->y1, 'hashes' => $hashes, 'packed' => $packed]
            : ['ix' => $this->ix, 'iz' => $this->iz, 'y0' => 0.0, 'y1' => 0.0, 'hashes' => [], 'packed' => []];
    }
}
