<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp;

/**
 * A gain that follows a target in dB along a linear (amplitude) ramp, with
 * separate speeds for going down and coming back up – the classic "music
 * ducks under speech" follower.
 *
 * The speeds are rates, set by how long a swing between 0 dB and
 * {@see $swingDb} takes: `downSeconds` to fall that far, `upSeconds` to rise
 * back. Shorter moves take proportionally less time, and a target change in
 * the middle of a ramp simply continues from the current gain at the rate of
 * the new direction – no jump, no restart.
 *
 * Plain state, no clock and no audio: drive it per frame with {@see step()}
 * (live playback) or render a whole control envelope with {@see envelope()}
 * (offline mix). Both give the same numbers for the same steps.
 */
final class GainFollower
{
    /** Targets at or below this level mean silence (gain 0). */
    public const float FLOOR_DB = -80.0;

    /** Gain units per second going down / up (INF: jump). */
    private readonly float $downRate;
    private readonly float $upRate;

    private float $gain;
    private float $target;
    private float $targetDb;

    public function __construct(
        public readonly float $downSeconds,
        public readonly float $upSeconds,
        public readonly float $swingDb = -12.0,
        float $initialDb = 0.0,
    ) {
        if ($downSeconds < 0.0 || $upSeconds < 0.0) {
            throw new \InvalidArgumentException('GainFollower times must be >= 0');
        }
        if (!($swingDb < 0.0)) {
            throw new \InvalidArgumentException('GainFollower swing must be below 0 dB');
        }
        $swing = 1.0 - self::toGain($swingDb);
        $this->downRate = $downSeconds > 0.0 ? $swing / $downSeconds : INF;
        $this->upRate = $upSeconds > 0.0 ? $swing / $upSeconds : INF;
        $this->targetDb = min(0.0, $initialDb);
        $this->gain = $this->target = self::toGain($this->targetDb);
    }

    /** A follower with the same speeds, settled at $db. */
    public function fresh(float $db = 0.0): self
    {
        return new self($this->downSeconds, $this->upSeconds, $this->swingDb, $db);
    }

    /** Head for $db from wherever the gain is now (positive values: 0 dB). */
    public function setTarget(float $db): void
    {
        if (is_nan($db)) {
            return;
        }
        $this->targetDb = min(0.0, $db);
        $this->target = self::toGain($this->targetDb);
    }

    /**
     * Advance by $dt seconds, optionally towards a new target first.
     * Returns the gain after the step.
     */
    public function step(float $dt, ?float $targetDb = null): float
    {
        if ($targetDb !== null) {
            $this->setTarget($targetDb);
        }
        $dt = max(0.0, $dt);
        if ($this->gain > $this->target) {
            $this->gain = is_finite($this->downRate)
                ? max($this->target, $this->gain - $this->downRate * $dt)
                : $this->target;
        } elseif ($this->gain < $this->target) {
            $this->gain = is_finite($this->upRate)
                ? min($this->target, $this->gain + $this->upRate * $dt)
                : $this->target;
        }
        return $this->gain;
    }

    /** Jump to $db at once (no ramp). */
    public function reset(float $db = 0.0): void
    {
        $this->setTarget($db);
        $this->gain = $this->target;
    }

    /** Current linear gain (1 = unity). */
    public function gain(): float
    {
        return $this->gain;
    }

    /** Current gain in dB ({@see FLOOR_DB} for silence). */
    public function db(): float
    {
        return $this->gain <= 0.0 ? self::FLOOR_DB : max(self::FLOOR_DB, 20.0 * log10($this->gain));
    }

    /** The target in dB. */
    public function targetDb(): float
    {
        return $this->targetDb;
    }

    /** Has the gain arrived at its target? */
    public function isSettled(): bool
    {
        return $this->gain === $this->target;
    }

    /**
     * Offline: the gain at every control point 0, block, 2·block … up to and
     * including $frames (⌊frames / block⌋ + 1 values). $targetDbAt receives
     * the time in seconds of each control point and returns the target there;
     * the value at a point is the gain BEFORE stepping towards that target,
     * so a change shows up one block later – as it would live. Starts from
     * the follower's current state and leaves it at the end.
     *
     * @param \Closure(float): float $targetDbAt
     * @return list<float>
     */
    public function envelope(int $frames, int $sampleRate, \Closure $targetDbAt, int $block = 64): array
    {
        if ($block <= 0 || $sampleRate <= 0) {
            throw new \InvalidArgumentException('GainFollower block and sample rate must be > 0');
        }
        $dt = $block / $sampleRate;
        $out = [];
        for ($b = 0; $b * $block <= $frames; $b++) {
            $out[] = $this->gain;
            $this->step($dt, $targetDbAt($b * $dt));
        }
        return $out;
    }

    /**
     * Expand a control envelope from {@see envelope()} to one gain per
     * sample, linearly interpolated within each block.
     *
     * @param list<float> $envelope
     * @return list<float>
     */
    public static function expand(array $envelope, int $frames, int $block = 64): array
    {
        if ($block <= 0) {
            throw new \InvalidArgumentException('GainFollower block must be > 0');
        }
        $out = [];
        $last = $envelope === [] ? 1.0 : $envelope[count($envelope) - 1];
        for ($i = 0; $i < $frames; $i++) {
            $b = intdiv($i, $block);
            $g0 = $envelope[$b] ?? $last;
            $g1 = $envelope[$b + 1] ?? $g0;
            $out[] = $g0 + ($g1 - $g0) * (($i - $b * $block) / $block);
        }
        return $out;
    }

    /** dB → linear gain, {@see FLOOR_DB} or below = 0. */
    public static function toGain(float $db): float
    {
        if ($db <= self::FLOOR_DB) {
            return 0.0;
        }
        return $db >= 0.0 ? 1.0 : 10.0 ** ($db / 20.0);
    }
}
