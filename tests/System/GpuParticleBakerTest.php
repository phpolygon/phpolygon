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
            $this->markTestSkipped('vio context has no compute support, or php-vio < ' . GpuParticleBaker::MIN_VIO_VERSION . '.');
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

        $gpu = self::floats($packed);
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

    public function testStepIndirectCompactsLiveSlotsIntoTheArgumentRecord(): void
    {
        $ctx = $this->ctx;
        self::assertNotNull($ctx);
        if (!GpuParticleBaker::isIndirectDraw($ctx)) {
            $this->markTestSkipped('backend cannot draw indirectly (php-vio < 2.17 or no VIO_FEATURE_INDIRECT_DRAW)');
        }
        GpuParticleBaker::warm($ctx);

        $emitter = new ParticleEmitter(gravity: new Vec3(0.0, -1.0, 0.0), startSize: 0.5, endSize: 0.1);
        $live = 300;
        $capacity = 512;
        $seed = $this->seed($live);
        $seed[] = [1.0, 2.0, 3.0, 0.0, 0.0, 0.0, 5.0, 2.0]; // dead: age 5 > life 2

        $state = GpuParticleBaker::createState($ctx, $seed, $capacity, indexCount: 6);
        self::assertNotNull($state);
        self::assertNotNull($state->argsBuf, 'index count + indirect support => argument record allocated');

        self::assertTrue(GpuParticleBaker::stepIndirect($ctx, $state, $emitter, 0.016, new Vec3(0.0, 5.0, 20.0)));

        // {indexCount, instanceCount, firstIndex, baseVertex, firstInstance}
        $args = vio_storage_buffer_read($ctx, $state->argsBuf);
        self::assertNotFalse($args);
        $rec = array_values(unpack('V5', $args) ?: []);
        self::assertSame([6, $live, 0, 0, 0], $rec, 'exactly the live particles are counted');

        // The live matrices are compacted to the front: every slot below the
        // count is a real billboard (w = 1), the first slot past it untouched.
        $out = vio_storage_buffer_read($ctx, $state->outBuf);
        self::assertNotFalse($out);
        $m = array_values(unpack('f*', $out) ?: []);
        for ($i = 0; $i < $live; $i++) {
            self::assertSame(1.0, $m[$i * 16 + 15], "compacted slot {$i} is not a matrix");
        }
        self::assertSame(0.0, $m[$live * 16 + 15], 'slot past the live count stays empty');

        // A second step keeps the count exact (the reset kernel zeroes it first).
        self::assertTrue(GpuParticleBaker::stepIndirect($ctx, $state, $emitter, 0.016, null));
        $rec = array_values(unpack('V5', (string) vio_storage_buffer_read($ctx, $state->argsBuf)) ?: []);
        self::assertSame($live, $rec[1], 'instance count does not accumulate across steps');
    }

    public function testParticleDiesOnTheStepItsAgeReachesItsLifetime(): void
    {
        $ctx = $this->ctx;
        self::assertNotNull($ctx);
        GpuParticleBaker::warm($ctx);

        $emitter = new ParticleEmitter(gravity: new Vec3(0.0, 0.0, 0.0));
        $seed = [
            [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.99, 1.0], // 0.99 + 0.016 >= 1.0: dies this step
            [1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.50, 1.0], // stays alive
        ];
        $state = GpuParticleBaker::createState($ctx, $seed, 4);
        self::assertNotNull($state);

        $m = array_values(unpack('f*', (string) GpuParticleBaker::step($ctx, $state, $emitter, 0.016, null)) ?: []);
        self::assertSame(0.0, $m[15], 'the CPU path drops this particle on the same step');
        self::assertSame(1.0, $m[16 + 15]);

        // A shorter step must not revive it: the aged value was written back.
        $m = array_values(unpack('f*', (string) GpuParticleBaker::step($ctx, $state, $emitter, 0.001, null)) ?: []);
        self::assertSame(0.0, $m[15]);
        self::assertSame(1.0, $m[16 + 15]);
    }

    public function testInjectWritesRowsAtTheHeadAndWrapsAroundTheRing(): void
    {
        $ctx = $this->ctx;
        self::assertNotNull($ctx);
        self::assertTrue(GpuParticleBaker::warm($ctx));

        $state = GpuParticleBaker::createState($ctx, [], 8);
        self::assertNotNull($state);
        $state->ledger->advanceHead(6);

        $flat = [];
        for ($i = 0; $i < 4; $i++) {
            array_push($flat, 10.0 + $i, 20.0 + $i, 30.0 + $i, 1.0, 2.0, 3.0, 0.25, 4.0);
        }
        self::assertTrue(GpuParticleBaker::inject($ctx, $state, pack('f*', ...$flat), 4));
        self::assertSame(2, $state->ledger->head(), 'slots 6, 7, 0, 1 were written');
        self::assertFalse(GpuParticleBaker::inject($ctx, $state, pack('f*', ...$flat), 9), 'a batch larger than the ring is refused');
        self::assertFalse(GpuParticleBaker::inject($ctx, $state, pack('f*', ...$flat), 5), 'fewer bytes than rows is refused');

        $bytes = vio_storage_buffer_read($ctx, $state->stateBuf);
        self::assertIsString($bytes);
        $s = array_values(unpack('f*', $bytes) ?: []);
        foreach ([6 => 0, 7 => 1, 0 => 2, 1 => 3] as $slot => $row) {
            self::assertSame([10.0 + $row, 20.0 + $row, 30.0 + $row, 1.0, 2.0, 3.0, 0.25, 4.0], array_slice($s, $slot * 8, 8), "slot {$slot}");
        }
        foreach ([2, 3, 4, 5] as $slot) {
            self::assertSame(array_fill(0, 8, 0.0), array_slice($s, $slot * 8, 8), "slot {$slot} untouched");
        }
    }

    /** @return list<float> */
    private static function floats(string $bytes): array
    {
        $out = [];
        foreach (unpack('f*', $bytes) ?: [] as $f) {
            if (is_float($f)) {
                $out[] = $f;
            }
        }
        return $out;
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
        // Age first, then test — ParticleSystem::integrate() drops a particle
        // on the step its age reaches its lifetime.
        $age += $dt;
        if ($age >= $life || $life <= 0.0) {
            return $m;
        }
        $vx += $em->gravity->x * $dt;
        $vy += $em->gravity->y * $dt;
        $vz += $em->gravity->z * $dt;
        $px += $vx * $dt;
        $py += $vy * $dt;
        $pz += $vz * $dt;
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
