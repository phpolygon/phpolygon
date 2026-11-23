<?php

declare(strict_types=1);

namespace PHPolygon\Camera;

use InvalidArgumentException;
use LogicException;

/**
 * Runs one `CameraFlight` in time, bracketed by a blend-in from the pose the
 * camera started from and a blend-out back to it:
 *
 *   [ blend-in | flight | blend-out ]   total = blendIn + flight duration + blendOut
 *
 * The caller owns the camera. It hands in the origin pose (the player's own
 * position/yaw/pitch at the moment the flight starts), applies each returned pose
 * to its camera, and gets exactly `origin()` back once the flight ends, is
 * cancelled or is skipped, so restoring the controls never jumps.
 *
 * `poseAt($elapsed)` is pure; `advance($dt)` only accumulates elapsed time, so the
 * pose at a given elapsed time does not depend on the frame rate. A blend of 0
 * seconds means no blend: the flight then begins/ends with a cut from/to the origin.
 */
final class CameraFlightPlayer
{
    private float $elapsed = 0.0;
    private bool $running = false;
    private bool $finished = false;
    private readonly CameraPose $firstPose;
    private readonly CameraPose $lastPose;
    private readonly float $yawOffset;

    public function __construct(
        private readonly CameraFlight $flight,
        private readonly CameraPose $origin,
        private readonly float $blendIn = 0.6,
        private readonly float $blendOut = 0.6,
    ) {
        foreach (['blendIn' => $blendIn, 'blendOut' => $blendOut] as $name => $value) {
            if (!is_finite($value) || $value < 0.0) {
                throw new InvalidArgumentException("CameraFlightPlayer {$name} must be a finite number >= 0, got {$value}.");
            }
        }
        // Shift the flight's unwrapped yaw by whole turns so it starts within PI of the
        // origin yaw: the blend-in then takes the short way and nothing jumps by 2 PI.
        $start = $flight->evaluate(0.0);
        $turns = round(($origin->yaw - $start->yaw) / (2.0 * M_PI));
        $offset = $turns * 2.0 * M_PI;
        $this->firstPose = $start->withYawOffset($offset);
        $this->lastPose = $flight->evaluate(1.0)->withYawOffset($offset);
        $this->yawOffset = $offset;
    }

    /** The pose the flight started from; apply it to restore the camera. */
    public function origin(): CameraPose
    {
        return self::fresh($this->origin);
    }

    public function flight(): CameraFlight
    {
        return $this->flight;
    }

    /** Blend-in + flight + blend-out, in seconds. */
    public function totalDuration(): float
    {
        return $this->blendIn + $this->flight->duration() + $this->blendOut;
    }

    public function start(): void
    {
        if ($this->running) {
            throw new LogicException('CameraFlightPlayer::start() called while the flight is running; cancel() it first.');
        }
        $this->elapsed = 0.0;
        $this->running = true;
        $this->finished = false;
    }

    /** Advance by `$dt` seconds (negative values count as 0) and return the pose to apply. */
    public function advance(float $dt): CameraPose
    {
        if (!$this->running) {
            return $this->currentPose();
        }
        if ($dt > 0.0) {
            $this->elapsed += $dt;
        }
        if ($this->elapsed >= $this->totalDuration()) {
            $this->finish();
        }
        return $this->currentPose();
    }

    /** End the flight now. The camera is back at `origin()`. Safe to call at any time. */
    public function cancel(): CameraPose
    {
        if ($this->running) {
            $this->finish();
        }
        return self::fresh($this->origin);
    }

    /** Jump to the end: the same state as `cancel()`; the caller restores the controls from `origin()`. */
    public function skip(): CameraPose
    {
        return $this->cancel();
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    /** 0 before start, 0..1 while running (by elapsed time), 1 when finished. */
    public function progress(): float
    {
        if ($this->finished) {
            return 1.0;
        }
        if (!$this->running) {
            return 0.0;
        }
        return min(1.0, $this->elapsed / $this->totalDuration());
    }

    public function elapsed(): float
    {
        return $this->elapsed;
    }

    /** Pose for the current state: the origin while idle or finished. */
    public function currentPose(): CameraPose
    {
        return $this->running ? $this->poseAt($this->elapsed) : self::fresh($this->origin);
    }

    /**
     * Pose at `$elapsed` seconds since start, including both blends. Pure.
     * At or past the total duration (and, with a blend-in, at 0) this is exactly `origin()`.
     */
    public function poseAt(float $elapsed): CameraPose
    {
        $total = $this->totalDuration();
        if ($elapsed >= $total || ($elapsed <= 0.0 && $this->blendIn > 0.0)) {
            return self::fresh($this->origin);
        }

        if ($elapsed < $this->blendIn) {
            $e = CameraEasing::Smoothstep->apply($elapsed / $this->blendIn);
            return self::fresh(CameraPose::lerp($this->origin, $this->firstPose, $e));
        }

        $flightEnd = $this->blendIn + $this->flight->duration();
        if ($elapsed <= $flightEnd) {
            $t = ($elapsed - $this->blendIn) / $this->flight->duration();
            return $this->flight->evaluate($t)->withYawOffset($this->yawOffset);
        }

        $e = CameraEasing::Smoothstep->apply(($elapsed - $flightEnd) / $this->blendOut);
        return self::fresh(CameraPose::lerp($this->lastPose, $this->origin, $e));
    }

    /** Every pose handed out is a copy: `Vec3` is mutable and callers move cameras in place. */
    private static function fresh(CameraPose $pose): CameraPose
    {
        return new CameraPose($pose->position, $pose->yaw, $pose->pitch);
    }

    private function finish(): void
    {
        $this->running = false;
        $this->finished = true;
    }
}
