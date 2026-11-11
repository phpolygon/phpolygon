<?php

declare(strict_types=1);

namespace PHPolygon\Visibility;

/**
 * What a {@see SpatialIndex} entry represents. Phase 2 only ever registers
 * Entity (one per MeshRenderer + Transform3D pair); the other cases are
 * reserved for later phases (batched draws, particle emitters, point/spot
 * lights, and anything else a game registers directly) so the entry shape
 * does not need to change again when they arrive.
 */
enum EntryKind
{
    case Entity;
    case Batch;
    case Emitter;
    case Light;
    case Custom;
}
