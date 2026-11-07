<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Season;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;

/**
 * Advances seasonal progression and updates vegetation/terrain materials.
 */
class SeasonSystem extends AbstractSystem
{
    // Base temperature per season (°C)
    private const TEMP_KEYS = [
        ['time' => 0.0,  'temp' => 16.0, 'humidity' => 0.55],  // spring
        ['time' => 0.25, 'temp' => 28.0, 'humidity' => 0.45],  // summer
        ['time' => 0.5,  'temp' => 14.0, 'humidity' => 0.65],  // autumn
        ['time' => 0.75, 'temp' => 4.0,  'humidity' => 0.60],  // winter
        ['time' => 1.0,  'temp' => 16.0, 'humidity' => 0.55],  // wrap
    ];

    // Vegetation leaf colors per season
    private const LEAF_KEYS = [
        ['time' => 0.0,  'r' => 0.24, 'g' => 0.61, 'b' => 0.24],  // spring green
        ['time' => 0.25, 'r' => 0.18, 'g' => 0.42, 'b' => 0.18],  // deep summer green
        ['time' => 0.5,  'r' => 0.55, 'g' => 0.42, 'b' => 0.18],  // autumn brown-yellow
        ['time' => 0.625,'r' => 0.78, 'g' => 0.33, 'b' => 0.10],  // deep autumn orange
        ['time' => 0.75, 'r' => 0.35, 'g' => 0.29, 'b' => 0.16],  // winter brown
        ['time' => 1.0,  'r' => 0.24, 'g' => 0.61, 'b' => 0.24],  // wrap
    ];

    /**
     * Season colour map for vegetation (shader plan 4.1): tint and desaturation
     * applied to grass and foliage, scaled by Season::$gradeStrength.
     */
    private const GRADE_KEYS = [
        ['time' => 0.0,   'r' => 1.02, 'g' => 1.10, 'b' => 0.90, 'd' => 0.0],   // fresh spring
        ['time' => 0.25,  'r' => 1.00, 'g' => 1.00, 'b' => 1.00, 'd' => 0.0],   // summer as authored
        ['time' => 0.5,   'r' => 1.18, 'g' => 0.94, 'b' => 0.62, 'd' => 0.05],  // autumn gold
        ['time' => 0.625, 'r' => 1.30, 'g' => 0.82, 'b' => 0.50, 'd' => 0.10],  // deep autumn
        ['time' => 0.75,  'r' => 0.90, 'g' => 0.92, 'b' => 0.96, 'd' => 0.40],  // winter, faded
        ['time' => 1.0,   'r' => 1.02, 'g' => 1.10, 'b' => 0.90, 'd' => 0.0],   // wrap
    ];

    // Sand color per season
    private const SAND_KEYS = [
        ['time' => 0.0,  'r' => 0.77, 'g' => 0.66, 'b' => 0.44],  // spring fresh
        ['time' => 0.25, 'r' => 0.83, 'g' => 0.74, 'b' => 0.51],  // summer golden
        ['time' => 0.5,  'r' => 0.72, 'g' => 0.60, 'b' => 0.35],  // autumn warm-brown
        ['time' => 0.75, 'r' => 0.63, 'g' => 0.56, 'b' => 0.44],  // winter grey-brown
        ['time' => 1.0,  'r' => 0.77, 'g' => 0.66, 'b' => 0.44],  // wrap
    ];

    public function update(World $world, float $dt): void
    {
        foreach ($world->query(Season::class) as $entity) {
            $season = $entity->get(Season::class);

            // Advance year
            $season->yearProgress += ($dt * $season->speed) / $season->yearDuration;
            $season->yearProgress -= floor($season->yearProgress);

            $t = $season->yearProgress;

            // Axial tilt: +15° at summer (t=0.25), -15° at winter (t=0.75),
            // 0° at spring/autumn. (cos, not sin — the old sin was phase-shifted
            // so it peaked in autumn; now the sun arc matches the actual season.)
            $season->axialTilt = cos(($t - 0.25) * 2.0 * M_PI) * 15.0;

            // Compute base temperature and humidity from seasonal curve
            [$season->gradeTint, $season->gradeDesaturation] = self::gradeAt($t, $season->gradeStrength);

            $climate = self::interpolateClimate(self::TEMP_KEYS, $t);
            $season->baseTemperature = $climate['temp'];
            $season->baseHumidity = $climate['humidity'];

            // Update vegetation materials
            $leaf = self::interpolateRGB(self::LEAF_KEYS, $t);
            $leafColor = new Color($leaf['r'], $leaf['g'], $leaf['b']);
            $leafLight = new Color(
                min(1.0, $leaf['r'] * 1.4),
                min(1.0, $leaf['g'] * 1.4),
                min(1.0, $leaf['b'] * 1.2),
            );

            MaterialRegistry::register('palm_leaves', new Material(albedo: $leafColor, roughness: 0.70));
            MaterialRegistry::register('palm_leaves_light', new Material(albedo: $leafLight, roughness: 0.65));
            MaterialRegistry::register('palm_branch', new Material(
                albedo: new Color($leaf['r'] * 0.8, $leaf['g'] * 0.9, $leaf['b'] * 0.7),
                roughness: 0.75,
            ));

            // Update sand_terrain material — albedo is used as seasonal tint by the renderer
            // The proc_mode 1 shader multiplies its hardcoded zone colors by u_season_tint
            $sand = self::interpolateRGB(self::SAND_KEYS, $t);
            MaterialRegistry::register('sand_terrain', new Material(
                albedo: new Color($sand['r'], $sand['g'], $sand['b']),
                roughness: 0.92,
            ));

            break; // Only one Season entity
        }
    }

    /**
     * Vegetation tint and desaturation for a year progress, mixed with the identity
     * by $strength.
     *
     * @return array{0: Color, 1: float}
     */
    public static function gradeAt(float $t, float $strength): array
    {
        $t -= floor($t);
        $s = max(0.0, min(1.0, $strength));
        $keys = self::GRADE_KEYS;
        for ($i = 0; $i < count($keys) - 1; $i++) {
            if ($t >= $keys[$i]['time'] && $t <= $keys[$i + 1]['time']) {
                $range = $keys[$i + 1]['time'] - $keys[$i]['time'];
                $f = $range > 0 ? ($t - $keys[$i]['time']) / $range : 0.0;
                $f = $f * $f * (3.0 - 2.0 * $f);
                $lerp = static fn (string $k): float => $keys[$i][$k] + ($keys[$i + 1][$k] - $keys[$i][$k]) * $f;

                return [
                    new Color(1.0 + ($lerp('r') - 1.0) * $s, 1.0 + ($lerp('g') - 1.0) * $s, 1.0 + ($lerp('b') - 1.0) * $s),
                    $lerp('d') * $s,
                ];
            }
        }

        return [new Color(1.0, 1.0, 1.0), 0.0];
    }

    /**
     * @param array<int, array{time: float, temp: float, humidity: float}> $keys
     * @return array{temp: float, humidity: float}
     */
    private static function interpolateClimate(array $keys, float $t): array
    {
        for ($i = 0; $i < count($keys) - 1; $i++) {
            if ($t >= $keys[$i]['time'] && $t <= $keys[$i + 1]['time']) {
                $range = $keys[$i + 1]['time'] - $keys[$i]['time'];
                $f = $range > 0 ? ($t - $keys[$i]['time']) / $range : 0.0;
                $f = $f * $f * (3.0 - 2.0 * $f);
                return [
                    'temp' => $keys[$i]['temp'] + ($keys[$i + 1]['temp'] - $keys[$i]['temp']) * $f,
                    'humidity' => $keys[$i]['humidity'] + ($keys[$i + 1]['humidity'] - $keys[$i]['humidity']) * $f,
                ];
            }
        }
        return ['temp' => $keys[0]['temp'], 'humidity' => $keys[0]['humidity']];
    }

    /**
     * @param array<int, array{time: float, r: float, g: float, b: float}> $keys
     * @return array{r: float, g: float, b: float}
     */
    private static function interpolateRGB(array $keys, float $t): array
    {
        for ($i = 0; $i < count($keys) - 1; $i++) {
            if ($t >= $keys[$i]['time'] && $t <= $keys[$i + 1]['time']) {
                $range = $keys[$i + 1]['time'] - $keys[$i]['time'];
                $f = $range > 0 ? ($t - $keys[$i]['time']) / $range : 0.0;
                $f = $f * $f * (3.0 - 2.0 * $f);
                return [
                    'r' => $keys[$i]['r'] + ($keys[$i + 1]['r'] - $keys[$i]['r']) * $f,
                    'g' => $keys[$i]['g'] + ($keys[$i + 1]['g'] - $keys[$i]['g']) * $f,
                    'b' => $keys[$i]['b'] + ($keys[$i + 1]['b'] - $keys[$i]['b']) * $f,
                ];
            }
        }
        return ['r' => $keys[0]['r'], 'g' => $keys[0]['g'], 'b' => $keys[0]['b']];
    }
}
