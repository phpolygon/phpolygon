<?php

declare(strict_types=1);

namespace PHPolygon\Icon;

/**
 * Shrinks icons by area averaging (a box filter with exact fractional coverage):
 * every output pixel is the mean of the source area it covers, weighted by alpha
 * (premultiplied), so transparent corners leave no dark fringe. A 2:1 step is
 * the plain 2x2 mean. Deterministic: the same bytes on every host.
 *
 * {@see self::sizes()} walks down from the source in halves and takes each size
 * from the smallest step at least four times as large, so a 1024 px source costs
 * about two full passes over it, not one per size.
 */
final class IconResampler
{
    /**
     * $source shrunk to $width x $height (never enlarged).
     */
    public static function downscale(RgbaImage $source, int $width, int $height): RgbaImage
    {
        if ($width > $source->width || $height > $source->height || $width < 1 || $height < 1) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot shrink a %dx%d image to %dx%d',
                $source->width,
                $source->height,
                $width,
                $height,
            ));
        }
        if ($width === $source->width && $height === $source->height) {
            return $source;
        }

        $xMap = self::axis($source->width, $width);
        $yMap = self::axis($source->height, $height);
        $lastInput = [];
        foreach ($yMap as $y => $targets) {
            foreach ($targets as [$row]) {
                $lastInput[$row] = $y;
            }
        }

        $inStride = $source->width * 4;
        $outStride = $width * 4;
        $zero = array_fill(0, $outStride, 0.0);
        /** @var array<int, list<float>> $acc */
        $acc = [];
        $rows = array_fill(0, $height, '');
        for ($y = 0; $y < $source->height; $y++) {
            /** @var array<int, int> $line */
            $line = unpack('C*', substr($source->pixels, $y * $inStride, $inStride));
            $h = $zero;
            for ($x = 0, $k = 1; $x < $source->width; $x++, $k += 4) {
                $a = $line[$k + 3];
                if ($a === 0) {
                    continue;
                }
                $r = $line[$k] * $a;
                $g = $line[$k + 1] * $a;
                $b = $line[$k + 2] * $a;
                foreach ($xMap[$x] as [$i, $w]) {
                    $o = $i * 4;
                    $h[$o] += $w * $r;
                    $h[$o + 1] += $w * $g;
                    $h[$o + 2] += $w * $b;
                    $h[$o + 3] += $w * $a;
                }
            }
            foreach ($yMap[$y] as [$row, $w]) {
                if (!isset($acc[$row])) {
                    $acc[$row] = $zero;
                }
                $target = &$acc[$row];
                for ($o = 0; $o < $outStride; $o++) {
                    $target[$o] += $w * $h[$o];
                }
                unset($target);
                if ($lastInput[$row] === $y) {
                    $rows[$row] = self::finishRow($acc[$row], $width);
                    unset($acc[$row]);
                }
            }
        }

        return new RgbaImage($width, $height, implode('', $rows));
    }

    /**
     * Square images of every size in $sizes from one square source, each by
     * area averaging (see the class comment for the cascade). Sizes larger
     * than the source are left out.
     *
     * @param list<int> $sizes
     * @return array<int, RgbaImage> size => image
     */
    public static function sizes(RgbaImage $source, array $sizes): array
    {
        if (!$source->isSquare()) {
            throw new \InvalidArgumentException("Icon source must be square, got {$source->width}x{$source->height}");
        }
        $steps = [$source->width => $source];
        $step = $source;
        while ($step->width >= 2 && intdiv($step->width, 2) >= min([...$sizes, PHP_INT_MAX])) {
            $half = intdiv($step->width, 2);
            $step = self::downscale($step, $half, $half);
            $steps[$half] = $step;
        }
        ksort($steps);

        $out = [];
        foreach ($sizes as $size) {
            if ($size > $source->width) {
                continue;
            }
            if (isset($steps[$size])) {
                $out[$size] = $steps[$size];
                continue;
            }
            $from = $source;
            foreach ($steps as $width => $candidate) {
                if ($width >= 4 * $size) {
                    $from = $candidate;
                    break;
                }
            }
            $out[$size] = self::downscale($from, $size, $size);
        }
        return $out;
    }

    /**
     * For each source index the output indices it covers and the weight
     * (covered length / output pixel length).
     *
     * @return list<list<array{int, float}>>
     */
    private static function axis(int $in, int $out): array
    {
        $scale = $in / $out;
        $map = [];
        for ($j = 0; $j < $in; $j++) {
            $targets = [];
            $first = (int) floor($j / $scale);
            $last = min($out - 1, (int) ceil(($j + 1) / $scale) - 1);
            for ($i = $first; $i <= $last; $i++) {
                $overlap = min($j + 1, ($i + 1) * $scale) - max($j, $i * $scale);
                if ($overlap > 1e-9) {
                    $targets[] = [$i, $overlap / $scale];
                }
            }
            $map[] = $targets;
        }
        return $map;
    }

    /**
     * Premultiplied sums of one row back to straight RGBA bytes.
     *
     * @param list<float> $sums
     */
    private static function finishRow(array $sums, int $width): string
    {
        $bytes = [];
        for ($x = 0, $o = 0; $x < $width; $x++, $o += 4) {
            $a = $sums[$o + 3];
            if ($a < 0.5) {
                array_push($bytes, 0, 0, 0, 0);
                continue;
            }
            $bytes[] = min(255, (int) round($sums[$o] / $a));
            $bytes[] = min(255, (int) round($sums[$o + 1] / $a));
            $bytes[] = min(255, (int) round($sums[$o + 2] / $a));
            $bytes[] = min(255, (int) round($a));
        }
        return pack('C*', ...$bytes);
    }
}
