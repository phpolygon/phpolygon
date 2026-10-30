<?php

declare(strict_types=1);

namespace PHPolygon\UI;

use PHPolygon\Rendering\Color;

/**
 * One entry of a property list, drawn by {@see UIContext::definitionRow()}:
 * a term with a short note and tag behind it, a value on the right and a
 * wrapped explanation below.
 *
 * Every part except $term is optional. '' as a font means the style's font,
 * null as a colour the style's text colour ($noteColor also tints $valueLabel).
 * Each part is drawn on its own, so bidirectional reordering of one part can
 * never move another - keep code and translated text in separate parts.
 */
final readonly class DefinitionRow
{
    public function __construct(
        public string $term,
        /** Explanation below the head line; wraps, never cut. */
        public string $body = '',
        /** Short qualifier behind the term, e.g. a type or a unit. */
        public string $note = '',
        /** Marker behind the note. */
        public string $tag = '',
        /** Right-aligned value on the head line; moves below $body when there is no room. */
        public string $value = '',
        /** Word in front of $value. */
        public string $valueLabel = '',
        public string $termFont = '',
        public string $valueFont = '',
        public ?Color $termColor = null,
        public ?Color $noteColor = null,
        public ?Color $tagColor = null,
        /** Size of $term, $body and $value. */
        public float $bodySize = 14.0,
        /** Size of $note, $tag and $valueLabel. */
        public float $noteSize = 12.0,
        public string $tagFont = '',
        /** Empty space below the row. */
        public float $rowGap = 6.0,
    ) {}
}
