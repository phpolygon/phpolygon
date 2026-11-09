<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Effects;

use PHPolygon\Rendering\GraphicsSettings;

/**
 * How a switched-off effect leaves the scene programs: a game wraps the effect's
 * block in its shader snippet in `#ifndef PHPOLYGON_EFFECT_OFF_<NAME>`, and the
 * renderer compiles every mesh program (base and variants) with the define of each
 * effect {@see GraphicsSettings::effectEnabled()} reports off. An effect that is
 * off then costs nothing; switching it rebuilds the mesh programs at the next frame.
 * Fullscreen passes and 2D effects read the same switch themselves.
 */
final class EffectSwitches
{
    public const string PREFIX = 'PHPOLYGON_EFFECT_OFF_';

    /** The define that leaves effect $name out. */
    public static function define(string $name): string
    {
        return self::PREFIX . strtoupper($name);
    }

    /**
     * Defines of every effect switched off in $settings, sorted (a stable program key).
     *
     * @return list<string>
     */
    public static function offDefines(GraphicsSettings $settings): array
    {
        $out = [];
        foreach ($settings->effects as $name => $on) {
            if ($on === false && preg_match('/^[a-z][a-z0-9_]*$/', (string) $name) === 1) {
                $out[] = self::define((string) $name);
            }
        }
        sort($out);

        return $out;
    }
}
