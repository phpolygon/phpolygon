<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Camera3DComponent;
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
use PHPolygon\Rendering\Command\SetSkybox;
use PHPolygon\Rendering\Command\SetSkyColors;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProceduralCubemap;
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
    // ── Sun light color/intensity ramp ──────────────────────────────
    // Refined with proper astronomical/nautical/civil twilight phases.
    // Sunrise sequence: astronomical (0.17) → nautical (0.19) → civil (0.22) → golden (0.25) → day (0.32)
    // Sunset mirrors: golden (0.72) → civil (0.77) → nautical (0.82) → astronomical (0.85) → night (0.88)
    /** @var array<int, array{time: float, r: float, g: float, b: float, intensity: float}> */
    private const SUN_KEYS = [
        ['time' => 0.0,  'r' => 0.0,  'g' => 0.0,  'b' => 0.0,  'intensity' => 0.0],   // midnight
        ['time' => 0.15, 'r' => 0.0,  'g' => 0.0,  'b' => 0.0,  'intensity' => 0.0],   // deep night
        ['time' => 0.17, 'r' => 0.15, 'g' => 0.05, 'b' => 0.03, 'intensity' => 0.02],  // astronomical twilight
        ['time' => 0.19, 'r' => 0.35, 'g' => 0.12, 'b' => 0.08, 'intensity' => 0.06],  // nautical twilight
        ['time' => 0.22, 'r' => 0.65, 'g' => 0.28, 'b' => 0.12, 'intensity' => 0.15],  // civil twilight
        ['time' => 0.25, 'r' => 1.0,  'g' => 0.50, 'b' => 0.22, 'intensity' => 0.45],  // sunrise — golden
        ['time' => 0.28, 'r' => 1.0,  'g' => 0.65, 'b' => 0.35, 'intensity' => 0.70],  // post-sunrise
        ['time' => 0.32, 'r' => 1.0,  'g' => 0.85, 'b' => 0.70, 'intensity' => 1.0],   // early morning
        ['time' => 0.40, 'r' => 1.0,  'g' => 0.97, 'b' => 0.92, 'intensity' => 1.3],   // morning
        ['time' => 0.50, 'r' => 1.0,  'g' => 0.98, 'b' => 0.94, 'intensity' => 1.5],   // noon — white
        ['time' => 0.60, 'r' => 1.0,  'g' => 0.97, 'b' => 0.90, 'intensity' => 1.35],  // afternoon
        ['time' => 0.68, 'r' => 1.0,  'g' => 0.85, 'b' => 0.65, 'intensity' => 1.1],   // late afternoon
        ['time' => 0.72, 'r' => 1.0,  'g' => 0.60, 'b' => 0.35, 'intensity' => 0.90],  // golden hour
        ['time' => 0.75, 'r' => 1.0,  'g' => 0.42, 'b' => 0.18, 'intensity' => 0.65],  // sunset — deep gold
        ['time' => 0.77, 'r' => 0.90, 'g' => 0.30, 'b' => 0.12, 'intensity' => 0.35],  // civil dusk
        ['time' => 0.82, 'r' => 0.50, 'g' => 0.15, 'b' => 0.08, 'intensity' => 0.10],  // nautical dusk
        ['time' => 0.85, 'r' => 0.20, 'g' => 0.06, 'b' => 0.04, 'intensity' => 0.03],  // astronomical dusk
        ['time' => 0.88, 'r' => 0.0,  'g' => 0.0,  'b' => 0.0,  'intensity' => 0.0],   // night
        ['time' => 1.0,  'r' => 0.0,  'g' => 0.0,  'b' => 0.0,  'intensity' => 0.0],   // wrap
    ];

    // ── Ambient light ramp ───────────────────────────────────────────
    /** @var array<int, array{time: float, r: float, g: float, b: float, intensity: float}> */
    private const AMBIENT_KEYS = [
        ['time' => 0.0,  'r' => 0.03, 'g' => 0.06, 'b' => 0.14, 'intensity' => 0.04],  // midnight — deep blue
        ['time' => 0.15, 'r' => 0.03, 'g' => 0.06, 'b' => 0.14, 'intensity' => 0.04],  // late night
        ['time' => 0.17, 'r' => 0.06, 'g' => 0.07, 'b' => 0.18, 'intensity' => 0.05],  // astro. twilight
        ['time' => 0.19, 'r' => 0.15, 'g' => 0.12, 'b' => 0.25, 'intensity' => 0.07],  // nautical twi.
        ['time' => 0.22, 'r' => 0.35, 'g' => 0.22, 'b' => 0.32, 'intensity' => 0.10],  // civil twilight
        ['time' => 0.25, 'r' => 0.70, 'g' => 0.50, 'b' => 0.38, 'intensity' => 0.14],  // sunrise
        ['time' => 0.32, 'r' => 0.72, 'g' => 0.78, 'b' => 0.88, 'intensity' => 0.22],  // morning
        ['time' => 0.50, 'r' => 0.78, 'g' => 0.86, 'b' => 0.94, 'intensity' => 0.28],  // noon
        ['time' => 0.68, 'r' => 0.78, 'g' => 0.86, 'b' => 0.94, 'intensity' => 0.25],  // afternoon
        ['time' => 0.75, 'r' => 0.60, 'g' => 0.35, 'b' => 0.22, 'intensity' => 0.14],  // sunset
        ['time' => 0.77, 'r' => 0.30, 'g' => 0.15, 'b' => 0.25, 'intensity' => 0.09],  // civil dusk
        ['time' => 0.82, 'r' => 0.12, 'g' => 0.08, 'b' => 0.20, 'intensity' => 0.06],  // nautical dusk
        ['time' => 0.85, 'r' => 0.05, 'g' => 0.06, 'b' => 0.16, 'intensity' => 0.04],  // astro. dusk
        ['time' => 0.88, 'r' => 0.03, 'g' => 0.06, 'b' => 0.14, 'intensity' => 0.04],  // night
        ['time' => 1.0,  'r' => 0.03, 'g' => 0.06, 'b' => 0.14, 'intensity' => 0.04],  // wrap
    ];

    // ── Sky zenith emission ──────────────────────────────────────────
    private const SKY_ZENITH_KEYS = [
        ['time' => 0.0,  'r' => 0.01, 'g' => 0.01, 'b' => 0.04],  // near-black
        ['time' => 0.15, 'r' => 0.01, 'g' => 0.01, 'b' => 0.04],  // deep night
        ['time' => 0.17, 'r' => 0.03, 'g' => 0.03, 'b' => 0.10],  // astro. — first hint of blue
        ['time' => 0.19, 'r' => 0.08, 'g' => 0.08, 'b' => 0.22],  // nautical — deep blue
        ['time' => 0.22, 'r' => 0.18, 'g' => 0.15, 'b' => 0.40],  // civil — purple-blue
        ['time' => 0.28, 'r' => 0.12, 'g' => 0.35, 'b' => 0.65],  // transition to day blue
        ['time' => 0.35, 'r' => 0.12, 'g' => 0.37, 'b' => 0.67],  // #1E5FAA day blue
        ['time' => 0.50, 'r' => 0.12, 'g' => 0.37, 'b' => 0.67],  // noon
        ['time' => 0.68, 'r' => 0.12, 'g' => 0.37, 'b' => 0.67],  // afternoon
        ['time' => 0.75, 'r' => 0.15, 'g' => 0.20, 'b' => 0.50],  // sunset — fading blue
        ['time' => 0.77, 'r' => 0.18, 'g' => 0.12, 'b' => 0.35],  // civil dusk — purple
        ['time' => 0.82, 'r' => 0.08, 'g' => 0.06, 'b' => 0.20],  // nautical dusk — deep
        ['time' => 0.85, 'r' => 0.03, 'g' => 0.03, 'b' => 0.10],  // astro. dusk
        ['time' => 0.88, 'r' => 0.01, 'g' => 0.01, 'b' => 0.04],  // night
        ['time' => 1.0,  'r' => 0.01, 'g' => 0.01, 'b' => 0.04],
    ];

    // ── Sky horizon emission ─────────────────────────────────────────
    private const SKY_HORIZON_KEYS = [
        ['time' => 0.0,  'r' => 0.02, 'g' => 0.02, 'b' => 0.06],
        ['time' => 0.15, 'r' => 0.02, 'g' => 0.02, 'b' => 0.06],  // deep night
        ['time' => 0.17, 'r' => 0.05, 'g' => 0.04, 'b' => 0.10],  // astro. — barely visible
        ['time' => 0.19, 'r' => 0.20, 'g' => 0.12, 'b' => 0.18],  // nautical — dark orange hint
        ['time' => 0.22, 'r' => 0.55, 'g' => 0.28, 'b' => 0.20],  // civil — warm orange band
        ['time' => 0.25, 'r' => 0.90, 'g' => 0.55, 'b' => 0.30],  // sunrise — vivid orange
        ['time' => 0.28, 'r' => 0.85, 'g' => 0.65, 'b' => 0.45],  // post-sunrise — fading warm
        ['time' => 0.35, 'r' => 0.53, 'g' => 0.68, 'b' => 0.80],  // #87AECC day haze
        ['time' => 0.50, 'r' => 0.53, 'g' => 0.68, 'b' => 0.80],  // noon
        ['time' => 0.68, 'r' => 0.60, 'g' => 0.60, 'b' => 0.55],  // late afternoon — warming
        ['time' => 0.72, 'r' => 0.85, 'g' => 0.50, 'b' => 0.25],  // golden hour
        ['time' => 0.75, 'r' => 0.95, 'g' => 0.38, 'b' => 0.12],  // sunset — intense red-orange
        ['time' => 0.77, 'r' => 0.70, 'g' => 0.25, 'b' => 0.15],  // civil dusk — deep red
        ['time' => 0.82, 'r' => 0.30, 'g' => 0.12, 'b' => 0.18],  // nautical dusk — purple-red
        ['time' => 0.85, 'r' => 0.10, 'g' => 0.06, 'b' => 0.12],  // astro. dusk — fading
        ['time' => 0.88, 'r' => 0.02, 'g' => 0.02, 'b' => 0.06],  // night
        ['time' => 1.0,  'r' => 0.02, 'g' => 0.02, 'b' => 0.06],
    ];

    public function __construct(
        private readonly RenderCommandList $commandList,
    ) {}

    private ?DayNightCycle $cachedCycle = null;

    /**
     * Last time-of-day the skybox cubemap was regenerated. The cubemap is
     * re-baked whenever the sun has moved far enough for the atmospheric
     * scattering gradient to look different.
     */
    private float $lastSkyboxTime = -1.0;
    private float $lastCloudDarkening = 0.0;

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

        // Atmospheric path length correction (Mie/Rayleigh approximation):
        // Low sun = long atmospheric path = blue scattered away, red/orange remains.
        // High sun = short path = full spectrum = white.
        $elevation = max(0.0, $cycle->getSunElevation());
        if ($elevation > 0.0 && $elevation < 25.0 && $sunColor['intensity'] > 0.0) {
            // Normalized path factor: 1.0 at horizon, 0.0 at 25°+
            $pathFactor = 1.0 - min(1.0, $elevation / 25.0);
            $pathFactor *= $pathFactor; // quadratic falloff — most reddening near horizon
            // Shift toward warm: boost R, reduce B, slightly reduce G
            $sunColor['r'] = min(1.0, $sunColor['r'] + $pathFactor * 0.12);
            $sunColor['g'] = $sunColor['g'] * (1.0 - $pathFactor * 0.15);
            $sunColor['b'] = $sunColor['b'] * (1.0 - $pathFactor * 0.35);
        }

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

        // --- Sun disc position (single sphere, moved each frame) ---
        // Radius: closer than the old dome so the sphere is clearly visible
        // but still comfortably inside the render distance.
        $sunRadius = 300.0;
        $sunWorldX = -$sunDirX * $sunRadius;
        $sunWorldY = -$sunDirY * $sunRadius;
        $sunWorldZ = -$sunDirZ * $sunRadius;
        $sunVisible = $cycle->getSunElevation() > -10.0;

        // --- Moon direction (opposite to sun) ---
        $moonRadius = 280.0;
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

        // Get camera position for dome/celestial tracking
        $camPos = new Vec3(0.0, 3.0, 0.0);
        foreach ($world->query(Camera3DComponent::class, Transform3D::class) as $entity) {
            $camPos = $entity->get(Transform3D::class)->position;
            break;
        }

        foreach ($world->query(MeshRenderer::class, Transform3D::class) as $entity) {
            $mesh = $entity->get(MeshRenderer::class);
            $mat = $mesh->materialId;

            // Sun — single sphere orbiting the camera at sunRadius
            if ($mat === 'sun_disc') {
                $entity->get(Transform3D::class)->position = $sunVisible
                    ? new Vec3($camPos->x + $sunWorldX, $sunWorldY, $camPos->z + $sunWorldZ)
                    : new Vec3(0.0, -100.0, 0.0);
            }

            // Moon layers — orbit around camera
            if ($mat === 'moon_disc' || $mat === 'moon_glow') {
                $entity->get(Transform3D::class)->position = ($moonVisible && $moonY > 0)
                    ? new Vec3($camPos->x + $moonX, $moonY, $camPos->z + $moonZ)
                    : new Vec3(0.0, -100.0, 0.0);
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

        // --- Fog (matches horizon color, with morning mist) ---
        $horizon = self::interpolateKeysRGB(self::SKY_HORIZON_KEYS, $t);
        $sunH = $cycle->getSunHeight();

        // Base fog: closer at night, further at midday
        $fogNear = 60.0 + (1.0 - $sunH) * 20.0;
        $fogFar = 280.0 - (1.0 - $sunH) * 80.0;

        // Morning mist: dense fog around sunrise (t=0.20-0.35) that burns off
        // Peaks at civil twilight (t≈0.22), dissolves by mid-morning (t≈0.35)
        if ($t > 0.18 && $t < 0.38) {
            $mistStrength = 0.0;
            if ($t < 0.22) {
                $mistStrength = ($t - 0.18) / 0.04; // ramp in
            } elseif ($t < 0.28) {
                $mistStrength = 1.0; // peak
            } else {
                $mistStrength = 1.0 - ($t - 0.28) / 0.10; // burn off
            }
            // Humidity amplifies morning mist
            $humidityFactor = min(1.0, $cycle->cloudDarkening * 2.0); // cloudDarkening ≈ humidity proxy
            $mistStrength *= 0.6 + $humidityFactor * 0.4;
            $fogNear = $fogNear * (1.0 - $mistStrength * 0.7); // mist pulls fog very close
            $fogFar = $fogFar * (1.0 - $mistStrength * 0.5);
        }
        $this->commandList->add(new SetFog(
            color: new Color($horizon['r'], $horizon['g'], $horizon['b']),
            near: $fogNear,
            far: $fogFar,
        ));

        // --- Sky colors for water reflection ---
        $skyColor = new Color($horizon['r'] * 0.5 + 0.15, $horizon['g'] * 0.5 + 0.2, $horizon['b'] * 0.5 + 0.3);
        $horizonColor = new Color($horizon['r'], $horizon['g'], $horizon['b']);
        $this->commandList->add(new SetSkyColors(
            skyColor: $skyColor,
            horizonColor: $horizonColor,
        ));

        // --- Procedural skybox with atmospheric scattering ---
        // Regenerate whenever the sun has moved far enough (or cloud cover
        // changed) for the gradient to look visibly different. Thresholds
        // are tuned so a 60-second day triggers ~5 regens per daytime.
        $timeDelta = abs($t - $this->lastSkyboxTime);
        if ($timeDelta > 0.5) $timeDelta = 1.0 - $timeDelta; // handle wrap
        $cloudDelta = abs($cycle->cloudDarkening - $this->lastCloudDarkening);

        if ($this->lastSkyboxTime < 0.0 || $timeDelta > 0.008 || $cloudDelta > 0.05) {
            $zenith = self::interpolateKeysRGB(self::SKY_ZENITH_KEYS, $t);
            $zenithColor = new Color($zenith['r'], $zenith['g'], $zenith['b']);

            // Cloud cover desaturates and darkens the sky.
            $cloudMute = 1.0 - $cycle->cloudDarkening * 0.55;
            $zenithColor = new Color(
                $zenithColor->r * $cloudMute,
                $zenithColor->g * $cloudMute,
                $zenithColor->b * $cloudMute + $cycle->cloudDarkening * 0.08,
            );
            $horizonMuted = new Color(
                $horizon['r'] * $cloudMute + $cycle->cloudDarkening * 0.08,
                $horizon['g'] * $cloudMute + $cycle->cloudDarkening * 0.08,
                $horizon['b'] * $cloudMute + $cycle->cloudDarkening * 0.10,
            );

            // Sun size/glow grow near the horizon for a natural sunset look.
            $lowSun = max(0.0, 1.0 - $cycle->getSunHeight());
            $sunSize = 0.018 + $lowSun * 0.020;
            $sunGlowSize = 0.15 + $lowSun * 0.25;
            $sunGlowIntensity = 0.25 + $lowSun * 0.45;

            // Sun direction points from the sky dot toward the ground. For the
            // skybox the "direction toward the sun" is the negated light dir.
            $skySunDir = $sunVisible
                ? (new Vec3(-$sunDirX, -$sunDirY, -$sunDirZ))
                : null;

            $sky = new \PHPolygon\Rendering\ProceduralSky(
                zenithColor: $zenithColor,
                horizonColor: $horizonMuted,
                groundColor: new Color(
                    $horizon['r'] * 0.35,
                    $horizon['g'] * 0.30,
                    $horizon['b'] * 0.25,
                ),
                sunDirection: $skySunDir,
                sunColor: new Color(
                    min(1.0, $sunColor['r'] + 0.05),
                    min(1.0, $sunColor['g'] + 0.05),
                    min(1.0, $sunColor['b'] + 0.05),
                ),
                sunSize: $sunSize,
                sunGlowSize: $sunGlowSize,
                sunGlowIntensity: $sunGlowIntensity * max(0.1, $sunColor['intensity']),
                starDensity: $cycle->isDaytime() ? 0.0 : 0.0015 * $moonBright,
                starBrightness: 0.7,
            );
            CubemapRegistry::registerProcedural('sky', $sky->generate(64));

            $this->lastSkyboxTime = $t;
            $this->lastCloudDarkening = $cycle->cloudDarkening;
        }

        $this->commandList->add(new SetSkybox('sky'));

        // --- Sun sphere material ---
        // Single emissive sphere; the horizon glow and scattering halo are
        // baked into the skybox cubemap above, not stacked as overlay spheres.
        $discBright = $sunVisible ? max(0.0, $sunColor['intensity']) : 0.0;
        MaterialRegistry::register('sun_disc', new Material(
            albedo: new Color(0, 0, 0),
            emission: new Color(
                min(1.0, 0.95 * $discBright + 0.05),
                min(1.0, 0.90 * $discBright + 0.05),
                min(1.0, 0.70 * $discBright + 0.1),
            ),
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
