<?php

declare(strict_types=1);

namespace PHPolygon\UI;

/**
 * Layout of a row of buttons that share a total width, from
 * {@see UIContext::layoutButtonRow()}: one x offset and width per button
 * (relative to the row's left edge), their wrapped labels, the shared font
 * size and the common height. Draw each with {@see UIContext::wrapButton()}.
 */
final class ButtonRow
{
    /**
     * @param list<float> $xs
     * @param list<float> $widths
     * @param list<list<string>> $lines
     */
    public function __construct(
        public readonly array $xs,
        public readonly array $widths,
        public readonly array $lines,
        public readonly float $fontSize,
        public readonly float $height,
    ) {}

    public function count(): int
    {
        return count($this->widths);
    }
}
