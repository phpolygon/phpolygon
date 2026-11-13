<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

/**
 * Global grime of the frame (0..1): how aged and dirty the materials that opted in ({@see \PHPolygon\Rendering\Material::$grime})
 * look. Dirt gathers in creases, runs down walls in dark streaks from them, and sharp edges wear lighter and rougher.
 * Without the command, or with 0, nothing changes. Typical use: a per-district or per-quest value.
 */
readonly class SetGrime
{
    public function __construct(public float $amount = 0.5) {}
}
