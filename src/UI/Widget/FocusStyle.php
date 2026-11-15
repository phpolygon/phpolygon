<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Rendering\Color;

/**
 * How {@see WidgetTree} draws the keyboard focus ring.
 *
 * The ring is an outline drawn after the whole tree, outside the focused
 * widget's bounds, so showing or hiding it never moves the layout.
 */
final class FocusStyle
{
    public function __construct(
        public Color $color = new Color(0.40, 0.70, 1.0, 1.0),
        /** Outline thickness in design pixels. */
        public float $width = 2.0,
        /** Gap between the widget's edge and the ring, in design pixels. */
        public float $offset = 2.0,
        /** Corner radius of the ring's outer edge. */
        public float $radius = 4.0,
    ) {}
}
