<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\System\GpuPartAnimator;
use PHPUnit\Framework\TestCase;

/** Shader plan 6.3: rigid-part figures animated on the GPU match the CPU math. */
final class GpuPartAnimatorTest extends TestCase
{
    public function testKernelMatchesTheCpuSwing(): void
    {
        $figures = [];
        for ($i = 0; $i < 8; $i++) {
            array_push($figures, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, $i * 2.0, 0.0, -$i, 1.0, $i * 0.13, 1.1, 0.0, 0.0);
        }
        $rest = [1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, -0.4, 0.0, 1.0];
        $cpu = GpuPartAnimator::step($figures, $rest, [0.2, 1.4, 0.0], [1.0, 0.0, 0.0], 0.6, 0.25, 1.7);
        self::assertCount(8 * 16, $cpu);
        // The part hangs 0.4 below its pivot and swings forward/back: its y stays below the shoulder.
        self::assertLessThan(1.4, $cpu[13]);

        if (!extension_loaded('vio')) {
            self::markTestSkipped('vio missing');
        }
        $ctx = @vio_create('auto', ['width' => 16, 'height' => 16, 'headless' => true]);
        if (!$ctx || !vio_supports_feature($ctx, VIO_FEATURE_COMPUTE)) {
            self::markTestSkipped('no compute');
        }
        $in = vio_storage_buffer($ctx, ['data' => pack('f*', ...$figures), 'stride' => 4]);
        $out = vio_storage_buffer($ctx, ['size' => 8 * 64, 'stride' => 4]);
        $cmd = GpuPartAnimator::draw($ctx, $in, 8, $out, 'm', 'mat', $rest, [0.2, 1.4, 0.0], [1.0, 0.0, 0.0], 0.6, 0.25, 1.7);
        self::assertNotNull($cmd);
        vio_compute_wait($ctx);
        $gpu = array_values(unpack('f*', (string) vio_storage_buffer_read($ctx, $out)));
        $max = 0.0;
        foreach ($cpu as $k => $v) {
            $max = max($max, abs($v - $gpu[$k]));
        }
        vio_destroy($ctx);
        self::assertLessThan(1e-3, $max);
    }
}
