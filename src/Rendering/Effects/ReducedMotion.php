<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Effects;

/** What an effect does while the player asked for reduced motion. */
enum ReducedMotion: string
{
    /** It does not animate, nothing to reduce. */
    case Static = 'static';
    /** Held still at a fixed time. */
    case Frozen = 'frozen';
    /** Keeps running, slower or with a smaller amplitude. */
    case Reduced = 'reduced';
    /** Switched off. */
    case Off = 'off';
}
