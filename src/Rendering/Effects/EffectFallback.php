<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Effects;

/** What stands in for an effect on a path that cannot run it. */
enum EffectFallback: string
{
    /** Runs there as it is. */
    case Same = 'same';
    /** A cheaper form of it, e.g. a branch of its own in the GL snippet. */
    case Simplified = 'simplified';
    /** Nothing: the image is complete without it. */
    case Off = 'off';
}
