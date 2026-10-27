<?php

declare(strict_types=1);

namespace PHPolygon\UI;

/**
 * One UI magnification shared by a {@see \PHPolygon\Rendering\ScaledRenderer2D}
 * and a {@see \PHPolygon\Runtime\ScaledInput}: layout code works in logical
 * pixels, the renderer draws them `factor` times larger and the input reports
 * the pointer in the same logical pixels. Change {@see $factor} once per frame
 * (e.g. from {@see forScreen()}) and both follow.
 *
 * A layout written for a 1080-line screen keeps its proportions on 1440p or
 * 2160p this way, and text stays crisp: the renderer asks for the larger font
 * size instead of magnifying a glyph atlas.
 */
final class UIScale
{
    public float $factor;

    public function __construct(float $factor = 1.0)
    {
        $this->factor = self::clamp($factor);
    }

    public function set(float $factor): void
    {
        $this->factor = self::clamp($factor);
    }

    /**
     * Factor for a screen $height pixels tall: $height / $designHeight (never
     * below $minimum, so small windows keep the design size) times the player's
     * $userFactor, snapped to $step so that 1 px lines land on whole pixels as
     * often as possible (2160 / 1080 = exactly 2).
     */
    public static function forScreen(
        float $height,
        float $designHeight = 1080.0,
        float $userFactor = 1.0,
        float $minimum = 1.0,
        float $step = 0.125,
    ): float {
        $screen = $designHeight > 0.0 ? $height / $designHeight : 1.0;
        $raw = max($minimum, $screen) * $userFactor;
        if ($step > 0.0) {
            $raw = round($raw / $step) * $step;
        }

        return self::clamp($raw);
    }

    private static function clamp(float $factor): float
    {
        return max(0.25, min(8.0, $factor));
    }
}
