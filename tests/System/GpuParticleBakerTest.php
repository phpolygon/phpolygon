<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Math\Vec3;
use PHPolygon\System\GpuParticleBaker;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real particle compute shader on a hidden vio context and asserts the
 * GPU output matches the CPU billboard math it replaces, to float precision.
 *
 * Skipped automatically when no vio OpenGL context or no compute support is
 * available (headless CI without a GPU / software renderer). A GPU failure must
 * never fail the suite — this test only runs where the GPU path is exercisable.
 */
#[RequiresPhpExtension('vio')]
class GpuParticleBakerTest extends TestCase
{
    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        if (!function_exists('vio_create')) {
            $this->markTestSkipped('vio_create unavailable');
        }
        $ctx = @vio_create('opengl', [
            'width' => 32, 'height' => 32, 'title' => 'gpu-particle-test',
            'vsync' => false, 'headless' => true,
        ]);
        if ($ctx === false) {
            $this->markTestSkipped('No vio OpenGL context (headless CI without a GPU).');
        }
        if (!GpuParticleBaker::isAvailable($ctx)) {
            vio_destroy($ctx);
            $this->markTestSkipped('vio context has no compute support.');
        }
        $this->ctx = $ctx;
    }

    protected function tearDown(): void
    {
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testStepMatchesCpuBillboardMath(): void
    {
        $ctx = $this->ctx;
        self::assertNotNull($ctx);
        self::assertTrue(GpuParticleBaker::warm($ctx));

        $emitter = new ParticleEmitter(
            gravity: new Vec3(0.0, -1.0, 0.0),
            startSize: 0.5,
            endSize: 0.1,
        );
        $cam = new Vec3(0.0, 5.0, 20.0);
        $dt = 0.016;

        $n = 300;
        $capacity = 512;
        $seed = $this->seed($n);

        $state = GpuParticleBaker::createState($ctx, $seed, $capacity);
        self::assertNotNull($state);

        $packed = GpuParticleBaker::step($ctx, $state, $emitter, $dt, $cam, true);
        self::assertNotNull($packed);

        $unpacked = unpack('f*', $packed);
        self::assertNotFalse($unpacked);
        $gpu = array_values($unpacked);
        self::assertCount($capacity * 16, $gpu);

        $maxErr = 0.0;
        for ($i = 0; $i < $capacity; $i++) {
            $cpu = $this->cpuReference($i < $n ? $seed[$i] : null, $emitter, $cam, $dt);
            for ($k = 0; $k < 16; $k++) {
                $e = abs($cpu[$k] - $gpu[$i * 16 + $k]);
                if ($e > $maxErr) {
                    $maxErr = $e;
                }
            }
        }
        self::assertLessThan(1e-3, $maxErr, "GPU billboard diverged from CPU by {$maxErr}");
    }

    public function testDeadSlotsAreZeroMatrices(): void
    {
        $ctx = $this->ctx;
        self::assertNotNull($ctx);
        GpuParticleBaker::warm($ctx);

        $emitter = new ParticleEmitter();
        // One already-dead particle (age >= lifetime) plus empty capacity.
        $seed = [[0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 5.0, 2.0]]; // age 5 > life 2 -> dead
        $state = GpuParticleBaker::createState($ctx, $seed, 64);
        self::assertNotNull($state);

        $packed = GpuParticleBaker::step($ctx, $state, $emitter, 0.016, new Vec3(0, 0, 10), true);
        self::assertNotNull($packed);
        $gpu = array_values(unpack('f*', $packed) ?: []);

        // The dead seed slot (0) and an untouched slot (40) must be all zero.
        foreach ([0, 40] as $slot) {
            for ($k = 0; $k < 16; $k++) {
                self::assertSame(0.0, $gpu[$slot * 16 + $k], "slot {$slot} float {$k} not zero");
            }
        }
    }

    /**
     * CPU reference: integrate one step (semi-implicit Euler) then build the
     * camera-facing billboard matrix from the post-integrate state — identical
     * to ParticleSystem::integrate() + writeBillboardMatrix(). Null / dead ->
     * zero matrix.
     *
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}|null $p
     * @return array<int, float>
     */
    private function cpuReference(?array $p, ParticleEmitter $em, Vec3 $cam, float $dt): array
    {
        $m = array_fill(0, 16, 0.0);
        if ($p === null) {
            return $m;
        }
        [$px, $py, $pz, $vx, $vy, $vz, $age, $life] = $p;
        if ($age >= $life || $life <= 0.0) {
            return $m;
        }
        $vx += $em->gravity->x * $dt;
        $vy += $em->gravity->y * $dt;
        $vz += $em->gravity->z * $dt;
        $px += $vx * $dt;
        $py += $vy * $dt;
        $pz += $vz * $dt;
        $age += $dt;
        $t = $age / max($life, 1e-4);
        $size = $em->startSize + ($em->endSize - $em->startSize) * $t;

        $dx = $cam->x - $px; $dy = $cam->y - $py; $dz = $cam->z - $pz;
        $len = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
        if ($len < 1e-6) {
            $m[0] = $size; $m[5] = $size; $m[10] = $size;
            $m[12] = $px; $m[13] = $py; $m[14] = $pz; $m[15] = 1.0;
            return $m;
        }
        $fx = $dx / $len; $fy = $dy / $len; $fz = $dz / $len;
        if (abs($fy) > 0.999) { $upx = 0.0; $upy = 0.0; $upz = 1.0; }
        else                  { $upx = 0.0; $upy = 1.0; $upz = 0.0; }
        $rx = $upy * $fz - $upz * $fy;
        $ry = $upz * $fx - $upx * $fz;
        $rz = $upx * $fy - $upy * $fx;
        $rlen = sqrt($rx * $rx + $ry * $ry + $rz * $rz);
        if ($rlen > 1e-6) { $rx /= $rlen; $ry /= $rlen; $rz /= $rlen; }
        $uxf = $fy * $rz - $fz * $ry;
        $uyf = $fz * $rx - $fx * $rz;
        $uzf = $fx * $ry - $fy * $rx;

        $m[0] = $rx * $size;  $m[1] = $ry * $size;  $m[2] = $rz * $size;
        $m[4] = $uxf * $size; $m[5] = $uyf * $size; $m[6] = $uzf * $size;
        $m[8] = $fx * $size;  $m[9] = $fy * $size;  $m[10] = $fz * $size;
        $m[12] = $px; $m[13] = $py; $m[14] = $pz; $m[15] = 1.0;
        return $m;
    }

    /**
     * @return list<array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}>
     */
    private function seed(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                ($i % 17) - 8.0, ($i % 11) + 5.0, ($i % 13) - 6.5,
                (($i * 7919) % 100) / 100.0, 1.5, (($i * 6151) % 100) / 100.0,
                ($i % 50) / 50.0, 2.0,
            ];
        }
        return $out;
    }
}
