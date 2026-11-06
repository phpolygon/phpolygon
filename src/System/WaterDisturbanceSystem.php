<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Transform3D;
use PHPolygon\Component\WaterDisturber;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\Effects\EffectRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\Water\WaterShading;

/**
 * Follows every {@see WaterDisturber} and hands the water shader what it left on
 * the surface: the trail of each wake (points every `spacing` metres, the newest
 * at the hull) and the rings of swimmers and splashes. No render target: the
 * shader draws both analytically from at most {@see WaterShading::WAKE_POINTS}
 * trail points and {@see WaterShading::RIPPLES} rings (`waterWake()`,
 * `waterRipples()`), newest first when there are more.
 *
 * Ages run on the system's own clock. With an effect name, reduced motion is
 * honoured through {@see EffectRegistry}: `Reduced` slows the clock, `Frozen` stops
 * it, `Off` clears the surface. A jump further than {@see TELEPORT} metres in one
 * step starts a new trail (a hull placed somewhere else is not a sprint there).
 *
 * Register after the systems that move the disturbers and BEFORE
 * {@see Renderer3DSystem}: the uniforms are queued in render().
 */
class WaterDisturbanceSystem extends AbstractSystem
{
    /** Metres in one step that count as a jump, not a movement. */
    public const float TELEPORT = 30.0;

    private float $clock = 0.0;

    /**
     * Per wake entity: last position, smoothed speed, the trail (x, z, birth, strength;
     * newest first) and whether its first point is the live head at the hull.
     *
     * @var array<int, array{last: ?array{float, float}, speed: float, points: list<array{float, float, float, float}>, head?: bool}>
     */
    private array $wakes = [];

    /** @var array<int, array{last: ?array{float, float}, ringAt: ?array{float, float}, ringTime: float}> per ripple entity */
    private array $sources = [];

    /** @var list<array{float, float, float, float}> x, z, birth, strength (newest last) */
    private array $rings = [];

    private bool $uploadedEmpty = false;

    public function __construct(
        private readonly RenderCommandList $commandList,
        private readonly float $wakeLife = 20.0,
        private readonly float $rippleLife = 3.5,
        private readonly ?string $wakeEffect = null,
        private readonly ?string $rippleEffect = null,
    ) {}

    public function update(World $world, float $dt): void
    {
        $wakeOff = EffectRegistry::motionSwitchesOff($this->effect($this->wakeEffect));
        $rippleOff = EffectRegistry::motionSwitchesOff($this->effect($this->rippleEffect));
        $this->clock += $dt * $this->rate();

        $seenWakes = [];
        $seenSources = [];
        /** @var array<int, WaterDisturber> $pool */
        $pool = $world->componentPool(WaterDisturber::class);
        foreach ($pool as $entityId => $disturber) {
            $transform = $world->tryGetComponent($entityId, Transform3D::class);
            if (!$transform instanceof Transform3D) {
                continue;
            }
            $m = $transform->getWorldMatrix()->toArray();
            $x = $m[12];
            $z = $m[14];
            if ($disturber->kind === WaterDisturber::WAKE) {
                $seenWakes[$entityId] = true;
                $this->followWake($entityId, $disturber, $x, $z, $dt, $wakeOff);
            } else {
                $seenSources[$entityId] = true;
                $this->followSource($entityId, $disturber, $x, $z, $rippleOff);
            }
        }
        $this->wakes = array_intersect_key($this->wakes, $seenWakes);
        $this->sources = array_intersect_key($this->sources, $seenSources);
        foreach ($this->wakes as $id => $wake) {
            $this->wakes[$id]['points'] = array_values(array_filter(
                $wake['points'],
                fn (array $p): bool => $this->clock - $p[2] < $this->wakeLife,
            ));
        }
        $this->rings = $rippleOff ? [] : array_values(array_filter(
            $this->rings,
            fn (array $r): bool => $this->clock - $r[2] < $this->rippleLife,
        ));
    }

    public function render(World $world): void
    {
        $points = $this->wakePoints();
        $rings = array_slice(array_reverse($this->rings), 0, WaterShading::RIPPLES);
        if ($points === [] && $rings === []) {
            if ($this->uploadedEmpty) {
                return;
            }
            $this->uploadedEmpty = true;
        } else {
            $this->uploadedEmpty = false;
        }
        $wake = self::pack($points, WaterShading::WAKE_POINTS);
        for ($k = 0; $k < WaterShading::WAKE_POINTS / 4; $k++) {
            $this->commandList->add(new SetShaderParameter(WaterShading::wakeUniform($k), array_slice($wake, $k * 16, 16)));
        }
        $ringData = self::pack(array_map(
            fn (array $r): array => [$r[0], $r[1], $this->clock - $r[2], $r[3]],
            $rings,
        ), WaterShading::RIPPLES);
        for ($k = 0; $k < WaterShading::RIPPLES / 4; $k++) {
            $this->commandList->add(new SetShaderParameter(WaterShading::rippleUniform($k), array_slice($ringData, $k * 16, 16)));
        }
    }

    /**
     * The trail points as the shader reads them, newest first per trail: x, z, age,
     * strength, the first point of each trail with a negative strength.
     *
     * @return list<array{float, float, float, float}>
     */
    public function wakePoints(): array
    {
        $out = [];
        foreach ($this->wakes as $wake) {
            $trail = $wake['points'];
            if (count($trail) < 2) {
                continue;
            }
            foreach ($trail as $i => [$x, $z, $birth, $strength]) {
                if (count($out) >= WaterShading::WAKE_POINTS) {
                    break 2;
                }
                // At least the first value away from 0: a strength of 0 marks an unused slot.
                $s = max($strength, 1.0e-3);
                $out[] = [$x, $z, $this->clock - $birth, $i === 0 ? -$s : $s];
            }
        }

        return $out;
    }

    /** @return list<array{float, float, float, float}> x, z, age, strength (newest first) */
    public function ripples(): array
    {
        return array_map(fn (array $r): array => [$r[0], $r[1], $this->clock - $r[2], $r[3]], array_reverse($this->rings));
    }

    private function followWake(int $id, WaterDisturber $d, float $x, float $z, float $dt, bool $off): void
    {
        $wake = $this->wakes[$id] ?? ['last' => null, 'speed' => 0.0, 'points' => []];
        if ($wake['last'] !== null) {
            $step = hypot($x - $wake['last'][0], $z - $wake['last'][1]);
            if ($step > self::TELEPORT) {
                $wake['points'] = [];
                $wake['speed'] = 0.0;
            } elseif ($dt > 0.0) {
                // Smoothed over ~0.25 s: a frame-time spike is not a burst of speed.
                $k = min(1.0, $dt / 0.25);
                $wake['speed'] += ($step / $dt - $wake['speed']) * $k;
            }
        }
        $wake['last'] = [$x, $z];
        $strength = max(0.0, min(1.0, $wake['speed'] / max(0.1, $d->fullSpeed))) * $d->strength;

        if ($off || !$d->active) {
            // The head leaves with the hull; what is on the water stays and fades.
            if ($wake['points'] !== [] && ($wake['head'] ?? false)) {
                array_shift($wake['points']);
            }
            $wake['head'] = false;
            $this->wakes[$id] = $wake;

            return;
        }

        $head = [$x, $z, $this->clock, $strength];
        if (($wake['head'] ?? false) && $wake['points'] !== []) {
            $wake['points'][0] = $head;
            $recorded = $wake['points'][1] ?? null;
            if ($recorded === null || hypot($x - $recorded[0], $z - $recorded[1]) >= $d->spacing) {
                array_unshift($wake['points'], $head);
            }
        } else {
            array_unshift($wake['points'], $head, $head);
        }
        $wake['head'] = true;
        $wake['points'] = array_slice($wake['points'], 0, WaterShading::WAKE_POINTS);
        $this->wakes[$id] = $wake;
    }

    private function followSource(int $id, WaterDisturber $d, float $x, float $z, bool $off): void
    {
        $source = $this->sources[$id] ?? ['last' => null, 'ringAt' => null, 'ringTime' => -INF];
        if ($source['last'] !== null && hypot($x - $source['last'][0], $z - $source['last'][1]) > self::TELEPORT) {
            $source['ringAt'] = null;
        }
        $source['last'] = [$x, $z];
        if (!$off && $d->active) {
            $moved = $source['ringAt'] === null || hypot($x - $source['ringAt'][0], $z - $source['ringAt'][1]) >= $d->spacing;
            $waited = $d->interval > 0.0 && $this->clock - $source['ringTime'] >= $d->interval;
            if ($moved || $waited) {
                $this->rings[] = [$x, $z, $this->clock, $d->strength];
                $source['ringAt'] = [$x, $z];
                $source['ringTime'] = $this->clock;
                $this->rings = array_slice($this->rings, -WaterShading::RIPPLES);
            }
        }
        $this->sources[$id] = $source;
    }

    /** The clock rate: the slower of the two effects under reduced motion, 0 when one is frozen. */
    private function rate(): float
    {
        $rate = 1.0;
        foreach ([$this->wakeEffect, $this->rippleEffect] as $name) {
            $effect = $this->effect($name);
            if (EffectRegistry::timeFrozen($effect)) {
                return 0.0;
            }
            $rate = min($rate, EffectRegistry::motionScale($effect));
        }

        return $rate;
    }

    private function effect(?string $name): ?\PHPolygon\Rendering\Effects\EffectDefinition
    {
        return $name === null ? null : EffectRegistry::get($name);
    }

    /**
     * Column-major mat4 data, one point per column, unused columns 0.
     *
     * @param list<array{float, float, float, float}> $points
     * @return list<float>
     */
    private static function pack(array $points, int $capacity): array
    {
        $flat = array_fill(0, $capacity * 4, 0.0);
        foreach (array_slice($points, 0, $capacity) as $i => $p) {
            for ($c = 0; $c < 4; $c++) {
                $flat[$i * 4 + $c] = (float) $p[$c];
            }
        }

        return array_values($flat);
    }
}
