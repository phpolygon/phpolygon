<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Math\Vec3;

/**
 * One stop of a benchmark tour: where the camera stands and looks. What else the
 * stop needs (time of day, a world state) the game carries in $data and applies in
 * its camera callback; the engine only reads label, position and target.
 */
final readonly class TourStation
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $label,
        public Vec3 $position,
        public Vec3 $target,
        public array $data = [],
    ) {
    }
}
