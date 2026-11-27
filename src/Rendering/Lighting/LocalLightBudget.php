<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Lighting;

/**
 * Chooses which local lights of one kind (point, spot, rect) burn when more are candidates
 * than the budget allows.
 *
 * Every light costs the renderer its per-frame collection, and point lights past eight the
 * upload of the light grid. A lowest quality tier therefore sets a budget per kind
 * ({@see \PHPolygon\Rendering\GraphicsSettings::$pointLightBudget} and its siblings); the
 * lights over the budget are not emitted at all.
 *
 * Lights rank by the distance from the camera to the light's sphere (0 inside its range),
 * then by the distance to its centre. A light that was in last frame keeps its slot against
 * a challenger that is only {@see HOLD} metres nearer, so a camera standing at the border
 * does not make two lamps trade places every frame.
 */
final class LocalLightBudget
{
    /** A challenger must be this much nearer (sphere distance in metres) to take the slot of a light that is in. */
    public const float HOLD = 6.0;

    /**
     * The $cap best lights.
     *
     * @param array<int|string, array{0: float, 1: float}> $ranks key => [distance to the sphere, distance to the centre]
     * @param array<int|string, true> $previous the keys selected last time (they get the hold margin)
     * @return array<int|string, true>
     */
    public static function select(array $ranks, int $cap, array $previous): array
    {
        if ($cap <= 0) {
            return [];
        }
        if (count($ranks) <= $cap) {
            return array_fill_keys(array_keys($ranks), true);
        }
        $score = [];
        foreach ($ranks as $key => [$sphere, $centre]) {
            $score[$key] = [$sphere - (isset($previous[$key]) ? self::HOLD : 0.0), $centre];
        }
        $keys = array_keys($score);
        usort($keys, static fn (int|string $a, int|string $b): int
            => ($score[$a][0] <=> $score[$b][0]) ?: ($score[$a][1] <=> $score[$b][1]) ?: ($a <=> $b));

        return array_fill_keys(array_slice($keys, 0, $cap), true);
    }
}
