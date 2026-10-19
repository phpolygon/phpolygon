<?php

declare(strict_types=1);

namespace PHPolygon\UI;

use PHPolygon\Rendering\TextWrap;

/**
 * Fits a set of labels into boxes of one width with ONE shared font size —
 * the labels of a row of buttons, which look broken when each picks its own
 * size.
 *
 * Nothing is ever truncated. The search goes from $size down to $minSize in
 * 1 px steps and takes the first size at which every label fits in at most
 * $maxLines lines without splitting a word. If none does, $minSize is used
 * with as many lines as needed, and a word that still does not fit is split
 * between two characters ({@see TextWrap::linesWithin()}). Line breaking is
 * {@see TextWrap}'s, so CJK text breaks between characters.
 *
 * Pure: measuring comes from a callback, so the result agrees with whatever
 * renderer (and font fallback chain) will draw the text.
 */
final class LabelFit
{
    /**
     * @param list<list<string>> $lines lines per label, in input order
     */
    public function __construct(
        public readonly array $lines,
        public readonly float $fontSize,
    ) {}

    /**
     * @param list<string> $labels
     * @param callable(string, float): float $measure width of a text at a font size
     */
    public static function fit(array $labels, float $width, float $size, float $minSize, int $maxLines, callable $measure): self
    {
        $maxLines = max(1, $maxLines);
        $minSize = min($size, max(1.0, $minSize));
        for ($s = $size; $s >= $minSize; $s -= 1.0) {
            $at = static fn(string $t): float => $measure($t, $s);
            $all = [];
            $ok = true;
            foreach ($labels as $label) {
                $lines = TextWrap::linesWithin($label, $width, $at);
                // A split word shows up as more lines than the word-only wrap.
                if (count($lines) > $maxLines || count($lines) !== count(TextWrap::lines($label, $width, $at))) {
                    $ok = false;
                }
                $all[] = $lines;
            }
            if ($ok) {
                return new self($all, $s);
            }
        }

        $at = static fn(string $t): float => $measure($t, $minSize);
        $all = [];
        foreach ($labels as $label) {
            $all[] = TextWrap::linesWithin($label, $width, $at);
        }

        return new self($all, $minSize);
    }

    /** @return list<string> the lines of label $i */
    public function label(int $i): array
    {
        return $this->lines[$i] ?? [];
    }

    /** The most lines any label needs (at least 1). */
    public function lineCount(): int
    {
        $n = 1;
        foreach ($this->lines as $lines) {
            $n = max($n, count($lines));
        }

        return $n;
    }
}
