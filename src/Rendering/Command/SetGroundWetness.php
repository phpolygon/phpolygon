<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

/**
 * How wet the ground appears. Drives the procedural sand shader's wetness
 * gradient and rain puddles. 0 = bone dry, 1 = soaked / fully wet.
 *
 * Issued each frame by Renderer3DSystem: the ground soaks at once and dries
 * slowly after the rain; $raining is the rain falling now (0..1), so the sky
 * can show a rainbow on wet, sunlit ground once it has stopped.
 */
final readonly class SetGroundWetness
{
    public function __construct(
        public float $rainWetness,
        public float $raining = 0.0,
        /** Below this world height nothing is wet (Weather::$dryBelowY), null = everywhere. */
        public ?float $dryBelowY = null,
        /**
         * How much of the rain wets walls and props (0..1; 0 = only the ground, as before): vertical surfaces darken,
         * smooth and run with streaks, from the same wetness. Opt-in: the default leaves every wall dry.
         */
        public float $wallWetness = 0.0,
    ) {}
}
