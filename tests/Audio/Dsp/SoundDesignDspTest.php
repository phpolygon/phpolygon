<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp;

use InvalidArgumentException;
use PHPolygon\Audio\Dsp\AllpassFilter;
use PHPolygon\Audio\Dsp\Biquad;
use PHPolygon\Audio\Dsp\BubbleGenerator;
use PHPolygon\Audio\Dsp\CombFilter;
use PHPolygon\Audio\Dsp\Envelope;
use PHPolygon\Audio\Dsp\LoopCrossfade;
use PHPolygon\Audio\Dsp\Reverb;
use PHPUnit\Framework\TestCase;

/**
 * Filters, comb/all-pass, reverb, envelopes, bubbles and loop crossfades.
 */
final class SoundDesignDspTest extends TestCase
{
    private const int SR = 44100;

    /** @return list<float> */
    private static function sine(float $hz, float $seconds, float $amp = 1.0, int $sr = self::SR): array
    {
        $n = (int) round($seconds * $sr);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $amp * sin(2.0 * M_PI * $hz * $i / $sr);
        }
        return $out;
    }

    /** RMS of the second half (past the filter's transient). @param list<float> $x */
    private static function steadyRms(array $x): float
    {
        $half = array_slice($x, intdiv(count($x), 2));
        $sum = 0.0;
        foreach ($half as $v) {
            $sum += $v * $v;
        }
        return sqrt($sum / max(1, count($half)));
    }

    /** @param list<float> $x */
    private static function peak(array $x): float
    {
        $p = 0.0;
        foreach ($x as $v) {
            $p = max($p, abs($v));
        }
        return $p;
    }

    /** @param list<float> $x */
    private static function gainDb(array $x, float $refRms): float
    {
        return 20.0 * log10(max(1e-12, self::steadyRms($x)) / $refRms);
    }

    // ---- Biquad ---------------------------------------------------------

    public function testLowPassPassesLowsAndCutsHighs(): void
    {
        $ref = self::steadyRms(self::sine(100.0, 0.2));
        $lp = Biquad::lowPass(1000.0);
        $this->assertEqualsWithDelta(0.0, self::gainDb($lp->process(self::sine(100.0, 0.2)), $ref), 0.1);
        $lp->reset();
        $this->assertEqualsWithDelta(-3.01, self::gainDb($lp->process(self::sine(1000.0, 0.2)), $ref), 0.1);
        $lp->reset();
        $this->assertLessThan(-30.0, self::gainDb($lp->process(self::sine(10000.0, 0.2)), $ref));
        // The analytic response matches.
        $this->assertEqualsWithDelta(-3.01, $lp->responseDb(1000.0), 0.01);
    }

    public function testHighPassAndBandPassAndNotch(): void
    {
        $hp = Biquad::highPass(2000.0);
        $this->assertLessThan(-30.0, $hp->responseDb(100.0));
        $this->assertEqualsWithDelta(0.0, $hp->responseDb(15000.0), 0.3);

        $bp = Biquad::bandPass(1000.0, 2.0);
        $this->assertEqualsWithDelta(0.0, $bp->responseDb(1000.0), 0.01);
        $this->assertLessThan(-10.0, $bp->responseDb(200.0));
        $this->assertLessThan(-10.0, $bp->responseDb(5000.0));

        $notch = Biquad::notch(1000.0, 4.0);
        $this->assertLessThan(-60.0, $notch->responseDb(1000.0));
        $this->assertEqualsWithDelta(0.0, $notch->responseDb(100.0), 0.1);
    }

    public function testShelvesAndPeaking(): void
    {
        $low = Biquad::lowShelf(200.0, 6.0);
        $this->assertEqualsWithDelta(6.0, $low->responseDb(20.0), 0.2);
        $this->assertEqualsWithDelta(0.0, $low->responseDb(10000.0), 0.1);
        $this->assertEqualsWithDelta(3.0, $low->responseDb(200.0), 0.1); // half gain at the corner

        $high = Biquad::highShelf(4000.0, -9.0);
        $this->assertEqualsWithDelta(-9.0, $high->responseDb(20000.0), 0.5);
        $this->assertEqualsWithDelta(0.0, $high->responseDb(50.0), 0.1);

        $peak = Biquad::peaking(1000.0, 12.0, 1.0);
        $this->assertEqualsWithDelta(12.0, $peak->responseDb(1000.0), 0.01);
        $this->assertEqualsWithDelta(0.0, $peak->responseDb(20.0), 0.1);
    }

    public function testBlocksJoinSeamlessly(): void
    {
        $x = self::sine(440.0, 0.05);
        $whole = Biquad::lowPass(800.0, 2.0)->process($x);
        $f = Biquad::lowPass(800.0, 2.0);
        $parts = array_merge($f->process(array_slice($x, 0, 777)), $f->process(array_slice($x, 777)));
        $this->assertSame($whole, $parts);
        $f2 = Biquad::lowPass(800.0, 2.0);
        $one = [];
        foreach ($x as $v) {
            $one[] = $f2->processSample($v);
        }
        $this->assertSame($whole, $one);
    }

    public function testRejectsFrequencyAboveNyquist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Biquad::lowPass(30000.0);
    }

    // ---- Comb / all-pass ---------------------------------------------------

    public function testFeedbackCombEchoesEveryDelay(): void
    {
        $comb = new CombFilter(10, 0.5);
        $impulse = array_fill(0, 40, 0.0);
        $impulse[0] = 1.0;
        $y = $comb->process($impulse);
        $this->assertSame(1.0, $y[0]);
        $this->assertSame(0.5, $y[10]);
        $this->assertSame(0.25, $y[20]);
        $this->assertSame(0.125, $y[30]);
        $this->assertSame(0.0, $y[5]);
    }

    public function testDampingSoftensLaterEchoes(): void
    {
        $impulse = array_fill(0, 400, 0.0);
        $impulse[0] = 1.0;
        $plain = (new CombFilter(50, 0.8))->process($impulse);
        $damped = (new CombFilter(50, 0.8, 0.5))->process($impulse);
        // Same first sample, the echo is spread out (lower peak) by the loop low-pass.
        $this->assertSame($plain[0], $damped[0]);
        $this->assertLessThan(self::peak(array_slice($plain, 300)), self::peak(array_slice($damped, 300)));
    }

    public function testFeedForwardCombIsOneEcho(): void
    {
        $impulse = array_fill(0, 30, 0.0);
        $impulse[0] = 1.0;
        $y = (new CombFilter(10, 0.7, 0.0, true))->process($impulse);
        $this->assertSame(1.0, $y[0]);
        $this->assertSame(0.7, $y[10]);
        $this->assertSame(0.0, $y[20]);
    }

    public function testFeedbackForDecay(): void
    {
        $g = CombFilter::feedbackForDecay(441, 1.0, self::SR);
        // 100 round trips per second must lose 60 dB.
        $this->assertEqualsWithDelta(-60.0, 20.0 * log10($g ** 100), 1e-6);
        $this->expectException(InvalidArgumentException::class);
        new CombFilter(10, 1.0);
    }

    public function testAllpassKeepsTheEnergy(): void
    {
        $impulse = array_fill(0, 20000, 0.0);
        $impulse[0] = 1.0;
        $y = (new AllpassFilter(113, 0.5))->process($impulse);
        $energy = 0.0;
        foreach ($y as $v) {
            $energy += $v * $v;
        }
        $this->assertEqualsWithDelta(1.0, $energy, 1e-6);
    }

    // ---- Reverb --------------------------------------------------------------

    public function testReverbTailRingsAfterTheInputAndScalesWithRoomSize(): void
    {
        $click = array_fill(0, 2205, 0.0);
        $click[0] = 1.0;
        $small = (new Reverb(roomSize: 0.2, wet: 1.0, dry: 0.0))->processMono($click, 1.0);
        $large = (new Reverb(roomSize: 0.95, wet: 1.0, dry: 0.0))->processMono($click, 1.0);
        $this->assertCount(2205 + self::SR, $small);

        $late = static fn (array $x): float => self::steadyRms(array_slice($x, 22050));
        $this->assertGreaterThan(0.0, $late($small));
        $this->assertGreaterThan(2.0 * $late($small), $late($large), 'a larger room rings longer');
        $this->assertLessThan(1.0, self::peak($large));
    }

    public function testReverbDryOnlyIsTheInput(): void
    {
        $x = self::sine(300.0, 0.05, 0.5);
        $y = (new Reverb(wet: 0.0, dry: 1.0))->processMono($x);
        $this->assertCount(count($x), $y);
        foreach ($x as $i => $v) {
            $this->assertEqualsWithDelta($v, $y[$i], 1e-12);
        }
    }

    public function testReverbPreDelayAndStereoWidth(): void
    {
        $click = array_fill(0, 4410, 0.0);
        $click[0] = 1.0;
        $r = new Reverb(wet: 1.0, dry: 0.0, preDelaySeconds: 0.05);
        [$l] = $r->processStereo($click, $click);
        $this->assertSame(0.0, self::peak(array_slice($l, 0, 2205 + 200)), 'silent during pre-delay + first comb delay');

        [$wl, $wr] = (new Reverb(wet: 1.0, dry: 0.0, width: 1.0))->processStereo($click, $click, 0.2);
        [$nl, $nr] = (new Reverb(wet: 1.0, dry: 0.0, width: 0.0))->processStereo($click, $click, 0.2);
        $this->assertNotSame($wl, $wr, 'wide tail differs per channel');
        foreach ($nl as $i => $v) {
            $this->assertEqualsWithDelta($v, $nr[$i], 1e-12);
        }
    }

    public function testReverbIsDeterministicAndScalesToTheSampleRate(): void
    {
        $x = self::sine(500.0, 0.02, 0.3, 48000);
        $a = (new Reverb(sampleRate: 48000))->processMono($x, 0.1);
        $b = (new Reverb(sampleRate: 48000))->processMono($x, 0.1);
        $this->assertSame($a, $b);
        $this->assertCount(count($x) + 4800, $a);
    }

    // ---- Envelope ----------------------------------------------------------

    public function testAdsrShape(): void
    {
        $env = new Envelope(0.01, 0.02, 0.5, 0.05);
        $g = $env->render(0.1, 1000);
        $this->assertCount(150, $g);
        $this->assertSame(0.0, $g[0]);
        $this->assertEqualsWithDelta(0.5, $g[5], 1e-9);            // mid-attack
        $this->assertEqualsWithDelta(1.0, $g[10], 1e-9);           // peak
        $this->assertEqualsWithDelta(0.75, $g[20], 1e-9);          // mid-decay
        $this->assertEqualsWithDelta(0.5, $g[60], 1e-9);           // sustain
        $this->assertEqualsWithDelta(0.25, $g[124], 1e-9);         // half-way release
        $this->assertSame(0.0, $g[149]);
        $this->assertEqualsWithDelta(0.15, $env->length(0.1), 1e-12);
    }

    public function testShortGateReleasesFromWhereItIs(): void
    {
        $env = new Envelope(0.1, 0.0, 1.0, 0.01);
        $g = $env->render(0.05, 1000);
        $this->assertEqualsWithDelta(0.49, $g[49], 1e-9);
        $this->assertLessThan($g[49], $g[50]);
        $this->assertGreaterThan(0.4, $g[50], 'no jump at release');
    }

    public function testCurveBendsTheSegments(): void
    {
        $lin = (new Envelope(0.0, 0.0, 1.0, 0.1))->render(0.0, 1000);
        $exp = (new Envelope(0.0, 0.0, 1.0, 0.1, 3.0))->render(0.0, 1000);
        // Release from 0 when the gate never opened.
        $this->assertSame(0.0, self::peak($lin));
        $lin = (new Envelope(0.0, 0.0, 1.0, 0.1))->render(0.01, 1000);
        $exp = (new Envelope(0.0, 0.0, 1.0, 0.1, 3.0))->render(0.01, 1000);
        $this->assertLessThan($lin[60], $exp[60]);
    }

    public function testApplyKeepsLengthAndSilencesPastTheEnd(): void
    {
        $x = array_fill(0, 100, 1.0);
        $y = Envelope::ar(0.01, 0.02)->apply($x, 0.05, 1000);
        $this->assertCount(100, $y);
        $this->assertSame(1.0, $y[30]);
        $this->assertSame(0.0, $y[80]);
        // Default gate: the release ends exactly at the buffer's end.
        $z = Envelope::ar(0.0, 0.02)->apply($x, null, 1000);
        $this->assertSame(1.0, $z[79]);
        $this->assertSame(0.0, $z[99]);
    }

    // ---- Bubbles -------------------------------------------------------------

    public function testBubblesAreDeterministicAndFollowTheDensity(): void
    {
        $gen = new BubbleGenerator(seed: 7);
        $a = $gen->render(0.5, 40.0);
        $this->assertSame($a, (new BubbleGenerator(seed: 7))->render(0.5, 40.0));
        $this->assertNotSame($a, (new BubbleGenerator(seed: 8))->render(0.5, 40.0));
        $this->assertCount(22050, $a);

        $count = count((new BubbleGenerator(seed: 3))->schedule(20.0, 50.0));
        $this->assertEqualsWithDelta(1000, $count, 120, 'Poisson count ≈ rate × time');

        $ramp = (new BubbleGenerator(seed: 3))->schedule(20.0, static fn (float $t): float => $t < 10.0 ? 0.0 : 100.0);
        $this->assertNotEmpty($ramp);
        foreach ($ramp as $b) {
            $this->assertGreaterThanOrEqual(10.0, $b['start']);
        }
        $this->assertSame([], (new BubbleGenerator())->render(0.0, 10.0));
        $this->assertSame(0.0, self::peak((new BubbleGenerator())->render(0.1, 0.0)));
    }

    public function testBubbleGlidesUpWithinItsRange(): void
    {
        $gen = new BubbleGenerator(minHz: 1000.0, maxHz: 1000.0, minSeconds: 0.2, maxSeconds: 0.2, glide: 1.0, minGain: 0.5, maxGain: 0.5, decayDb: 0.0, seed: 1);
        // Pick a bubble with nothing else within ±0.3 s.
        $all = $gen->schedule(20.0, 0.5);
        $one = null;
        foreach ($all as $i => $b) {
            $prev = $all[$i - 1]['start'] ?? -1.0;
            $next = $all[$i + 1]['start'] ?? 99.0;
            if ($b['start'] - $prev > 0.3 && $next - $b['start'] > 0.3 && $b['start'] + 0.3 < 20.0) {
                $one = $b;
                break;
            }
        }
        $this->assertNotNull($one);
        $y = $gen->render(20.0, 0.5);
        $start = (int) round($one['start'] * self::SR);
        $zc = static function (array $x, int $from, int $len): int {
            $c = 0;
            for ($i = $from + 1; $i < $from + $len; $i++) {
                if (($x[$i - 1] < 0.0) !== ($x[$i] < 0.0)) {
                    $c++;
                }
            }
            return $c;
        };
        $early = $zc($y, $start + 100, 2205);           // u 0.01–0.06: ≈ 1040 Hz
        $late = $zc($y, $start + 8820 - 2400, 2205);    // u 0.73–0.78: ≈ 1750 Hz
        $this->assertGreaterThan(80, $early);
        $this->assertGreaterThan(1.5 * $early, $late, 'pitch rises');
        $this->assertLessThanOrEqual(0.5 + 1e-9, self::peak(array_slice($y, $start, 8820)));
        $this->assertSame(0.0, $y[$start - 1] ?? 0.0, 'nothing before the bubble');
    }

    public function testWrappedBubblesLoop(): void
    {
        $gen = new BubbleGenerator(minSeconds: 0.05, maxSeconds: 0.05, seed: 11);
        $plain = $gen->render(0.3, 60.0);
        $wrapped = $gen->render(0.3, 60.0, wrap: true);
        $this->assertCount(count($plain), $wrapped);
        // The tail of the last bubbles lands at the start.
        $this->assertNotSame(array_slice($plain, 0, 2000), array_slice($wrapped, 0, 2000));
    }

    // ---- Loop crossfade ------------------------------------------------------

    public function testLoopCrossfadeMakesASmoothSeam(): void
    {
        // A 440 Hz tone cut at an arbitrary length clicks at the wrap.
        $x = self::sine(440.0, 445.25 / 440.0, 0.8); // ends on a crest
        $this->assertGreaterThan(5.0, LoopCrossfade::seamRoughness($x));

        $loop = LoopCrossfade::makeSeconds($x, 0.05, self::SR, equalPower: false);
        $this->assertCount(count($x) - 2205, $loop);
        $this->assertLessThanOrEqual(1.05, LoopCrossfade::seamRoughness($loop));
        // Past the crossfade the source is untouched.
        $this->assertSame(array_slice($x, 2205, 1000), array_slice($loop, 2205, 1000));
    }

    public function testEqualPowerKeepsTheLevelOfNoise(): void
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Xoshiro256StarStar(5));
        $noise = [];
        for ($i = 0; $i < 40000; $i++) {
            $noise[] = $rng->nextFloat() * 2.0 - 1.0;
        }
        $c = 8000;
        $rms = static function (array $x): float {
            $s = 0.0;
            foreach ($x as $v) {
                $s += $v * $v;
            }
            return sqrt($s / count($x));
        };
        $ep = array_slice(LoopCrossfade::make($noise, $c), 0, $c);
        $lin = array_slice(LoopCrossfade::make($noise, $c, false), 0, $c);
        $ref = $rms($noise);
        $this->assertEqualsWithDelta($ref, $rms($ep), 0.03 * $ref);
        $this->assertLessThan(0.9 * $ref, $rms($lin), 'linear fades dip on uncorrelated material');

        [$l, $r] = LoopCrossfade::makeStereo($noise, $noise, $c);
        $this->assertSame($l, $r);
        $this->expectException(InvalidArgumentException::class);
        LoopCrossfade::make([0.0, 1.0, 2.0], 2);
    }
}
