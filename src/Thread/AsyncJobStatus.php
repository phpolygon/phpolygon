<?php

declare(strict_types=1);

namespace PHPolygon\Thread;

/** How an {@see AsyncJobResult} ended. A cancelled job produces no result at all. */
enum AsyncJobStatus: string
{
    /** The handler returned; the value is in {@see AsyncJobResult::$value}. */
    case Ok = 'ok';

    /** The handler threw, ran out of memory, or the worker was lost twice; see {@see AsyncJobResult::$error}. */
    case Error = 'error';

    /** The job ran longer than the pool's hang limit; its worker was killed and replaced. */
    case TimedOut = 'timed_out';
}
