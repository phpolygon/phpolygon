<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Accessibility;

use PHPolygon\Math\Mat4;

/**
 * How strongly the comfort vignette should close, from how fast the camera turns and
 * moves. Fast artificial turning and gliding is what makes players motion sick; a
 * narrower view while it happens helps many of them. The thresholds below are design
 * values, not measurements: a walk or a slow look-around stays clear, a quick turn or a
 * ride closes the vignette fully. Rises fast, fades slowly, so it does not flicker.
 */
final class ComfortMotion
{
    /** Turn rate where the vignette starts / is fully closed, degrees per second. */
    public const TURN_START = 30.0;
    public const TURN_FULL = 120.0;
    /** Speed where the vignette starts / is fully closed, world units (metres) per second. */
    public const MOVE_START = 3.0;
    public const MOVE_FULL = 10.0;
    /** Time constants of the smoothing, seconds. */
    public const RISE = 0.1;
    public const FADE = 0.4;

    private ?Mat4 $previousView = null;
    private float $level = 0.0;

    /**
     * Advance by one frame and return the closing, 0..1.
     *
     * @param float $minimum the game's floor for this frame (a lift, swimming), 0..1
     * @param bool  $cut     the camera jumped (teleport, switch): not a motion
     */
    public function update(Mat4 $view, float $dt, float $minimum = 0.0, bool $cut = false): float
    {
        if ($dt <= 0.0) {
            // A pinned clock (screenshots): nothing moved in time.
            return max($this->level, $minimum);
        }
        $target = 0.0;
        if ($this->previousView !== null && !$cut) {
            $target = self::closing($this->previousView, $view, $dt);
        }
        $this->previousView = $view;
        $tau = $target > $this->level ? self::RISE : self::FADE;
        $this->level += ($target - $this->level) * (1.0 - exp(-$dt / $tau));
        return max($this->level, max(0.0, min(1.0, $minimum)));
    }

    public function reset(): void
    {
        $this->previousView = null;
        $this->level = 0.0;
    }

    /** Closing for the motion between two view matrices $dt seconds apart, before smoothing. */
    public static function closing(Mat4 $from, Mat4 $to, float $dt): float
    {
        $a = $from->toArray();
        $b = $to->toArray();
        // Forward is the third row of the view's rotation (column-major storage).
        $fa = [$a[2], $a[6], $a[10]];
        $fb = [$b[2], $b[6], $b[10]];
        $cos = max(-1.0, min(1.0, $fa[0] * $fb[0] + $fa[1] * $fb[1] + $fa[2] * $fb[2]));
        $turn = rad2deg(acos($cos)) / $dt;
        $pa = $from->inverse()->getTranslation();
        $pb = $to->inverse()->getTranslation();
        $speed = sqrt(($pb->x - $pa->x) ** 2 + ($pb->y - $pa->y) ** 2 + ($pb->z - $pa->z) ** 2) / $dt;
        $ramp = static fn (float $v, float $start, float $full): float => max(0.0, min(1.0, ($v - $start) / ($full - $start)));
        return max($ramp($turn, self::TURN_START, self::TURN_FULL), $ramp($speed, self::MOVE_START, self::MOVE_FULL));
    }
}
