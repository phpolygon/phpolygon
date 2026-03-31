<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\DayNightCycle;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetSkyColors;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;

/**
 * Drives the day/night cycle: updates sun direction, sky colors, ambient light, and fog.
 *
 * Reads a single DayNightCycle component. Updates DirectionalLight components,
 * sky material emissions, ambient light, and fog color/distance each frame.
 *
 * Register BEFORE Renderer3DSystem.
 */
class DayNightSystem extends AbstractSystem
{
    /** @var array<int, array{time: float, r: float, g: float, b: float, intensity: float}> */
    private const SUN_KEYS = [
        ['time' => 0.0,  'r' => 0.0,  'g' => 0.0,  'b' => 0.0,  'intensity' => 0.0],   // midnight
        ['time' => 0.15, 'r' => 0.0,  'g' => 0.0,  'b' => 0.0,  'intensity' => 0.0],   // late night
        ['time' => 0.20, 'r' => 0.6,  'g' => 0.25, 'b' => 0.12, 'intensity' => 0.1],   // first light
        ['time' => 0.25, 'r' => 1.0,  'g' => 0.53, 'b' => 0.27, 'intensity' => 0.4],   // dawn
        ['time' => 0.30, 'r' => 1.0,  'g' => 0.67, 'b' => 0.33, 'intensity' => 0.8],   // sunrise
        ['time' => 0.40, 'r' => 1.0,  'g' => 0.98, 'b' => 0.94, 'intensity' => 1.3],   // morning
        ['time' => 0.50, 'r' => 1.0,  'g' => 0.98, 'b' => 0.94, 'intensity' => 1.5],   // noon
        ['time' => 0.60, 'r' => 1.0,  'g' => 0.96, 'b' => 0.88, 'intensity' => 1.3],   // afternoon
        ['time' => 0.70, 'r' => 1.0,  'g' => 0.65, 'b' => 0.40, 'intensity' => 1.0],   // golden hour
        ['time' => 0.75, 'r' => 1.0,  'g' => 0.40, 'b' => 0.20, 'intensity' => 0.7],   // sunset
        ['time' => 0.80, 'r' => 0.8,  'g' => 0.27, 'b' => 0.13, 'intensity' => 0.3],   // dusk
        ['time' => 0.85, 'r' => 0.4,  'g' => 0.12, 'b' => 0.06, 'intensity' => 0.1],   // twilight
        ['time' => 0.90, 'r' => 0.0,  'g' => 0.0,  'b' => 0.0,  'intensity' => 0.0],   // night
        ['time' => 1.0,  'r' => 0.0,  'g' => 0.0,  'b' => 0.0,  'intensity' => 0.0],   // wrap
    ];

    /** @var array<int, array{time: float, r: float, g: float, b: float, intensity: float}> */
    private const AMBIENT_KEYS = [
        ['time' => 0.0,  'r' => 0.04, 'g' => 0.09, 'b' => 0.16, 'intensity' => 0.05],  // night
        ['time' => 0.2,  'r' => 0.29, 'g' => 0.19, 'b' => 0.31, 'intensity' => 0.10],  // dawn
        ['time' => 0.25, 'r' => 0.75, 'g' => 0.56, 'b' => 0.44, 'intensity' => 0.15],  // sunrise
        ['time' => 0.35, 'r' => 0.72, 'g' => 0.82, 'b' => 0.91, 'intensity' => 0.25],  // morning
        ['time' => 0.5,  'r' => 0.78, 'g' => 0.86, 'b' => 0.94, 'intensity' => 0.28],  // noon
        ['time' => 0.65, 'r' => 0.78, 'g' => 0.86, 'b' => 0.94, 'intensity' => 0.25],  // afternoon
        ['time' => 0.75, 'r' => 0.63, 'g' => 0.38, 'b' => 0.25, 'intensity' => 0.15],  // sunset
        ['time' => 0.8,  'r' => 0.16, 'g' => 0.09, 'b' => 0.22, 'intensity' => 0.10],  // dusk
        ['time' => 0.9,  'r' => 0.04, 'g' => 0.09, 'b' => 0.16, 'intensity' => 0.05],  // night
        ['time' => 1.0,  'r' => 0.04, 'g' => 0.09, 'b' => 0.16, 'intensity' => 0.05],  // wrap
    ];

    // Sky zenith emission colors (matched to original #1E5FAA at noon)
    private const SKY_ZENITH_KEYS = [
        ['time' => 0.0,  'r' => 0.01, 'g' => 0.02, 'b' => 0.06],  // very dark navy
        ['time' => 0.2,  'r' => 0.25, 'g' => 0.15, 'b' => 0.40],  // purple dawn
        ['time' => 0.3,  'r' => 0.12, 'g' => 0.37, 'b' => 0.67],  // #1E5FAA blue
        ['time' => 0.5,  'r' => 0.12, 'g' => 0.37, 'b' => 0.67],  // noon
        ['time' => 0.7,  'r' => 0.12, 'g' => 0.37, 'b' => 0.67],  // afternoon
        ['time' => 0.8,  'r' => 0.20, 'g' => 0.10, 'b' => 0.30],  // dusk purple
        ['time' => 0.9,  'r' => 0.01, 'g' => 0.02, 'b' => 0.06],  // night
        ['time' => 1.0,  'r' => 0.01, 'g' => 0.02, 'b' => 0.06],
    ];

    // Sky horizon emission colors (matched to original #87AECC at noon)
    private const SKY_HORIZON_KEYS = [
        ['time' => 0.0,  'r' => 0.02, 'g' => 0.03, 'b' => 0.08],
        ['time' => 0.2,  'r' => 0.80, 'g' => 0.45, 'b' => 0.30],  // orange dawn
        ['time' => 0.25, 'r' => 0.90, 'g' => 0.70, 'b' => 0.50],  // warm sunrise
        ['time' => 0.35, 'r' => 0.53, 'g' => 0.68, 'b' => 0.80],  // #87AECC haze
        ['time' => 0.5,  'r' => 0.53, 'g' => 0.68, 'b' => 0.80],  // noon
        ['time' => 0.65, 'r' => 0.70, 'g' => 0.60, 'b' => 0.50],  // warm afternoon
        ['time' => 0.75, 'r' => 0.95, 'g' => 0.40, 'b' => 0.15],  // red sunset
        ['time' => 0.8,  'r' => 0.50, 'g' => 0.20, 'b' => 0.25],  // dusk
        ['time' => 0.9,  'r' => 0.02, 'g' => 0.03, 'b' => 0.08],
        ['time' => 1.0,  'r' => 0.02, 'g' => 0.03, 'b' => 0.08],
    ];

    public function __construct(
        private readonly RenderCommandList $commandList,
    ) {}

    private ?DayNightCycle $cachedCycle = null;

    public function update(World $world, float $dt): void
    {
        foreach ($world->query(DayNightCycle::class) as $entity) {
            $this->cachedCycle = $entity->get(DayNightCycle::class);
            break;
        }
        if ($this->cachedCycle !== null && !$this->cachedCycle->paused && $dt > 0) {
            $prevDay = floor($this->cachedCycle->timeOfDay);
            $this->cachedCycle->timeOfDay += ($dt * $this->cachedCycle->speed) / $this->cachedCycle->dayDuration;
            // Count full days for lunar cycle
            if (floor($this->cachedCycle->timeOfDay) > $prevDay) {
                $this->cachedCycle->dayCount += 1.0;
            }
            $this->cachedCycle->timeOfDay -= floor($this->cachedCycle->timeOfDay);
        }
    }

    /**
     * All visual updates in render() to stay in sync with Renderer3DSystem.
     */
    public function render(World $world): void
    {
        $cycle = $this->cachedCycle;
        if ($cycle === null) return;

        $t = $cycle->timeOfDay;

        // --- Sun direction ---
        $elevRad = deg2rad($cycle->getSunElevation());
        $azimRad = $t * 2.0 * M_PI;
        $sunDirX = -cos($elevRad) * sin($azimRad);
        $sunDirY = -sin($elevRad);
        $sunDirZ = -cos($elevRad) * cos($azimRad);

        // Pre-compute moon brightness (needed for moonlight directional light)
        $moonBright = max(0.0, min(1.0, -$cycle->getSunElevation() / 20.0));
        $moonPhase = $cycle->getMoonPhase();
        $phaseBrightness = 0.3 + 0.7 * (1.0 - abs(cos($moonPhase * M_PI)));
        $moonBright *= $phaseBrightness;

        // Update DirectionalLight components
        $sunColor = self::interpolateKeys(self::SUN_KEYS, $t);

        // At night, the moon acts as a weak directional light (reflected sunlight).
        $sunIntensityFinal = $sunColor['intensity'] * (1.0 - $cycle->cloudDarkening);

        // Smooth blend between sunlight and moonlight.
        // moonBlend: 0 = full sun, 1 = full moon.
        // Wide transition range (0.0 – 0.8 sun intensity) with smoothstep for gradual crossfade.
        $raw = 1.0 - min(1.0, max(0.0, $sunIntensityFinal / 0.8));
        $moonBlend = $raw * $raw * (3.0 - 2.0 * $raw); // smoothstep
        $moonBlend *= min(1.0, $moonBright / 0.1); // only blend in if moon is bright

        // Moonlight parameters
        $moonIntensity = 0.15 + $moonBright * 0.5;

        // Blended primary light: lerp direction, color, intensity between sun and moon
        $blendDirX = $sunDirX * (1.0 - $moonBlend) + (-$sunDirX) * $moonBlend;
        $blendDirY = $sunDirY * (1.0 - $moonBlend) + (-$sunDirY) * $moonBlend;
        $blendDirZ = $sunDirZ * (1.0 - $moonBlend) + (-$sunDirZ) * $moonBlend;
        $blendR = $sunColor['r'] * (1.0 - $moonBlend) + 0.55 * $moonBlend;
        $blendG = $sunColor['g'] * (1.0 - $moonBlend) + 0.60 * $moonBlend;
        $blendB = $sunColor['b'] * (1.0 - $moonBlend) + 0.80 * $moonBlend;
        $blendIntensity = $sunIntensityFinal * (1.0 - $moonBlend) + $moonIntensity * $moonBlend;

        $isFirst = true;
        foreach ($world->query(DirectionalLight::class, Transform3D::class) as $entity) {
            $light = $entity->get(DirectionalLight::class);

            if ($isFirst) {
                $light->direction = new Vec3($blendDirX, $blendDirY, $blendDirZ);
                $light->color = new Color($blendR, $blendG, $blendB);
                $light->intensity = $blendIntensity;
                $isFirst = false;
            } else {
                // Fill light: scales with sun, very dim at night
                $fillScale = max(0.02, $sunIntensityFinal / 1.5);
                $light->intensity = 0.3 * $fillScale;
            }
        }

        // --- Move sun disc + glow ---
        $sunRadius = 80.0;
        $sunWorldX = -$sunDirX * $sunRadius;
        $sunWorldY = -$sunDirY * $sunRadius;
        $sunWorldZ = -$sunDirZ * $sunRadius;
        $sunVisible = $cycle->getSunElevation() > -10.0;

        // --- Moon direction (opposite to sun) ---
        $moonRadius = 75.0;
        $moonX = $sunDirX * $moonRadius;
        $moonY = $sunDirY * $moonRadius;
        $moonZ = $sunDirZ * $moonRadius;
        $moonVisible = $cycle->getSunElevation() < 5.0; // Moon visible when sun is low/below horizon

        // moonBright already computed above (with phase)

        // Moon disc uses proc_mode 9 (procedural phase shader).
        // Phase value is encoded in roughness (read by renderer as u_moon_phase).
        MaterialRegistry::register('moon_disc', new Material(
            albedo: new Color(0.85, 0.87, 0.92),
            roughness: (float) $moonPhase, // shader reads this as u_moon_phase
        ));
        MaterialRegistry::register('moon_glow', new Material(
            albedo: new Color(0, 0, 0),
            emission: new Color(0.2 * $moonBright, 0.22 * $moonBright, 0.35 * $moonBright),
            alpha: 0.08,
        ));

        $sunPos = $sunVisible ? new Vec3($sunWorldX, $sunWorldY, $sunWorldZ) : new Vec3(0.0, -100.0, 0.0);
        $moonPos = ($moonVisible && $moonY > 0) ? new Vec3($moonX, $moonY, $moonZ) : new Vec3(0.0, -100.0, 0.0);

        // Moon phase is rendered procedurally — no shadow sphere positioning needed

        foreach ($world->query(MeshRenderer::class, Transform3D::class) as $entity) {
            $mesh = $entity->get(MeshRenderer::class);
            $mat = $mesh->materialId;

            // Sun layers (all follow same position)
            if ($mat === 'sun_disc' || $mat === 'sun_glow') {
                $entity->get(Transform3D::class)->position = $sunPos;
            }

            // Moon layers (disc uses proc_mode 9 for phase rendering)
            if ($mat === 'moon_disc' || $mat === 'moon_glow') {
                $entity->get(Transform3D::class)->position = $moonPos;
            }
        }

        // --- Ambient light ---
        $ambient = self::interpolateKeys(self::AMBIENT_KEYS, $t);
        // Cloud darkening
        $ambientR = $ambient['r'] * (1.0 - $cycle->cloudDarkening * 0.5);
        $ambientG = $ambient['g'] * (1.0 - $cycle->cloudDarkening * 0.5);
        $ambientB = $ambient['b'] * (1.0 - $cycle->cloudDarkening * 0.5);
        $ambientIntensity = $ambient['intensity'] * (1.0 - $cycle->cloudDarkening * 0.3);

        // Moonlight: reflected sunlight creates faint blue-white ambient at night.
        // Intensity scales with moon brightness (phase + elevation).
        // Full moon at midnight ≈ 0.08 intensity, new moon ≈ 0.01.
        if ($moonBright > 0.01) {
            $moonAmbient = 0.05 + $moonBright * 0.12;
            $ambientR = max($ambientR, 0.12 * $moonBright + 0.03);
            $ambientG = max($ambientG, 0.14 * $moonBright + 0.04);
            $ambientB = max($ambientB, 0.22 * $moonBright + 0.06);
            $ambientIntensity = max($ambientIntensity, $moonAmbient);
        }

        // Lightning flash: brief white burst
        if ($cycle->lightningFlash > 0.01) {
            $flash = $cycle->lightningFlash;
            $ambientR = min(1.0, $ambientR + $flash * 0.8);
            $ambientG = min(1.0, $ambientG + $flash * 0.85);
            $ambientB = min(1.0, $ambientB + $flash * 0.9);
            $ambientIntensity = min(1.0, $ambientIntensity + $flash * 0.7);
        }

        $this->commandList->add(new SetAmbientLight(
            color: new Color($ambientR, $ambientG, $ambientB),
            intensity: $ambientIntensity,
        ));

        // --- Fog (matches horizon color) ---
        $horizon = self::interpolateKeysRGB(self::SKY_HORIZON_KEYS, $t);
        $fogNear = 60.0 + (1.0 - $cycle->getSunHeight()) * 20.0; // Fog creeps closer at night
        $fogFar = 280.0 - (1.0 - $cycle->getSunHeight()) * 80.0;  // Fog closer at night
        $this->commandList->add(new SetFog(
            color: new Color($horizon['r'], $horizon['g'], $horizon['b']),
            near: $fogNear,
            far: $fogFar,
        ));

        // --- Sky colors for water reflection ---
        $this->commandList->add(new SetSkyColors(
            skyColor: new Color($horizon['r'] * 0.5 + 0.15, $horizon['g'] * 0.5 + 0.2, $horizon['b'] * 0.5 + 0.3),
            horizonColor: new Color($horizon['r'], $horizon['g'], $horizon['b']),
        ));

        // --- Sky dome materials (update emission colors) ---
        $zenith = self::interpolateKeysRGB(self::SKY_ZENITH_KEYS, $t);
        MaterialRegistry::register('sky_zenith', new Material(
            albedo: new Color(0, 0, 0),
            emission: new Color($zenith['r'], $zenith['g'], $zenith['b']),
        ));
        // sky_mid: blend 60% zenith + 40% horizon, slightly brighter (original #4A90D9)
        MaterialRegistry::register('sky_mid', new Material(
            albedo: new Color(0, 0, 0),
            emission: new Color(
                $zenith['r'] * 0.6 + $horizon['r'] * 0.4 + 0.05,
                $zenith['g'] * 0.6 + $horizon['g'] * 0.4 + 0.05,
                $zenith['b'] * 0.6 + $horizon['b'] * 0.4 + 0.05,
            ),
        ));
        MaterialRegistry::register('sky_horizon', new Material(
            albedo: new Color(0, 0, 0),
            emission: new Color($horizon['r'], $horizon['g'], $horizon['b']),
        ));

        // Stratosphere layer — Rayleigh scattering simulation
        // Daytime: scatters blue light → pale blue-white (sky looks brighter at mid-altitudes)
        // Sunset: more atmosphere to penetrate → filters blue out → orange/pink
        // Night: dark, thin glow from starlight
        $stratoSunH = $cycle->getSunHeight();
        $stratoR = $zenith['r'] * 0.6 + $horizon['r'] * 0.4 + $stratoSunH * 0.15;
        $stratoG = $zenith['g'] * 0.5 + $horizon['g'] * 0.5 + $stratoSunH * 0.10;
        $stratoB = $zenith['b'] * 0.4 + $horizon['b'] * 0.6 + $stratoSunH * 0.05;
        // At sunset/sunrise: shift toward warm (filter blue, keep red)
        $horizonFactor = max(0.0, 1.0 - abs($cycle->getSunElevation()) / 25.0);
        $stratoR += $horizonFactor * 0.3;
        $stratoG += $horizonFactor * 0.1;
        $stratoB *= (1.0 - $horizonFactor * 0.4); // blue is filtered
        MaterialRegistry::register('sky_strato', new Material(
            albedo: new Color(0, 0, 0),
            emission: new Color(
                min(1.0, $stratoR),
                min(1.0, $stratoG),
                min(1.0, $stratoB),
            ),
            alpha: 0.4 + $stratoSunH * 0.2, // more opaque during day, thinner at night
        ));

        // Sun-warm sky side: blends between sky-mid color (daytime) and warm glow (sunrise/sunset)
        $sunWarmFactor = max(0.0, 1.0 - abs($cycle->getSunElevation()) / 30.0);
        $midR = $zenith['r'] * 0.6 + $horizon['r'] * 0.4 + 0.05;
        $midG = $zenith['g'] * 0.6 + $horizon['g'] * 0.4 + 0.05;
        $midB = $zenith['b'] * 0.6 + $horizon['b'] * 0.4 + 0.05;
        MaterialRegistry::register('sky_sun_warm', new Material(
            albedo: new Color(0, 0, 0),
            emission: new Color(
                $midR * (1.0 - $sunWarmFactor) + $horizon['r'] * $sunWarmFactor,
                $midG * (1.0 - $sunWarmFactor) + $horizon['g'] * $sunWarmFactor * 0.7,
                $midB * (1.0 - $sunWarmFactor) + $horizon['b'] * $sunWarmFactor * 0.4,
            ),
        ));

        // Sun materials (3 layers scale with brightness)
        $discBright = $sunVisible ? max(0.0, $sunColor['intensity']) : 0.0;
        MaterialRegistry::register('sun_disc', new Material(
            albedo: new Color(0, 0, 0),
            emission: new Color(
                min(1.0, $sunColor['r'] * $discBright + 0.3 * $discBright),
                min(1.0, $sunColor['g'] * $discBright + 0.2 * $discBright),
                min(1.0, $sunColor['b'] * $discBright + 0.4 * $discBright),
            ),
        ));
        MaterialRegistry::register('sun_glow', new Material(
            albedo: new Color(0, 0, 0),
            emission: new Color(
                min(1.0, $sunColor['r'] * $discBright * 0.9),
                min(1.0, $sunColor['g'] * $discBright * 0.8),
                min(1.0, $sunColor['b'] * $discBright * 0.5 + 0.15 * $discBright),
            ),
            alpha: 0.06,
        ));

        // Moon materials — must be significantly brighter than night sky for contrast
        MaterialRegistry::register('moon_disc', new Material(
            albedo: new Color(0, 0, 0),
            emission: new Color(
                min(1.0, 1.0 * $moonBright),
                min(1.0, 1.0 * $moonBright),
                min(1.0, 1.0 * $moonBright),
            ),
        ));
        MaterialRegistry::register('moon_shadow', new Material(
            albedo: new Color(0, 0, 0),
            emission: new Color(0.02, 0.02, 0.04), // near-black, independent of brightness
        ));
        MaterialRegistry::register('moon_glow', new Material(
            albedo: new Color(0, 0, 0),
            emission: new Color(
                min(1.0, 0.5 * $moonBright),
                min(1.0, 0.55 * $moonBright),
                min(1.0, 0.7 * $moonBright),
            ),
            alpha: 0.12,
        ));
    }

    /**
     * Interpolate between keyframes (with intensity).
     * @param list<array{time: float, r: float, g: float, b: float, intensity: float}> $keys
     * @return array{r: float, g: float, b: float, intensity: float}
     */
    private static function interpolateKeys(array $keys, float $t): array
    {
        $t = max(0.0, min(1.0, $t));
        for ($i = 0; $i < count($keys) - 1; $i++) {
            if ($t >= $keys[$i]['time'] && $t <= $keys[$i + 1]['time']) {
                $range = $keys[$i + 1]['time'] - $keys[$i]['time'];
                $f = $range > 0 ? ($t - $keys[$i]['time']) / $range : 0.0;
                // Smoothstep
                $f = $f * $f * (3.0 - 2.0 * $f);
                return [
                    'r' => $keys[$i]['r'] + ($keys[$i + 1]['r'] - $keys[$i]['r']) * $f,
                    'g' => $keys[$i]['g'] + ($keys[$i + 1]['g'] - $keys[$i]['g']) * $f,
                    'b' => $keys[$i]['b'] + ($keys[$i + 1]['b'] - $keys[$i]['b']) * $f,
                    'intensity' => $keys[$i]['intensity'] + ($keys[$i + 1]['intensity'] - $keys[$i]['intensity']) * $f,
                ];
            }
        }
        $last = $keys[count($keys) - 1];
        return ['r' => $last['r'], 'g' => $last['g'], 'b' => $last['b'], 'intensity' => $last['intensity']];
    }

    /**
     * Interpolate RGB-only keyframes.
     * @param list<array{time: float, r: float, g: float, b: float}> $keys
     * @return array{r: float, g: float, b: float}
     */
    private static function interpolateKeysRGB(array $keys, float $t): array
    {
        $t = max(0.0, min(1.0, $t));
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
        $last = $keys[count($keys) - 1];
        return ['r' => $last['r'], 'g' => $last['g'], 'b' => $last['b']];
    }
}
