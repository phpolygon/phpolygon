<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\System\GpuParticleBaker;
use PHPolygon\Tests\Rendering\VioTestContext;

/**
 * Work-graph particles (shader plan 10.2): GpuParticleBaker::spawnBursts() spawns
 * bursts on the GPU from one record each - every row starts at its burst's origin,
 * flies inside the cone at a speed from the range, lives within the life range, and
 * the bursts fill the ring one after the other, wrapping around its end.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class GpuParticleBurstTest extends TestCase
{
    public function testBurstsSpawnInsideTheirRangesAndWrapTheRing(): void
    {
        $ctx = VioTestContext::create(16, 16, ['shader_model' => 6]);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        try {
            if (!GpuParticleBaker::workGraphsAvailable($ctx)) {
                $this->markTestSkipped('no work graphs on this backend');
            }
            $state = GpuParticleBaker::createState($ctx, [], 256);
            self::assertNotNull($state);
            $up = ['origin' => [1.0, 2.0, 3.0], 'dir' => [0.0, 1.0, 0.0], 'cone' => deg2rad(30.0), 'speed' => [2.0, 3.0], 'life' => [1.0, 2.0], 'count' => 150, 'seed' => 11];
            $side = ['origin' => [-4.0, 0.5, 0.0], 'dir' => [1.0, 0.0, 0.0], 'cone' => deg2rad(10.0), 'speed' => [5.0, 5.0], 'life' => [0.5, 0.5], 'count' => 50, 'seed' => 12];
            self::assertTrue(GpuParticleBaker::spawnBursts($ctx, $state, [$up, $side]));
            self::assertSame(200, $state->ledger->head());
            $read = function () use ($ctx, $state): array {
                /** @var array<int, float> $f */
                $f = array_values(unpack('g*', vio_storage_buffer_read($ctx, $state->stateBuf)));
                return $f;
            };
            $check = function (array $f, int $slot, array $burst, string $what): void {
                $r = array_slice($f, $slot * 8, 8);
                self::assertEqualsWithDelta($burst['origin'][0], $r[0], 1e-5, "{$what}: origin");
                self::assertEqualsWithDelta($burst['origin'][1], $r[1], 1e-5, "{$what}: origin");
                $speed = sqrt($r[3] ** 2 + $r[4] ** 2 + $r[5] ** 2);
                self::assertGreaterThanOrEqual($burst['speed'][0] - 1e-4, $speed, "{$what}: speed");
                self::assertLessThanOrEqual($burst['speed'][1] + 1e-4, $speed, "{$what}: speed");
                $cos = ($r[3] * $burst['dir'][0] + $r[4] * $burst['dir'][1] + $r[5] * $burst['dir'][2]) / max($speed, 1e-6);
                self::assertGreaterThanOrEqual(cos($burst['cone']) - 1e-4, $cos, "{$what}: inside the cone");
                self::assertSame(0.0, $r[6], "{$what}: age 0");
                self::assertGreaterThanOrEqual($burst['life'][0] - 1e-5, $r[7], "{$what}: life");
                self::assertLessThanOrEqual($burst['life'][1] + 1e-5, $r[7], "{$what}: life");
            };
            $f = $read();
            $speeds = [];
            for ($i = 0; $i < 150; $i++) {
                $check($f, $i, $up, "up {$i}");
                $speeds[] = sqrt($f[$i * 8 + 3] ** 2 + $f[$i * 8 + 4] ** 2 + $f[$i * 8 + 5] ** 2);
            }
            self::assertGreaterThan(0.6, max($speeds) - min($speeds), 'speeds spread over the range');
            for ($i = 150; $i < 200; $i++) {
                $check($f, $i, $side, "side {$i}");
            }

            // A third burst runs over the end of the 256-slot ring: 56 slots at the end, 44 at the start.
            $wrap = ['origin' => [0.0, 10.0, 0.0], 'dir' => [0.0, -1.0, 0.0], 'cone' => 0.0, 'speed' => [1.0, 1.0], 'life' => [3.0, 3.0], 'count' => 100, 'seed' => 13];
            self::assertTrue(GpuParticleBaker::spawnBursts($ctx, $state, [$wrap]));
            self::assertFalse(GpuParticleBaker::spawnBursts($ctx, $state, [array_merge($wrap, ['count' => 300])]), 'more than the ring holds');
            $f = $read();
            for ($i = 200; $i < 256; $i++) {
                $check($f, $i, $wrap, "wrap end {$i}");
            }
            for ($i = 0; $i < 44; $i++) {
                $check($f, $i, $wrap, "wrap start {$i}");
            }
            $check($f, 44, $up, 'slot 44 keeps the first burst');
        } finally {
            vio_destroy($ctx);
        }
    }
}
