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

    /**
     * Frame-time cap in nanoseconds. 0 = uncapped. Set via setFpsCap() from
     * the GraphicsSettingsManager when the player picks a fixed FPS limit.
     * The cap is applied as a busy-wait of at most one frame at the end of
     * each render iteration, after vsync has had its chance to throttle.
     */
    private int $minFrameTimeNs = 0;

    /**
     * Largest dt (seconds) a single variable-timestep update may receive. A
     * one-off stall (asset load, window drag, debugger pause) is clamped to this
     * so it can't inject a huge step that tunnels physics — the variable-mode
     * analogue of the fixed loop's spiral-of-death guard. ~14 fps floor.
     */
    private const MAX_VARIABLE_DT = 1.0 / 14.0;

    public function __construct(
        private float $targetTickRate = 60.0,
        int $maxUpdatesPerFrame = 10,
        private bool $variableTimestep = false,
    ) {
        $this->timestepNs = (int)(1_000_000_000.0 / $targetTickRate);
        $this->maxUpdatesPerFrame = $maxUpdatesPerFrame;
    }

    /**
     * Set the FPS cap. 0 disables the cap; valid values are 30, 60, 120, 144.
     */
    public function setFpsCap(int $fps): void
    {
        if ($fps <= 0) {
            $this->minFrameTimeNs = 0;
            return;
        }
        $this->minFrameTimeNs = (int)(1_000_000_000.0 / max(1.0, (float)$fps));
    }

    public function getFpsCap(): int
    {
        if ($this->minFrameTimeNs <= 0) {
            return 0;
        }
        return (int)round(1_000_000_000.0 / $this->minFrameTimeNs);
    }

    /**
     * @param callable(float): void $update Called with fixed dt per tick
     * @param callable(float): void $render Called with interpolation factor (0..1)
     * @param callable(): bool $shouldStop
     */
    public function run(callable $update, callable $render, callable $shouldStop): void
    {
        // Variable-timestep mode: one update per rendered frame with the real
        // (clamped) frame time, so the sim rate tracks the render rate exactly —
        // no stepping when the render outruns a fixed tick. render() gets
        // interpolation = 1.0 because the state shown IS the just-updated state.
        if ($this->variableTimestep) {
            $previousTime = Clock::now();
            // Short moving average of the per-frame interval. The raw measured dt
            // jitters frame-to-frame (OS scheduling, where the vsync wait lands
            // relative to the measurement) even at a steady fps, which makes motion
            // advance by slightly uneven amounts → visible micro-judder. Averaging
            // a few frames yields a near-constant step while preserving the
            // real-time mean (no clock drift). Cleared-on-spike via the clamp below.
            /** @var list<float> $dtWindow */
            $dtWindow = [];
            $dtWindowSize = 8;
            while (!$shouldStop()) {
                $frameStart = Clock::now();
                $elapsed = $frameStart - $previousTime;
                $previousTime = $frameStart;

                $elapsedSeconds = $elapsed / 1_000_000_000.0;
                $this->frameTimeSamples[$this->sampleIndex] = $elapsedSeconds;
                $this->sampleIndex = ($this->sampleIndex + 1) % $this->sampleCount;

                // A genuine stall (> the spike guard) restarts the window so the
                // average isn't dragged by a one-off freeze.
                if ($elapsedSeconds > self::MAX_VARIABLE_DT) {
                    $dtWindow = [];
                }
                $dtWindow[] = $elapsedSeconds;
                if (count($dtWindow) > $dtWindowSize) {
                    array_shift($dtWindow);
                }
                $smoothed = array_sum($dtWindow) / count($dtWindow);

                $dt = min($smoothed, self::MAX_VARIABLE_DT);
                $update($dt);
                $render(1.0);

                $this->updateAverages();
                $this->throttleToFpsCap($frameStart);
            }
            return;
        }

        $fixedDt = 1.0 / $this->targetTickRate;
        $lag = 0;
        $previousTime = Clock::now();

        while (!$shouldStop()) {
            $frameStart = Clock::now();
            $currentTime = $frameStart;
            $elapsed = $currentTime - $previousTime;
            $previousTime = $currentTime;

            // Record the real inter-frame time for FPS stats, before clamping.
            $this->frameTimeSamples[$this->sampleIndex] = $elapsed / 1_000_000_000.0;
            $this->sampleIndex = ($this->sampleIndex + 1) % $this->sampleCount;

            // Spiral-of-death guard (1/2): clamp one frame's contribution to the
            // accumulator. A one-off stall — asset streaming, window drag/resize,
            // a debugger pause, the world-build splash — would otherwise inject a
            // backlog the catch-up loop below amplifies into a multi-frame freeze.
            $lag += min($elapsed, $this->timestepNs * $this->maxUpdatesPerFrame);

            // Fixed-timestep updates
            $tickCount = 0;
            while ($lag >= $this->timestepNs && $tickCount < $this->maxUpdatesPerFrame) {
                $update($fixedDt);
                $lag -= $this->timestepNs;
                $tickCount++;
            }

            // Spiral-of-death guard (2/2): if the catch-up budget is exhausted and
            // we are still behind, the sim cannot track real time. Discard the
            // backlog instead of carrying it forward (which compounds the slowdown
            // every frame). Under sustained overload the simulation slows slightly
            // — far better than a runaway freeze. Also keeps interpolation in [0,1].
            if ($lag > $this->timestepNs) {
                $lag = $this->timestepNs;
            }

            // Render with interpolation factor
            $interpolation = $lag / $this->timestepNs;
            $render((float)$interpolation);

            // Update average FPS
            $this->updateAverages();

            // Apply FPS cap (set via GraphicsSettings::$fpsCap). 0 = uncapped.
            $this->throttleToFpsCap($frameStart);
        }
    }

    /**
     * Sleep until at least minFrameTimeNs has elapsed since the supplied
     * frame start. Uses usleep for the bulk of the wait (sub-ns precision is
     * irrelevant) and is a no-op when no cap is active.
     */
    private function throttleToFpsCap(int $frameStartNs): void
    {
        if ($this->minFrameTimeNs <= 0) {
            return;
        }
        $remaining = $this->minFrameTimeNs - (Clock::now() - $frameStartNs);
        if ($remaining > 0) {
            // Convert ns to us, leaving 200us headroom for usleep wake-up jitter.
            $us = (int)max(0, ($remaining / 1000) - 200);
            if ($us > 0) {
                usleep($us);
            }
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
            $frameStart = Clock::now();
            $currentTime = $frameStart;
            $elapsed = $currentTime - $previousTime;
            $previousTime = $currentTime;

            // Record the real inter-frame time for FPS stats, before clamping.
            $this->frameTimeSamples[$this->sampleIndex] = $elapsed / 1_000_000_000.0;
            $this->sampleIndex = ($this->sampleIndex + 1) % $this->sampleCount;

            // Spiral-of-death guard (1/2): see run(). Clamp one frame's
            // contribution so a one-off stall cannot inject a multi-tick backlog.
            $lag += min($elapsed, $this->timestepNs * $this->maxUpdatesPerFrame);

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

            // Spiral-of-death guard (2/2): see run(). Discard backlog we cannot
            // catch up on rather than compounding it into the next frame.
            if ($lag > $this->timestepNs) {
                $lag = $this->timestepNs;
            }

            // 3. Render with interpolation
            $interpolation = $lag / $this->timestepNs;
            $render((float) $interpolation);

            $this->updateAverages();
            $this->throttleToFpsCap($frameStart);
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
