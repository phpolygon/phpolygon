<?php

declare(strict_types=1);

namespace PHPolygon\Tests\ECS;

use PHPolygon\ECS\SystemInterface;
use PHPolygon\ECS\World;

/**
 * A system whose update()/render() do exactly what the test configures:
 * sleep a given number of microseconds, or throw once. Counts how many
 * times each was called.
 */
final class RecordingSystem implements SystemInterface
{
    public int $updateCalls = 0;
    public int $renderCalls = 0;
    public int $sleepMicroseconds = 0;
    public bool $throwOnNextUpdate = false;

    public function register(World $world): void {}

    public function unregister(World $world): void {}

    public function update(World $world, float $dt): void
    {
        $this->updateCalls++;
        if ($this->throwOnNextUpdate) {
            $this->throwOnNextUpdate = false;
            throw new \RuntimeException('boom');
        }
        if ($this->sleepMicroseconds > 0) {
            usleep($this->sleepMicroseconds);
        }
    }

    public function render(World $world): void
    {
        $this->renderCalls++;
        if ($this->sleepMicroseconds > 0) {
            usleep($this->sleepMicroseconds);
        }
    }
}
