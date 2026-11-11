<?php

declare(strict_types=1);

namespace PHPolygon\Component;

/**
 * How {@see \PHPolygon\Activity\ActivitySystem} tiers an entity carrying
 * {@see Relevance} (Phase 4 of the visibility plan, 2026-11-11).
 */
enum RelevanceMode: string
{
    /** Never auto-tiered - always kept at Visible, regardless of distance. For anything that must always fully simulate (the player, a quest-critical actor). */
    case Always = 'always';

    /** Tiered between Visible and Near/Far only - never Dormant, even far away (e.g. an ambient sound source or background system that must keep a little state, just not render). */
    case Near = 'near';

    /** The full Visible/Near/Far/Dormant range, purely by distance from the camera (see ActivitySystem's near/far radii). The common case for ordinary world dressing/NPCs. */
    case Auto = 'auto';
}
