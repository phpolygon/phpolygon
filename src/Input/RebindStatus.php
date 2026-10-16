<?php

declare(strict_types=1);

namespace PHPolygon\Input;

/** Why {@see InputMap::rebind()} did or did not apply a binding. */
enum RebindStatus: string
{
    /** The binding was applied. */
    case Bound = 'bound';
    /** The action already had exactly this binding in that slot. */
    case Unchanged = 'unchanged';
    /** Applied; the action that held the binding got the old one in exchange. */
    case Swapped = 'swapped';
    case UnknownAction = 'unknown_action';
    /** The action is marked not rebindable. */
    case Locked = 'locked';
    /** Reserved key, a key of a locked action, or a key used by an axis. */
    case Reserved = 'reserved';
    /** Rejected by the bindable filter. */
    case NotBindable = 'not_bindable';
    /** Another action holds the binding and the policy is Reject. */
    case Conflict = 'conflict';
    /** Slot beyond the end of the action's binding list. */
    case InvalidSlot = 'invalid_slot';
}
