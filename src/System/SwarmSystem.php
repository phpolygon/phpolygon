<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Swarm;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\RenderCommandList;

/**
 * GPU flocks and schools (shader plan 7.2). Each {@see Swarm} keeps its agents in a
 * storage buffer (8 floats each: position, velocity, phase, spare); one compute
 * dispatch per frame steers them and writes their instance matrices into a second
 * buffer that is drawn as is - nothing crosses back to PHP. Without compute the
 * same steering runs here and the matrices are uploaded like any instanced draw.
 *
 * Steering: every agent has a spot on a loop of the swarm's radius that turns
 * with time (birds bank up and down, fish stay flat), steers towards it at the
 * swarm speed and is pushed by the curl field of the particles - wheeling flocks,
 * not formations. Fish dart now and then (a short speed burst per agent).
 */
class SwarmSystem extends AbstractSystem
{
    /** @var \WeakMap<Swarm, array{state: \VioBuffer, out: \VioBuffer, count: int}> */
    private \WeakMap $gpu;

    /** @var \WeakMap<Swarm, array<int, float>> CPU agents, 8 floats each. */
    private \WeakMap $cpu;

    private float $time = 0.0;
    private float $lastDt = 0.0;
    private ?\VioComputePipeline $kernel = null;
    private bool $kernelFailed = false;

    public const KERNEL = <<<'GLSL'
        #version 450
        layout(local_size_x = 64, local_size_y = 1, local_size_z = 1) in;
        layout(std430, binding = 0) buffer State { float s[]; };
        layout(std430, binding = 1) writeonly buffer OutM { float m[]; };
        layout(std140, binding = 2) uniform Params {
            float time; float dt; float cx; float cy;
            float cz; float radius; float height; float speed;
            float scale; float fish; int count; float pad0;
        };

        void main() {
            uint i = gl_GlobalInvocationID.x;
            if (i >= uint(count)) return;
            uint b = i * 8u;
            vec3 p = vec3(s[b], s[b + 1u], s[b + 2u]);
            vec3 v = vec3(s[b + 3u], s[b + 4u], s[b + 5u]);
            float ph = s[b + 6u];

            float ang = time * speed / max(radius, 1.0) + ph * 6.2831;
            float lane = 0.6 + 0.4 * fract(ph * 7.31);
            vec3 target = vec3(cx + cos(ang) * radius * lane,
                               cy + (fish > 0.5 ? (fract(ph * 3.7) - 0.5) * height
                                                : sin(ang * 2.0 + ph * 3.0) * height * 0.5),
                               cz + sin(ang) * radius * lane);
            vec3 to = target - p;
            float burst = fish > 0.5 ? 1.0 + 1.5 * step(0.93, fract(time * 0.37 + ph * 11.0)) : 1.0;
            vec3 desired = normalize(to + vec3(1e-4)) * speed * burst;
            v = mix(v, desired, clamp(dt * 1.6, 0.0, 1.0));
            float cs = 0.15;
            v -= vec3(cos(cs * p.z + 2.1), cos(cs * p.x + 0.7) * (fish > 0.5 ? 0.2 : 1.0), cos(cs * p.y + 1.3)) * speed * 0.35 * dt;
            p += v * dt;
            s[b] = p.x; s[b + 1u] = p.y; s[b + 2u] = p.z;
            s[b + 3u] = v.x; s[b + 4u] = v.y; s[b + 5u] = v.z;

            vec3 f = normalize(v + vec3(0.0, 0.0, 1e-4));
            vec3 r = normalize(cross(vec3(0.0, 1.0, 0.0), f) + vec3(1e-4, 0.0, 0.0));
            vec3 u = cross(f, r);
            uint o = i * 16u;
            m[o + 0u] = r.x * scale; m[o + 1u] = r.y * scale; m[o + 2u] = r.z * scale; m[o + 3u] = 0.0;
            m[o + 4u] = u.x * scale; m[o + 5u] = u.y * scale; m[o + 6u] = u.z * scale; m[o + 7u] = ph;
            m[o + 8u] = f.x * scale; m[o + 9u] = f.y * scale; m[o + 10u] = f.z * scale; m[o + 11u] = 0.0;
            m[o + 12u] = p.x; m[o + 13u] = p.y; m[o + 14u] = p.z; m[o + 15u] = 1.0;
        }
        GLSL;

    public function __construct(
        private readonly RenderCommandList $commandList,
        private readonly ?\VioContext $ctx = null,
        private readonly bool $useGpu = true,
    ) {
        $this->gpu = new \WeakMap();
        $this->cpu = new \WeakMap();
    }

    public function update(World $world, float $dt): void
    {
        if ($dt <= 0.0) {
            return;
        }
        $this->time += $dt;
        $this->lastDt = min($dt, 0.1);
    }

    public function render(World $world): void
    {
        foreach ($world->query(Swarm::class, Transform3D::class) as $entity) {
            $swarm = $entity->get(Swarm::class);
            $center = $entity->get(Transform3D::class)->getWorldPosition();
            if ($swarm->count <= 0) {
                continue;
            }
            $c = [$center->x, $center->y, $center->z];
            if ($this->renderGpu($swarm, $c)) {
                continue;
            }
            $this->renderCpu($swarm, $c);
        }
    }

    /**
     * Initial agents: spread over the loop, each with its phase (deterministic per seed).
     *
     * @return list<float>
     */
    public static function seedAgents(Swarm $swarm, float $cx, float $cy, float $cz): array
    {
        mt_srand($swarm->seed);
        $rows = [];
        for ($i = 0; $i < $swarm->count; $i++) {
            $ph = mt_rand() / mt_getrandmax();
            $a = $ph * 6.2831;
            $rows[] = $cx + cos($a) * $swarm->radius;
            $rows[] = $cy + (mt_rand() / mt_getrandmax() - 0.5) * $swarm->height;
            $rows[] = $cz + sin($a) * $swarm->radius;
            $rows[] = -sin($a) * $swarm->speed;
            $rows[] = 0.0;
            $rows[] = cos($a) * $swarm->speed;
            $rows[] = $ph;
            $rows[] = 0.0;
        }
        mt_srand();
        return $rows;
    }

    /**
     * One CPU step of the kernel's steering; writes the agents' matrices.
     *
     * @param array<int, float> $s agents, updated in place
     * @return list<float> 16 floats per agent
     */
    public static function step(array &$s, Swarm $swarm, float $time, float $dt, float $cx, float $cy, float $cz): array
    {
        $m = [];
        $fish = $swarm->kind === Swarm::KIND_FISH;
        $speed = $swarm->speed;
        $radius = max($swarm->radius, 1.0);
        $scale = $swarm->agentScale;
        $n = intdiv(count($s), 8);
        for ($i = 0; $i < $n; $i++) {
            $b = $i * 8;
            [$px, $py, $pz, $vx, $vy, $vz, $ph] = [$s[$b], $s[$b + 1], $s[$b + 2], $s[$b + 3], $s[$b + 4], $s[$b + 5], $s[$b + 6]];
            $ang = $time * $speed / $radius + $ph * 6.2831;
            $lane = 0.6 + 0.4 * fmod($ph * 7.31, 1.0);
            $tx = $cx + cos($ang) * $radius * $lane;
            $ty = $cy + ($fish ? (fmod($ph * 3.7, 1.0) - 0.5) * $swarm->height : sin($ang * 2.0 + $ph * 3.0) * $swarm->height * 0.5);
            $tz = $cz + sin($ang) * $radius * $lane;
            $dx = $tx - $px + 1e-4; $dy = $ty - $py + 1e-4; $dz = $tz - $pz + 1e-4;
            $len = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
            $burst = $fish && fmod($time * 0.37 + $ph * 11.0, 1.0) >= 0.93 ? 2.5 : 1.0;
            $k = min(max($dt * 1.6, 0.0), 1.0);
            $vx += ($dx / $len * $speed * $burst - $vx) * $k;
            $vy += ($dy / $len * $speed * $burst - $vy) * $k;
            $vz += ($dz / $len * $speed * $burst - $vz) * $k;
            $vx -= cos(0.15 * $pz + 2.1) * $speed * 0.35 * $dt;
            $vy -= cos(0.15 * $px + 0.7) * ($fish ? 0.2 : 1.0) * $speed * 0.35 * $dt;
            $vz -= cos(0.15 * $py + 1.3) * $speed * 0.35 * $dt;
            $px += $vx * $dt; $py += $vy * $dt; $pz += $vz * $dt;
            $s[$b] = $px; $s[$b + 1] = $py; $s[$b + 2] = $pz;
            $s[$b + 3] = $vx; $s[$b + 4] = $vy; $s[$b + 5] = $vz;

            $fl = sqrt($vx * $vx + $vy * $vy + ($vz + 1e-4) ** 2);
            [$fx, $fy, $fz] = [$vx / $fl, $vy / $fl, ($vz + 1e-4) / $fl];
            // r = normalize(cross(up, f)), up = (0, 1, 0)
            [$rx, $ry, $rz] = [$fz + 1e-4, 0.0, -$fx];
            $rl = sqrt($rx * $rx + $rz * $rz);
            $rx /= $rl; $rz /= $rl;
            // u = cross(f, r)
            $ux = $fy * $rz - $fz * $ry; $uy = $fz * $rx - $fx * $rz; $uz = $fx * $ry - $fy * $rx;
            array_push($m,
                $rx * $scale, $ry * $scale, $rz * $scale, 0.0,
                $ux * $scale, $uy * $scale, $uz * $scale, $ph,
                $fx * $scale, $fy * $scale, $fz * $scale, 0.0,
                $px, $py, $pz, 1.0,
            );
        }
        return $m;
    }

    /** @param array{0: float, 1: float, 2: float} $c */
    private function renderGpu(Swarm $swarm, array $c): bool
    {
        $ctx = $this->ctx;
        if (!$this->useGpu || $ctx === null || $this->kernelFailed || !function_exists('vio_compute_pipeline')
            || !defined('VIO_FEATURE_COMPUTE') || !vio_supports_feature($ctx, VIO_FEATURE_COMPUTE)) {
            return false;
        }
        if ($this->kernel === null) {
            $k = vio_compute_pipeline($ctx, ['source' => self::KERNEL]);
            if ($k === false) {
                $this->kernelFailed = true;
                return false;
            }
            $this->kernel = $k;
        }
        $g = $this->gpu[$swarm] ?? null;
        if ($g === null || $g['count'] !== $swarm->count) {
            $state = vio_storage_buffer($ctx, ['data' => pack('f*', ...self::seedAgents($swarm, ...$c)), 'stride' => 4]);
            $out = vio_storage_buffer($ctx, ['size' => $swarm->count * 16 * 4, 'stride' => 4]);
            if ($state === false || $out === false) {
                $this->kernelFailed = true;
                return false;
            }
            $g = ['state' => $state, 'out' => $out, 'count' => $swarm->count];
            $this->gpu[$swarm] = $g;
        }
        $params = pack('f10', $this->time, $this->lastDt, $c[0], $c[1], $c[2], $swarm->radius, $swarm->height, $swarm->speed,
                $swarm->agentScale, $swarm->kind === Swarm::KIND_FISH ? 1.0 : 0.0)
            . pack('l', $swarm->count) . pack('f', 0.0);
        GpuParticleBaker::reserveDescriptorBlock($ctx);
        vio_compute_bind_buffer($ctx, $this->kernel, $g['state'], 0, VIO_COMPUTE_READ | VIO_COMPUTE_WRITE);
        vio_compute_bind_buffer($ctx, $this->kernel, $g['out'], 1, VIO_COMPUTE_WRITE);
        vio_compute_set_uniforms($ctx, $this->kernel, $params);
        vio_compute_dispatch($ctx, $this->kernel, (int) ceil($swarm->count / 64), 1, 1, ['async' => true]);
        $this->commandList->add(DrawMeshInstanced::fromStorageBuffer(
            meshId: $swarm->meshId,
            materialId: $swarm->materialId,
            storageBuffer: $g['out'],
            instanceCount: $swarm->count,
        ));
        return true;
    }

    /** @param array{0: float, 1: float, 2: float} $c */
    private function renderCpu(Swarm $swarm, array $c): void
    {
        $s = $this->cpu[$swarm] ?? null;
        if ($s === null || count($s) !== $swarm->count * 8) {
            $s = self::seedAgents($swarm, ...$c);
        }
        $m = self::step($s, $swarm, $this->time, $this->lastDt, ...$c);
        $this->cpu[$swarm] = $s;
        $this->commandList->add(DrawMeshInstanced::flat(
            meshId: $swarm->meshId,
            materialId: $swarm->materialId,
            flatMatrices: $m,
            instanceCount: $swarm->count,
        ));
    }
}
