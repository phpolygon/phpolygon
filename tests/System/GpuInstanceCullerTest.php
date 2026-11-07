<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\System\GpuInstanceCuller;
use PHPUnit\Framework\TestCase;

/** Shader plan 9.1: the cull kernel keeps exactly the instances inside the frustum. */
final class GpuInstanceCullerTest extends TestCase
{
    public function testKernelCompactsTheVisibleInstances(): void
    {
        if (!extension_loaded('vio')) {
            self::markTestSkipped('vio missing');
        }
        $ctx = @vio_create('auto', ['width' => 16, 'height' => 16, 'headless' => true]);
        if (!$ctx || !GpuInstanceCuller::isAvailable($ctx)) {
            self::markTestSkipped('no compute / indirect draws');
        }
        // 10 instances along x = 0..9; the "frustum" keeps 2.5 <= x <= 6.5 (and all y/z).
        $floats = [];
        for ($i = 0; $i < 10; $i++) {
            array_push($floats, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, (float) $i, 0.0, 0.0, 1.0);
        }
        $planes = [
            [1.0, 0.0, 0.0, -3.0],   // x >= 3 (radius 0.5 lets x = 2.5 .. in)
            [-1.0, 0.0, 0.0, 6.0],   // x <= 6
            [0.0, 1.0, 0.0, 100.0], [0.0, -1.0, 0.0, 100.0],
            [0.0, 0.0, 1.0, 100.0], [0.0, 0.0, -1.0, 100.0],
        ];
        $cmd = GpuInstanceCuller::draw($ctx, 'test', 'm', 'mat', pack('f*', ...$floats), 10, 0.5, 36, $planes);
        self::assertNotNull($cmd);
        vio_compute_wait($ctx);
        $args = array_values(unpack('V*', (string) vio_storage_buffer_read($ctx, $cmd->indirectArgs)));
        self::assertSame(36, $args[0]);
        self::assertSame(4, $args[1], 'instances 3, 4, 5, 6 are visible');
        $out = array_values(unpack('f*', (string) vio_storage_buffer_read($ctx, $cmd->storageBuffer)));
        $xs = [];
        for ($i = 0; $i < 4; $i++) {
            $xs[] = (int) $out[$i * 16 + 12];
        }
        sort($xs);
        self::assertSame([3, 4, 5, 6], $xs);
        GpuInstanceCuller::forget('test');
        vio_destroy($ctx);
    }
}
