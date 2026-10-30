<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

use InvalidArgumentException;

/**
 * Turns how a character moves into when its feet hit the ground: one state
 * machine per character, fed once a frame, answering with a {@see StepEvent} or
 * null. It plays nothing and knows no surface, so the same cadence can drive
 * sound, dust or a camera bob.
 *
 * Steps: the interval falls with speed as
 * `referenceInterval * (referenceSpeed / v) ^ exponent`, clamped; v is smoothed so
 * a jolt in the speed does not jolt the rhythm. Below $minSpeed for $stopGraceSeconds
 * the character stands and the first step of the next start comes after
 * 1 - $startPhase of an interval; slow frames inside that grace keep the rhythm but
 * fire nothing. Nothing fires in the air. A gap in the grounded flag shorter than
 * $minAirSeconds keeps the rhythm running; a real flight holds it.
 *
 * Measuring the speed: it is meant as the character's real horizontal speed, one
 * value per update. Taken from the position change of a frame, it is only exact
 * while the position changes every frame. Physics slower than the render loop gives
 * frames of 0 and frames of a whole tick: the mean is right, so the rhythm holds (the
 * smoothing averages it, the grace bridges the zeros), but $teleportSpeed then has to
 * lie above the single-frame spike, or better the game divides the movement by the
 * time since the position last changed. At very low speed such spikes also pass
 * $minSpeed, which again the division avoids. No speed at all, or none for longer
 * than the grace, is a stop.
 *
 * Landings: touching down after at least $minAirSeconds with a fall of at least
 * $minFallSpeed is one Landing, with strength rising to 1 at $fullFallSpeed. The
 * physics zeroes the vertical velocity on contact, so the fastest fall seen in
 * the air is what counts. The next step then waits $landingHoldoff of an interval.
 * It arms only on a grounded frame: dropping into the world at a spawn makes no
 * sound.
 *
 * Teleports: a speed above $teleportSpeed is a jump of the position, not a walk.
 * It makes no sound and resets everything, like a frame with $enabled false -
 * which is how a game reports anything that is not walking (swimming, riding,
 * flying, a scripted animation).
 */
final class StepCadence
{
    /** Phase of a step that is due but has no ground yet: fires on the next grounded frame. */
    private const float PHASE_DUE = 1.0;

    private float $phase;
    private float $smoothedSpeed = 0.0;
    private bool $moving = false;
    private float $slowSeconds = 0.0;
    private bool $armed = false;
    private float $airSeconds = 0.0;
    private float $fallSpeed = 0.0;
    private int $side = 1;

    public function __construct(
        public readonly float $referenceInterval,
        public readonly float $referenceSpeed,
        public readonly float $teleportSpeed,
        public readonly float $exponent = 0.55,
        public readonly float $minInterval = 0.0,
        public readonly float $maxInterval = PHP_FLOAT_MAX,
        public readonly float $minSpeed = 1.0,
        public readonly float $smoothingSeconds = 0.15,
        public readonly float $startPhase = 0.5,
        public readonly float $minAirSeconds = 0.15,
        public readonly float $minFallSpeed = 3.0,
        public readonly float $fullFallSpeed = 12.0,
        public readonly float $landingHoldoff = 0.6,
        public readonly float $stopGraceSeconds = 0.1,
    ) {
        if (!($referenceInterval > 0.0) || !($referenceSpeed > 0.0)) {
            throw new InvalidArgumentException('StepCadence: need referenceInterval > 0 and referenceSpeed > 0');
        }
        if ($minInterval > $maxInterval) {
            throw new InvalidArgumentException('StepCadence: need minInterval <= maxInterval');
        }
        if (!($minSpeed > 0.0) || !($teleportSpeed > $minSpeed)) {
            throw new InvalidArgumentException('StepCadence: need 0 < minSpeed < teleportSpeed');
        }
        if ($smoothingSeconds < 0.0 || $stopGraceSeconds < 0.0) {
            throw new InvalidArgumentException('StepCadence: smoothingSeconds and stopGraceSeconds must be >= 0');
        }
        if ($startPhase < 0.0 || $startPhase >= 1.0 || $landingHoldoff < 0.0 || $landingHoldoff > 1.0) {
            throw new InvalidArgumentException('StepCadence: need 0 <= startPhase < 1 and 0 <= landingHoldoff <= 1');
        }
        if (!($fullFallSpeed > $minFallSpeed)) {
            throw new InvalidArgumentException('StepCadence: need fullFallSpeed > minFallSpeed');
        }
        $this->phase = $startPhase;
    }

    /**
     * Advance one frame.
     *
     * @param float $speed horizontal speed in m/s; see the class comment on measuring it
     * @param float $verticalVelocity negative while falling
     * @param bool $enabled false while the character is not on its feet
     */
    public function update(float $dt, float $speed, bool $grounded, float $verticalVelocity = 0.0, bool $enabled = true): ?StepEvent
    {
        if ($dt <= 0.0) {
            return null;
        }
        if (!$enabled || $speed > $this->teleportSpeed) {
            $this->reset();
            return null;
        }
        if (!$grounded) {
            if ($this->moving && $this->airSeconds < $this->minAirSeconds) {
                // Downhill or over a seam the flag drops for a frame or two: the
                // legs keep going. A step that falls due waits for the ground.
                $this->phase = min(self::PHASE_DUE, $this->phase + $dt / $this->intervalFor($this->smoothedSpeed));
            }
            if ($this->armed) {
                $this->airSeconds += $dt;
                $this->fallSpeed = max($this->fallSpeed, -$verticalVelocity);
            }
            return null;
        }

        $landing = $this->landing();
        $this->armed = true;

        $slow = $speed < $this->minSpeed;
        if ($slow) {
            $this->slowSeconds += $dt;
            if ($this->slowSeconds >= $this->stopGraceSeconds) {
                $this->moving = false;
            }
            if (!$this->moving) {
                $this->phase = $this->startPhase;
                return $landing === null ? null : new StepEvent(StepEventKind::Landing, 0, 0.0, $landing);
            }
        } else {
            $this->slowSeconds = 0.0;
        }

        if ($this->moving) {
            $this->smoothedSpeed += ($speed - $this->smoothedSpeed) * $this->smoothing($dt);
        } else {
            $this->moving = true;
            $this->smoothedSpeed = $speed;
        }

        if ($landing !== null) {
            $this->phase = 1.0 - $this->landingHoldoff;
            return new StepEvent(StepEventKind::Landing, 0, $this->smoothedSpeed, $landing);
        }

        $this->phase += $dt / $this->intervalFor($this->smoothedSpeed);
        if ($slow) {
            // Not stopped yet, but no foot lands on a frame that may be the stop.
            $this->phase = min(self::PHASE_DUE, $this->phase);
            return null;
        }
        if ($this->phase < 1.0) {
            return null;
        }
        $this->phase -= floor($this->phase);
        $this->side = -$this->side;

        return new StepEvent(StepEventKind::Step, $this->side, $this->smoothedSpeed, 0.0);
    }

    /** Seconds between steps at $speed. */
    public function intervalFor(float $speed): float
    {
        $interval = $this->referenceInterval * ($this->referenceSpeed / max($speed, 1e-6)) ** $this->exponent;

        return min($this->maxInterval, max($this->minInterval, $interval));
    }

    /** Forget everything: the next frame starts like a fresh character, disarmed. */
    public function reset(): void
    {
        $this->phase = $this->startPhase;
        $this->smoothedSpeed = 0.0;
        $this->moving = false;
        $this->slowSeconds = 0.0;
        $this->armed = false;
        $this->airSeconds = 0.0;
        $this->fallSpeed = 0.0;
        $this->side = 1;
    }

    /** Strength of the landing this grounded frame ends, or null; clears the air state. */
    private function landing(): ?float
    {
        $air = $this->airSeconds;
        $fall = $this->fallSpeed;
        $this->airSeconds = 0.0;
        $this->fallSpeed = 0.0;
        if ($air < $this->minAirSeconds || $fall < $this->minFallSpeed) {
            return null;
        }

        return min(1.0, ($fall - $this->minFallSpeed) / ($this->fullFallSpeed - $this->minFallSpeed));
    }

    private function smoothing(float $dt): float
    {
        return $this->smoothingSeconds > 0.0 ? 1.0 - exp(-$dt / $this->smoothingSeconds) : 1.0;
    }
}
