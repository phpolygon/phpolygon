<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\Component\Swarm;
use PHPolygon\System\SwarmSystem;
use PHPUnit\Framework\TestCase;

/** Shader plan 7.2: GPU flocks and schools, CPU steering as the reference. */
final class SwarmSystemTest extends TestCase
{
    public function testAgentsCircleTheirLoopAndStayFinite(): void
    {
        foreach ([Swarm::KIND_BIRDS, Swarm::KIND_FISH] as $kind) {
            $swarm = new Swarm(count: 32, radius: 20.0, height: 4.0, speed: 6.0, kind: $kind, seed: 7);
            $s = SwarmSystem::seedAgents($swarm, 100.0, 10.0, -50.0);
            $t = 0.0;
            $m = [];
            for ($f = 0; $f < 600; $f++) {
                $t += 1 / 60;
                $m = SwarmSystem::step($s, $swarm, $t, 1 / 60, 100.0, 10.0, -50.0);
            }
            self::assertCount(32 * 16, $m);
            for ($i = 0; $i < 32; $i++) {
                $x = $m[$i * 16 + 12]; $y = $m[$i * 16 + 13]; $z = $m[$i * 16 + 14];
                self::assertTrue(is_finite($x) && is_finite($y) && is_finite($z));
                $d = sqrt(($x - 100.0) ** 2 + ($z + 50.0) ** 2);
                self::assertLessThan(40.0, $d, "{$kind}: agent {$i} left the swarm");
                self::assertLessThan(15.0, abs($y - 10.0), "{$kind}: agent {$i} left the height band");
            }
        }
    }

    public function testKernelMatchesTheCpuSteering(): void
    {
        if (!extension_loaded('vio') || !function_exists('vio_compute_pipeline')) {
            self::markTestSkipped('vio compute unavailable');
        }
        $ctx = @vio_create('auto', ['width' => 16, 'height' => 16, 'headless' => true]);
        if (!$ctx || !vio_supports_feature($ctx, VIO_FEATURE_COMPUTE)) {
            self::markTestSkipped('no compute');
        }
        $swarm = new Swarm(count: 64, kind: Swarm::KIND_BIRDS, seed: 3);
        $cpu = SwarmSystem::seedAgents($swarm, 0.0, 5.0, 0.0);
        $state = vio_storage_buffer($ctx, ['data' => pack('f*', ...$cpu), 'stride' => 4]);
        $out = vio_storage_buffer($ctx, ['size' => 64 * 16 * 4, 'stride' => 4]);
        $k = vio_compute_pipeline($ctx, ['source' => SwarmSystem::KERNEL]);
        self::assertNotFalse($k);
        $t = 0.0;
        $mCpu = [];
        for ($f = 0; $f < 30; $f++) {
            $t += 1 / 60;
            vio_compute_bind_buffer($ctx, $k, $state, 0, VIO_COMPUTE_READ | VIO_COMPUTE_WRITE);
            vio_compute_bind_buffer($ctx, $k, $out, 1, VIO_COMPUTE_WRITE);
            vio_compute_set_uniforms($ctx, $k, pack('f10', $t, 1 / 60, 0.0, 5.0, 0.0, $swarm->radius, $swarm->height, $swarm->speed, 1.0, 0.0) . pack('l', 64) . pack('f', 0.0));
            vio_compute_dispatch($ctx, $k, 1, 1, 1);
            $mCpu = SwarmSystem::step($cpu, $swarm, $t, 1 / 60, 0.0, 5.0, 0.0);
        }
        $gpu = array_values(unpack('f*', (string) vio_storage_buffer_read($ctx, $out)));
        $max = 0.0;
        for ($i = 0; $i < 64; $i++) {
            foreach ([12, 13, 14] as $c) {
                $max = max($max, abs($gpu[$i * 16 + $c] - $mCpu[$i * 16 + $c]));
            }
        }
        vio_destroy($ctx);
        self::assertLessThan(0.05, $max, "GPU swarm diverged from the CPU steering by {$max} m");
    }
}
