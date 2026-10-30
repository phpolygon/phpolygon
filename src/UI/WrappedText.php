<?php

declare(strict_types=1);

namespace PHPolygon\UI;

use PHPolygon\Rendering\TextWrap;

/**
 * A text broken into lines no wider than a box, ready for
 * {@see \PHPolygon\Rendering\Renderer2DInterface::drawTextBox()}.
 *
 * Breaking comes from {@see TextWrap::linesWithin()}: CJK breaks between
 * characters and a word wider than the box is split instead of overflowing,
 * which plain drawTextBox() wrapping would let happen. The lines travel as one
 * string with hard breaks, so the renderer keeps them as they are.
 *
 * Pure: measuring comes from the caller, so the result agrees with whatever
 * renderer and font fallback chain will draw the text.
 */
final readonly class WrappedText
{
    private function __construct(
        /** The lines joined with "\n". */
        public string $text,
        public int $lines,
        /** Width of the widest line. */
        public float $width,
        public float $height,
        /** Distance between two lines. */
        public float $pitch,
    ) {}

    /**
     * @param callable(string): float $lineWidth width of a candidate line
     * @param float $pitch distance between two lines
     */
    public static function fit(string $text, float $maxWidth, callable $lineWidth, float $pitch): self
    {
        $lines = TextWrap::linesWithin($text, $maxWidth, $lineWidth);
        $width = 0.0;
        foreach ($lines as $line) {
            $width = max($width, $lineWidth($line));
        }

        return new self(implode("\n", $lines), count($lines), $width, count($lines) * $pitch, $pitch);
    }
}
