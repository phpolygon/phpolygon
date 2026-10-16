<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp;

use PHPolygon\Audio\Dsp\Limiter;
use PHPolygon\Audio\Dsp\Pcm;
use PHPUnit\Framework\TestCase;

final class LimiterTest extends TestCase
{
    public function testNoSampleExceedsTheCeiling(): void
    {
        $limiter = new Limiter(ceilingDb: -3.0);
        $left = Signals::sine(220.0, 0.5, 1.4);
        $right = Signals::sine(330.0, 0.5, 0.9);

        [$l, $r] = $limiter->processStereo($left, $right);

        $ceiling = Pcm::db(-3.0);
        self::assertLessThanOrEqual($ceiling, max(array_map('abs', $l)));
        self::assertLessThanOrEqual($ceiling, max(array_map('abs', $r)));
        // The loud parts are brought right up to the ceiling, not far below.
        self::assertGreaterThan($ceiling * 0.95, max(array_map('abs', $l)));
    }

    public function testBufferBelowTheCeilingIsUnchanged(): void
    {
        $limiter = new Limiter(ceilingDb: -1.0);
        $x = Signals::sine(440.0, 0.2, 0.5);

        self::assertSame($x, $limiter->processMono($x));
        self::assertNull($limiter->gains([$x]));
    }

    public function testGainFallsAheadOfThePeakWithinTheLookahead(): void
    {
        $sr = 1000;
        $limiter = new Limiter(ceilingDb: 20.0 * log10(0.5), lookaheadMs: 10.0, releaseMs: 100.0, sampleRate: $sr);
        $x = array_fill(0, 200, 0.25);
        $x[100] = 1.0; // needs gain 0.5

        $gains = $limiter->gains([$x]);
        self::assertNotNull($gains);

        // 10 ms at 1 kHz = 10 samples for a full swing: half a swing = 5.
        self::assertEqualsWithDelta(0.5, $gains[100], 1e-9);
        self::assertEqualsWithDelta(0.6, $gains[99], 1e-9);
        self::assertEqualsWithDelta(1.0, $gains[95], 1e-9);
        self::assertSame(1.0, $gains[94]);
        // Samples before the look-ahead window are untouched.
        $y = $limiter->processMono($x);
        for ($i = 0; $i < 95; $i++) {
            self::assertSame($x[$i], $y[$i]);
        }
        self::assertEqualsWithDelta(0.5, $y[100], 1e-9);
    }

    public function testReleaseRecoversLinearly(): void
    {
        $sr = 1000;
        $limiter = new Limiter(ceilingDb: 20.0 * log10(0.5), lookaheadMs: 0.0, releaseMs: 100.0, sampleRate: $sr);
        $x = array_fill(0, 300, 0.1);
        $x[100] = 1.0;

        $gains = $limiter->gains([$x]);
        self::assertNotNull($gains);

        // Without look-ahead the reduction is instantaneous at the peak.
        self::assertSame(1.0, $gains[99]);
        self::assertEqualsWithDelta(0.5, $gains[100], 1e-12);
        // 100 samples per full swing: back from 0.5 to 1 in 50 samples.
        self::assertEqualsWithDelta(0.75, $gains[125], 1e-9);
        self::assertEqualsWithDelta(1.0, $gains[150], 1e-9);
        self::assertSame(1.0, $gains[160]);
    }

    public function testChannelsAreLinked(): void
    {
        $limiter = new Limiter(ceilingDb: -6.0, sampleRate: 1000, lookaheadMs: 5.0);
        $left = array_fill(0, 50, 0.1);
        $right = array_fill(0, 50, 0.1);
        $left[25] = 1.0;

        [$l, $r] = $limiter->processStereo($left, $right);

        // The quiet right channel dips by the same gain as the loud left one.
        self::assertEqualsWithDelta($l[24] / $left[24], $r[24] / $right[24], 1e-12);
        self::assertLessThan(0.1, $r[25]);
    }

    public function testTruePeakCatchesInterSamplePeaks(): void
    {
        // A sine at fs/4 sampled 45° off its crests: every sample reads
        // 0.707·A, the waveform between them reaches A.
        $n = 400;
        $x = [];
        for ($i = 0; $i < $n; $i++) {
            $x[] = 1.0 * sin(M_PI / 2.0 * $i + M_PI / 4.0);
        }
        $ceilingDb = -2.0;
        $samplePeak = (new Limiter(ceilingDb: $ceilingDb))->processMono($x);
        $truePeak = (new Limiter(ceilingDb: $ceilingDb, truePeak: true))->processMono($x);

        // Sample peaks (0.707) are below −2 dBFS: the plain limiter leaves it.
        self::assertSame($x, $samplePeak);
        // The true-peak limiter estimates ~0.88 between the samples and acts.
        $estimate = 0.0;
        for ($i = 1; $i < $n - 2; $i++) {
            $estimate = max($estimate, abs(Limiter::catmullRom($truePeak[$i - 1], $truePeak[$i], $truePeak[$i + 1], $truePeak[$i + 2], 0.5)));
        }
        // An estimate: the gain moves between the samples, so allow 0.1 %.
        self::assertNotSame($x, $truePeak);
        self::assertLessThanOrEqual(Pcm::db($ceilingDb) * 1.001, $estimate);
    }

    public function testRejectsPositiveCeiling(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Limiter(ceilingDb: 1.0);
    }

    public function testRejectsUnequalChannels(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Limiter())->process([[0.1, 0.2], [0.1]]);
    }
}
