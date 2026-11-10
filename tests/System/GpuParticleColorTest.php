<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\System\GpuParticleBaker;
use PHPolygon\System\ParticleColor;
use PHPolygon\Tests\Rendering\VioTestContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The step kernel's colour over life on the backend of the test context: between two
 * stops it unpacks, mixes and repacks the RGBA6 float. It must hand back exactly what
 * ParticleColor::packedAt() gives the CPU path - also for colours with alpha >= 0.5,
 * whose packed value lies at or above 2^23 and has no fraction bits left.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class GpuParticleColorTest extends TestCase
{
    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(32, 32);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!GpuParticleBaker::isAvailable($ctx)) {
            vio_destroy($ctx);
            $this->markTestSkipped('no compute support, or php-vio < ' . GpuParticleBaker::MIN_VIO_VERSION);
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

    /** @return array<string, array{0: Color, 1: Color}> */
    public static function gradients(): array
    {
        return [
            'red' => [new Color(1.0, 0.0, 0.0, 1.0), new Color(1.0, 0.0, 0.0, 1.0)],
            'white' => [new Color(1.0, 1.0, 1.0, 1.0), new Color(1.0, 1.0, 1.0, 1.0)],
            'warm' => [new Color(1.0, 0.9, 0.6, 1.0), new Color(1.0, 0.9, 0.6, 1.0)],
            'red to white, half alpha' => [new Color(1.0, 0.0, 0.0, 0.5), new Color(1.0, 1.0, 1.0, 0.6)],
            'white fading out' => [new Color(1.0, 1.0, 1.0, 1.0), new Color(1.0, 1.0, 1.0, 0.0)],
        ];
    }

    #[DataProvider('gradients')]
    public function testKernelColourMatchesTheCpuPath(Color $start, Color $end): void
    {
        $ctx = $this->ctx;
        self::assertNotNull($ctx);
        self::assertTrue(GpuParticleBaker::warm($ctx));

        $emitter = new ParticleEmitter(gravity: new Vec3(0.0, 0.0, 0.0));
        $emitter->colorOverLife = true;
        $emitter->startColor = $start;
        $emitter->endColor = $end;
        $stops = ParticleColor::stops($emitter);

        // Ages spread over the life, none on a stop: every particle takes the
        // unpack -> mix -> pack path of the kernel.
        $dt = 0.016;
        $life = 2.0;
        $seed = [];
        for ($i = 0; $i < 32; $i++) {
            $seed[] = [(float) $i, 0.0, 0.0, 0.0, 0.0, 0.0, 0.05 + $i * 0.06, $life];
        }
        $state = GpuParticleBaker::createState($ctx, $seed, 32);
        self::assertNotNull($state);
        $bytes = GpuParticleBaker::step($ctx, $state, $emitter, $dt, null, true);
        self::assertNotNull($bytes);
        $m = array_values(unpack('f*', $bytes) ?: []);

        foreach ($seed as $i => $p) {
            $t = ($p[6] + $dt) / $life;
            $want = array_map(static fn (float $c): int => (int) round($c * 63), ParticleColor::unpack(ParticleColor::packedAt($stops, $t)));
            $got = array_map(static fn (float $c): int => (int) round($c * 63), ParticleColor::unpack((float) $m[$i * 16 + 3]));
            // The kernel mixes in float32, the CPU in double: a channel may round
            // to the neighbouring step, never further.
            foreach ([0, 1, 2, 3] as $k) {
                self::assertLessThanOrEqual(1, abs($want[$k] - $got[$k]), sprintf(
                    'particle %d (t %.3f) channel %d: cpu rgba6 %s, gpu %s',
                    $i,
                    $t,
                    $k,
                    implode(' ', $want),
                    implode(' ', $got),
                ));
            }
        }
    }
}
