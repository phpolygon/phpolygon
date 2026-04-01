<?php

declare(strict_types=1);

namespace PHPolygon\System;

/**
 * Orchestrates atmospheric simulation: Season → Weather → DayNight coupling.
 *
 * Extends EnvironmentalSystem with the canonical name used in 3D game templates.
 * Run this BEFORE PrecipitationSystem and DayNightSystem so both can read the
 * updated Weather and DayNightCycle state.
 */
class AtmosphericEnvironmentalSystem extends EnvironmentalSystem
{
}
