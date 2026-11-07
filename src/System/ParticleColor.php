<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Rendering\Color;

/**
 * Colour and alpha of a particle over its lifetime (shader plan 7.1). A particle's
 * colour travels as ONE float in the instance parameters (v_instance_params.x): six
 * bits per channel, RGBA in 24 bits - exactly representable in a 32-bit float, so
 * the CPU path, the step kernel and the billboard kernel all carry it unchanged.
 * Materials with Material::$particleTint multiply albedo, emission and alpha by it.
 */
final class ParticleColor
{
    /** Stops a gradient may have (the step kernel's parameter block holds four). */
    public const MAX_STOPS = 4;

    /** Pack a colour (0..1 per channel, alpha included) into one float. */
    public static function pack(float $r, float $g, float $b, float $a): float
    {
        $q = static fn (float $v): int => (int) round(max(0.0, min(1.0, $v)) * 63.0);

        return (float) ($q($r) + $q($g) * 64 + $q($b) * 4096 + $q($a) * 262144);
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} r, g, b, a */
    public static function unpack(float $packed): array
    {
        $v = (int) $packed;

        return [($v % 64) / 63.0, (intdiv($v, 64) % 64) / 63.0, (intdiv($v, 4096) % 64) / 63.0, (intdiv($v, 262144) % 64) / 63.0];
    }

    /**
     * The emitter's gradient as [t, r, g, b, a] stops, ascending, at most MAX_STOPS:
     * colorGradient when set, else startColor -> endColor. Empty when colorOverLife
     * is off.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float, 4: float}>
     */
    public static function stops(ParticleEmitter $emitter): array
    {
        if (!$emitter->colorOverLife) {
            return [];
        }
        $stops = [];
        if ($emitter->colorGradient !== []) {
            foreach ($emitter->colorGradient as [$t, $c]) {
                $stops[] = [max(0.0, min(1.0, (float) $t)), $c->r, $c->g, $c->b, $c->a];
            }
            usort($stops, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
            if (count($stops) > self::MAX_STOPS) {
                $stops = array_slice($stops, 0, self::MAX_STOPS);
            }
        } else {
            $s = $emitter->startColor;
            $e = $emitter->endColor;
            $stops = [[0.0, $s->r, $s->g, $s->b, $s->a], [1.0, $e->r, $e->g, $e->b, $e->a]];
        }

        return $stops;
    }

    /**
     * The packed colour at lifetime fraction $t (0 born, 1 dead).
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float, 4: float}> $stops
     */
    public static function packedAt(array $stops, float $t): float
    {
        $n = count($stops);
        if ($n === 0) {
            return 0.0;
        }
        if ($t <= $stops[0][0]) {
            return self::pack($stops[0][1], $stops[0][2], $stops[0][3], $stops[0][4]);
        }
        for ($i = 1; $i < $n; $i++) {
            if ($t <= $stops[$i][0]) {
                $a = $stops[$i - 1];
                $b = $stops[$i];
                $span = max($b[0] - $a[0], 1e-6);
                $k = ($t - $a[0]) / $span;

                return self::pack(
                    $a[1] + ($b[1] - $a[1]) * $k,
                    $a[2] + ($b[2] - $a[2]) * $k,
                    $a[3] + ($b[3] - $a[3]) * $k,
                    $a[4] + ($b[4] - $a[4]) * $k,
                );
            }
        }
        $l = $stops[$n - 1];

        return self::pack($l[1], $l[2], $l[3], $l[4]);
    }

    /** A colour for a gradient stop. */
    public static function stop(float $t, Color $c): array
    {
        return [$t, $c];
    }
}
