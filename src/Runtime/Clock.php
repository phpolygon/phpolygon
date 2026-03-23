<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

class Clock
{
    private int $startTime;
    private int $lastTime;
    private float $deltaTime = 0.0;

    public function __construct()
    {
        $this->startTime = hrtime(true);
        $this->lastTime = $this->startTime;
    }

    public static function now(): int
    {
        return hrtime(true);
    }

    public function tick(): float
    {
        $now = hrtime(true);
        $this->deltaTime = ($now - $this->lastTime) / 1_000_000_000.0;
        $this->lastTime = $now;
        return $this->deltaTime;
    }

    public function getDeltaTime(): float
    {
        return $this->deltaTime;
    }

    public function getElapsed(): float
    {
        return (hrtime(true) - $this->startTime) / 1_000_000_000.0;
    }

    public static function sleep(int $nanoseconds): void
    {
        $seconds = intdiv($nanoseconds, 1_000_000_000);
        $nanos = $nanoseconds % 1_000_000_000;
        time_nanosleep($seconds, $nanos);
    }
}
