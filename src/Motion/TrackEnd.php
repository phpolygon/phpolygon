<?php

declare(strict_types=1);

namespace PHPolygon\Motion;

/** What a {@see \PHPolygon\Component\SignalTrack} does with an item that reaches the end. */
enum TrackEnd: string
{
    /** The item stops at the end; later items queue up behind it, spacing apart. */
    case Queue = 'queue';
    /** The item disappears at the end and goes back to the pool. */
    case Recycle = 'recycle';
}
