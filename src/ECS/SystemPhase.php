<?php

declare(strict_types=1);

namespace PHPolygon\ECS;

/**
 * Execution phase for systems in a pipelined game loop.
 *
 * MainThread:  Runs before thread results arrive (input, camera, player movement)
 * Threaded:    Handled by ThreadScheduler, skipped in World::updateMainThread()
 * PostThread:  Runs after thread results are applied (transform propagation)
 */
enum SystemPhase
{
    case MainThread;
    case Threaded;
    case PostThread;
}
