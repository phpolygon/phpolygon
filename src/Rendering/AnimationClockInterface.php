<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * A 3D renderer whose shader animation time (`u_time`: water ripples, wave and
 * sway vertex animation, procedural pulses) follows a clock the caller drives.
 *
 * The engine sets it once per rendered frame, before `beginFrame()`, to the
 * simulated time (the sum of all update steps), so animations run at the same
 * speed at any frame rate. A renderer that is never driven (tests, tools
 * rendering frames directly) keeps its own fallback of 1/60 s per frame.
 */
interface AnimationClockInterface
{
    /** Shader animation time for the next frame, in seconds. */
    public function setAnimationTime(float $seconds): void;
}
