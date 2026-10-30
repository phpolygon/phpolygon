<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Text;

/**
 * How a text sits in its box: the rendered lines and one font size.
 */
final class TextFit
{
    /**
     * @param list<string> $lines        in logical order
     * @param float        $fontPx       pixels per em
     * @param bool         $belowMinimum the text only fits under the wanted minimum size
     */
    public function __construct(
        public readonly array $lines,
        public readonly float $fontPx,
        public readonly bool $belowMinimum = false,
    ) {}
}
