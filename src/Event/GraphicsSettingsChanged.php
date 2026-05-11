<?php

declare(strict_types=1);

namespace PHPolygon\Event;

use PHPolygon\Rendering\GraphicsSettings;

/**
 * Fired after the GraphicsSettingsManager has applied a settings change.
 *
 * Listeners can refresh UI displays, recompute camera parameters, or
 * regenerate procedural meshes that depend on the new tier.
 */
final readonly class GraphicsSettingsChanged
{
    public function __construct(
        public GraphicsSettings $previous,
        public GraphicsSettings $current,
    ) {
    }
}
