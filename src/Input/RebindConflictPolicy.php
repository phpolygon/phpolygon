<?php

declare(strict_types=1);

namespace PHPolygon\Input;

/** What {@see InputMap::rebind()} does when another action already holds the binding. */
enum RebindConflictPolicy: string
{
    /** Refuse; the caller can show which action holds it. */
    case Reject = 'reject';
    /** Apply, and hand the rebound action's old binding to the other action. */
    case Swap = 'swap';
}
