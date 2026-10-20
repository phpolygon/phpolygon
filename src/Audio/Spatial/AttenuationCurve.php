<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Spatial;

/**
 * Shape of the distance roll-off between an {@see Attenuation}'s minimum and
 * maximum radius.
 */
enum AttenuationCurve: string
{
    /** Straight line from 1 at the minimum radius to 0 at the maximum. */
    case Linear = 'linear';

    /** OpenAL-style inverse distance, tapered to reach 0 at the maximum radius. */
    case Inverse = 'inverse';

    /** (d / min)^-rolloff, tapered to reach 0 at the maximum radius. */
    case Exponential = 'exponential';

    /** No distance roll-off at all – the source only pans. */
    case None = 'none';
}
