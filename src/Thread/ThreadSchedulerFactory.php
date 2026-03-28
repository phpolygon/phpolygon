<?php

declare(strict_types=1);

namespace PHPolygon\Thread;

use PHPolygon\EngineConfig;

final class ThreadSchedulerFactory
{
    public static function create(EngineConfig $config): ThreadScheduler|NullThreadScheduler
    {
        $mode = ParallelCapability::resolveMode($config->threadingMode);

        if ($mode === ThreadingMode::MultiThreaded && ParallelCapability::isAvailable()) {
            return new ThreadScheduler(ParallelCapability::getCpuCount());
        }

        return new NullThreadScheduler();
    }
}
