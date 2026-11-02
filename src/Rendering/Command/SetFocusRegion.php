<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;

/**
 * The part of the screen the player works in (a terminal, a dialogue partner, a
 * puzzle): with AccessibilitySettings::$focusDim above 0 everything outside it is
 * dimmed. Screen coordinates 0..1, origin top left. Sticky until the next command;
 * {@see clear()} ends the focus. An empty rectangle (left = right) dims the whole 3D
 * image, e.g. behind a full-screen 2D panel. With the option off it changes nothing.
 */
readonly class SetFocusRegion
{
    /** @param float $feather width of the soft edge, as a fraction of the screen height */
    public function __construct(
        public float $left = 0.0,
        public float $top = 0.0,
        public float $right = 0.0,
        public float $bottom = 0.0,
        public float $feather = 0.04,
        public bool $active = true,
    ) {}

    public static function clear(): self
    {
        return new self(active: false);
    }

    /**
     * The screen rectangle around a world-space box seen through a camera, grown by
     * $margin (screen fraction, on both axes). A box reaching behind the camera
     * focuses the whole screen.
     */
    public static function aroundBox(Vec3 $min, Vec3 $max, Mat4 $view, Mat4 $projection, float $margin = 0.02, float $feather = 0.04): self
    {
        $m = $projection->multiply($view)->toArray();
        [$l, $t, $r, $b] = [1.0, 1.0, 0.0, 0.0];
        foreach ([$min->x, $max->x] as $x) {
            foreach ([$min->y, $max->y] as $y) {
                foreach ([$min->z, $max->z] as $z) {
                    $w = $m[3] * $x + $m[7] * $y + $m[11] * $z + $m[15];
                    if ($w <= 1e-6) {
                        return new self(0.0, 0.0, 1.0, 1.0, $feather);
                    }
                    $sx = (($m[0] * $x + $m[4] * $y + $m[8] * $z + $m[12]) / $w + 1.0) * 0.5;
                    $sy = (1.0 - ($m[1] * $x + $m[5] * $y + $m[9] * $z + $m[13]) / $w) * 0.5;
                    [$l, $t, $r, $b] = [min($l, $sx), min($t, $sy), max($r, $sx), max($b, $sy)];
                }
            }
        }
        $clamp = static fn (float $v): float => max(0.0, min(1.0, $v));
        return new self($clamp($l - $margin), $clamp($t - $margin), $clamp($r + $margin), $clamp($b + $margin), $feather);
    }
}
