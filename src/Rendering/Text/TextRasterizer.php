<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Text;

use PHPolygon\Rendering\Color;
use PHPolygon\UI\LabelFit;
use VioFontFace;

/**
 * Lays text into a pixel box and rasterizes it into RGBA, without GD and without
 * a GPU.
 *
 * Every line goes through vio_text_bitmap() on its own: bidi runs, shaping and
 * the fallback chain are php-vio's, so Arabic joins and runs right to left and
 * Thai marks sit on their consonants. Lines are broken in logical order
 * ({@see \PHPolygon\Rendering\TextWrap}, CJK between characters) and centred, which
 * is why right-to-left text needs nothing of its own here.
 *
 * Nothing is cut and no word is split: the search takes the largest size at
 * which the text fits in up to $maxLines lines, down to below the wanted minimum
 * if it has to, and reports that. A word split between two characters would tear
 * an Arabic join or a Thai cluster, a smaller font hurts less.
 */
final class TextRasterizer
{
    /** Metrics are taken once at this size and scaled: glyph outlines scale linearly. */
    private const float REFERENCE_PX = 64.0;

    /** One line more must win at least this much size: a sign reads better on fewer lines. */
    private const float EXTRA_LINE_GAIN = 1.1;

    /** @var (callable(list<VioFontFace>, string, float, array{measure?: bool}|null): (array{width: int, height: int, origin_x: int, baseline: int, advance: float, data?: string}|false))|null */
    private $bitmap;

    /**
     * @param (callable(list<VioFontFace>, string, float, array{measure?: bool}|null): (array{width: int, height: int, origin_x: int, baseline: int, advance: float, data?: string}|false))|null $bitmap
     *        layout source, vio_text_bitmap() by default. Named only when text is
     *        laid out, so that this class can exist without php-vio.
     */
    public function __construct(?callable $bitmap = null)
    {
        $this->bitmap = $bitmap;
    }

    /** True when php-vio can rasterize text here. */
    public static function available(): bool
    {
        return function_exists('vio_text_bitmap') && function_exists('vio_font_face');
    }

    /**
     * The largest size at which $text fits a box of $boxWidth x $boxHeight pixels.
     * The ink of the whole block stays inside, marks and descenders included.
     *
     * @param list<VioFontFace> $faces
     * @param float $maxPx 0 = no limit besides the box
     * @param float $minPx sizes below this are flagged in the result
     */
    public function fit(array $faces, string $text, float $boxWidth, float $boxHeight, float $lineHeight, int $maxLines, float $maxPx, float $minPx): TextFit
    {
        $maxPx = $maxPx > 0.0 ? min($maxPx, $boxHeight) : $boxHeight;
        if (trim($text) === '') {
            return new TextFit([], $maxPx);
        }

        /** @var array<string, array{0: float, 1: float, 2: float}> $metrics */
        $metrics = [];
        $measure = function (string $line, float $px) use ($faces, &$metrics): float {
            $metrics[$line] ??= $this->lineMetrics($faces, $line);
            return $metrics[$line][0] * $px;
        };

        $best = null;
        for ($lines = 1; $lines <= max(1, $maxLines); $lines++) {
            $size = min($maxPx, $boxHeight / (1.0 + ($lines - 1) * $lineHeight));
            // Marks can reach past the line box: shrink by the overshoot and look again.
            for ($attempt = 0; $attempt < 8 && $size >= 1.0; $attempt++) {
                $fit = LabelFit::fit([$text], $boxWidth, $size, 1.0, $lines, $measure);
                $found = $fit->label(0);
                if (count($found) > $lines) {
                    break;
                }
                $extent = $this->blockHeight($found, $fit->fontSize, $lineHeight, $metrics);
                if ($extent <= $boxHeight) {
                    $wins = $best === null || $fit->fontSize > $best->fontPx * self::EXTRA_LINE_GAIN;
                    if ($wins) {
                        $best = new TextFit($found, $fit->fontSize, $fit->fontSize < $minPx);
                    }
                    break;
                }
                $size = min($fit->fontSize - 1.0, $fit->fontSize * $boxHeight / $extent);
            }
        }

        // Not even one word per line fits at 1 px: an absurd box, keep the text whole.
        return $best ?? new TextFit([$text], 1.0, true);
    }

    /**
     * RGBA8 pixels, top row first: $background with the text of $fit centred in $color.
     * The colours are written as they are, blending happens on these values. The
     * mesh shader reads them as linear albedo, so this is a blend in linear light.
     *
     * @param list<VioFontFace> $faces
     */
    public function render(array $faces, TextFit $fit, int $width, int $height, Color $color, Color $background, float $lineHeight): string
    {
        $coverageRows = $this->coverage($faces, $fit, $width, $height, $lineHeight);

        $from = self::bytes($background);
        $to = self::bytes($color);
        $table = [];
        for ($c = 0; $c < 256; $c++) {
            $table[chr($c)] = pack(
                'C4',
                (int) round($from[0] + ($to[0] - $from[0]) * $c / 255),
                (int) round($from[1] + ($to[1] - $from[1]) * $c / 255),
                (int) round($from[2] + ($to[2] - $from[2]) * $c / 255),
                255,
            );
        }

        $emptyRow = str_repeat($table["\0"], $width);
        $rows = array_fill(0, $height, $emptyRow);
        foreach ($coverageRows as $y => $row) {
            $rows[$y] = strtr($row, $table);
        }

        return implode('', $rows);
    }

    /**
     * Coverage of the text block, one string of $width bytes per row that holds ink.
     *
     * @param list<VioFontFace> $faces
     * @return array<int, string>
     */
    private function coverage(array $faces, TextFit $fit, int $width, int $height, float $lineHeight): array
    {
        $pitch = $fit->fontPx * $lineHeight;

        // First the bitmaps: the block is centred by its real ink.
        $bitmaps = [];
        $top = PHP_FLOAT_MAX;
        $bottom = -PHP_FLOAT_MAX;
        foreach ($fit->lines as $i => $line) {
            $bitmap = $this->layout($faces, $line, $fit->fontPx, null);
            if ($bitmap === false || $bitmap['width'] === 0) {
                continue;
            }
            $bitmaps[$i] = $bitmap;
            $top = min($top, $i * $pitch - $bitmap['baseline']);
            $bottom = max($bottom, $i * $pitch - $bitmap['baseline'] + $bitmap['height']);
        }
        $firstBaseline = ($height - ($bottom - $top)) / 2.0 - $top;

        $rows = [];
        $blank = str_repeat("\0", $width);
        foreach ($bitmaps as $i => $bitmap) {
            $x = (int) round(($width - $bitmap['width']) / 2.0);
            $y = (int) round($firstBaseline + $i * $pitch) - $bitmap['baseline'];
            $data = $bitmap['data'] ?? '';
            for ($row = 0; $row < $bitmap['height']; $row++) {
                $target = $y + $row;
                if ($target < 0 || $target >= $height) {
                    continue;
                }
                $left = max(0, -$x);
                $ink = substr($data, $row * $bitmap['width'] + $left, min($bitmap['width'] - $left, $width - max(0, $x)));
                // OR keeps the ink of a neighbouring line that reaches into this row.
                $rows[$target] = ($rows[$target] ?? $blank) | str_pad(str_repeat("\0", max(0, $x)) . $ink, $width, "\0");
            }
        }

        return $rows;
    }

    /**
     * @param list<VioFontFace> $faces
     * @param array{measure?: bool}|null $options
     * @return array{width: int, height: int, origin_x: int, baseline: int, advance: float, data?: string}|false
     */
    private function layout(array $faces, string $line, float $px, ?array $options): array|false
    {
        return $this->bitmap !== null
            ? ($this->bitmap)($faces, $line, $px, $options)
            : vio_text_bitmap($faces, $line, $px, $options);
    }

    /**
     * Width (ink or pen advance, the larger), ink above and below the baseline,
     * each per pixel of font size.
     *
     * @param list<VioFontFace> $faces
     * @return array{0: float, 1: float, 2: float}
     */
    private function lineMetrics(array $faces, string $line): array
    {
        $m = $this->layout($faces, $line, self::REFERENCE_PX, ['measure' => true]);
        if ($m === false) {
            return [0.0, 0.0, 0.0];
        }

        return [
            max($m['advance'], (float) $m['width']) / self::REFERENCE_PX,
            $m['baseline'] / self::REFERENCE_PX,
            ($m['height'] - $m['baseline']) / self::REFERENCE_PX,
        ];
    }

    /**
     * Height of the ink of $lines at $px.
     *
     * @param list<string> $lines
     * @param array<string, array{0: float, 1: float, 2: float}> $metrics
     */
    private function blockHeight(array $lines, float $px, float $lineHeight, array $metrics): float
    {
        $top = PHP_FLOAT_MAX;
        $bottom = -PHP_FLOAT_MAX;
        foreach ($lines as $i => $line) {
            [, $above, $below] = $metrics[$line] ?? [0.0, 0.0, 0.0];
            $top = min($top, $i * $lineHeight * $px - $above * $px);
            $bottom = max($bottom, $i * $lineHeight * $px + $below * $px);
        }

        return $bottom - $top;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function bytes(Color $color): array
    {
        return [
            (int) round(max(0.0, min(1.0, $color->r)) * 255),
            (int) round(max(0.0, min(1.0, $color->g)) * 255),
            (int) round(max(0.0, min(1.0, $color->b)) * 255),
        ];
    }
}
