<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

class GameLoop
{
    private int $timestepNs;
    private int $maxUpdatesPerFrame;

    private float $averageFps = 0.0;

    /** @var float[] */
    private array $frameTimeSamples = [];
    private int $sampleIndex = 0;
    private int $sampleCount = 60;

    public function __construct(
        private float $targetTickRate = 60.0,
        int $maxUpdatesPerFrame = 10,
    ) {
        $this->timestepNs = (int)(1_000_000_000.0 / $targetTickRate);
        $this->maxUpdatesPerFrame = $maxUpdatesPerFrame;
    }

    /**
     * @param callable(float): void $update Called with fixed dt per tick
     * @param callable(float): void $render Called with interpolation factor (0..1)
     * @param callable(): bool $shouldStop
     */
    public function run(callable $update, callable $render, callable $shouldStop): void
    {
        $fixedDt = 1.0 / $this->targetTickRate;
        $lag = 0;
        $previousTime = Clock::now();

        while (!$shouldStop()) {
            $currentTime = Clock::now();
            $elapsed = $currentTime - $previousTime;
            $previousTime = $currentTime;
            $lag += $elapsed;

            // Record frame time
            $this->frameTimeSamples[$this->sampleIndex] = $elapsed / 1_000_000_000.0;
            $this->sampleIndex = ($this->sampleIndex + 1) % $this->sampleCount;

            // Fixed-timestep updates
            $tickCount = 0;
            while ($lag >= $this->timestepNs && $tickCount < $this->maxUpdatesPerFrame) {
                $update($fixedDt);
                $lag -= $this->timestepNs;
                $tickCount++;
            }

            // Render with interpolation factor
            $interpolation = $lag / $this->timestepNs;
            $render((float)$interpolation);

            // Update average FPS
            $this->updateAverages();
        }
    }

    private function updateAverages(): void
    {
        $count = count($this->frameTimeSamples);
        if ($count === 0) {
            return;
        }

        $sum = array_sum($this->frameTimeSamples);
        $avgFrameTime = $sum / $count;
        $this->averageFps = $avgFrameTime > 0 ? 1.0 / $avgFrameTime : 0.0;
    }

    public function getAverageFps(): float
    {
        return $this->averageFps;
    }

    /**
     * Pipelined game loop for multithreaded execution.
     *
     * Per frame:
     *   1. prepareAndSend() — extract state, send to worker threads
     *   2. update() — main-thread-only systems (input, camera, player)
     *   3. render() — draw with world state from previous frame's thread results
     *   4. recvAndApply() — blocking recv from threads, apply deltas to World
     *
     * @param callable(): void $prepareAndSend
     * @param callable(float): void $update
     * @param callable(float): void $render
     * @param callable(): void $recvAndApply
     * @param callable(): bool $shouldStop
     */
    public function runPipelined(
        callable $prepareAndSend,
        callable $update,
        callable $render,
        callable $recvAndApply,
        callable $shouldStop,
    ): void {
        $fixedDt = 1.0 / $this->targetTickRate;
        $lag = 0;
        $previousTime = Clock::now();

        while (!$shouldStop()) {
            $currentTime = Clock::now();
            $elapsed = $currentTime - $previousTime;
            $previousTime = $currentTime;
            $lag += $elapsed;

            // Record frame time
            $this->frameTimeSamples[$this->sampleIndex] = $elapsed / 1_000_000_000.0;
            $this->sampleIndex = ($this->sampleIndex + 1) % $this->sampleCount;

            // Fixed-timestep updates
            $tickCount = 0;
            while ($lag >= $this->timestepNs && $tickCount < $this->maxUpdatesPerFrame) {
                // 1. Send current state to worker threads
                $prepareAndSend();

                // 2. Run main-thread-only systems
                $update($fixedDt);

                $lag -= $this->timestepNs;
                $tickCount++;

                // 4. Receive thread results and apply
                $recvAndApply();
            }

            // 3. Render with interpolation
            $interpolation = $lag / $this->timestepNs;
            $render((float) $interpolation);

            $this->updateAverages();
        }
    }

    public function getTargetTickRate(): float
    {
        return $this->targetTickRate;
    }

    public function getFixedDeltaTime(): float
    {
        return 1.0 / $this->targetTickRate;
    }
}
