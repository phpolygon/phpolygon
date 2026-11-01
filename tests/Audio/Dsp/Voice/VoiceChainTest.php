<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Biquad;
use PHPolygon\Audio\Dsp\Loudness;
use PHPolygon\Audio\Dsp\Voice\Crusher;
use PHPolygon\Audio\Dsp\Voice\PitchDetector;
use PHPolygon\Audio\Dsp\Voice\SawChordCarrier;
use PHPolygon\Audio\Dsp\Voice\TailBreathTrimmer;
use PHPolygon\Audio\Dsp\Voice\Vocoder;
use PHPolygon\Audio\Dsp\Voice\VoiceChain;
use PHPolygon\Audio\Dsp\Voice\VoiceProfile;
use PHPolygon\Tests\Audio\Dsp\Signals;
use PHPUnit\Framework\TestCase;

final class VoiceChainTest extends TestCase
{
    /** @var list<float>|null */
    private static ?array $vowel = null;

    private static function robot(): VoiceProfile
    {
        return new VoiceProfile(vocoderHz: 200.0, vocoderDry: 0.75);
    }

    private static function broken(float $crush): VoiceProfile
    {
        return new VoiceProfile(vocoderHz: 120.0, vocoderDry: 0.5, crush: $crush);
    }

    private static function speaker(): VoiceProfile
    {
        return new VoiceProfile(pitch: 1.09, formant: 0.95, tempo: 1.04, tiltLowDb: 1.0, tiltHighDb: -2.0, breath: 0.05);
    }

    /** @return list<float> */
    private static function vowel(): array
    {
        return self::$vowel ??= array_merge(Signals::vowel(190.0, 0.7, amp: 0.2), array_fill(0, 8820, 0.0), Signals::vowel(220.0, 0.5, amp: 0.1));
    }

    public function testHasAVersionForRenderCaches(): void
    {
        self::assertGreaterThanOrEqual(2, VoiceChain::CHAIN_VERSION);
    }

    /** @return list<float> the vowel, a pause and an inhalation fading in */
    private static function vowelThenBreath(): array
    {
        $x = array_merge(Signals::vowel(190.0, 0.7, amp: 0.25), array_fill(0, 7000, 0.0));
        $noise = Biquad::highPass(600.0)->process(Signals::noise(0.5, 0.008));
        $n = count($noise);
        foreach ($noise as $i => $v) {
            $x[] = $v * min(1.0, $i / 6000.0, ($n - $i) / 3000.0);
        }
        return $x;
    }

    public function testTheBreathBehindTheLastWordDoesNotReachTheOutput(): void
    {
        $x = self::vowelThenBreath();
        $chain = new VoiceChain();

        $trimmed = $chain->render($x, new VoiceProfile());
        $kept = $chain->render($x, new VoiceProfile(), trimTailBreath: false);

        self::assertLessThan(count($kept) - 20000, count($trimmed));
    }

    public function testTheBreathDoesNotCountForTheLoudness(): void
    {
        $x = self::vowelThenBreath();

        $y = (new VoiceChain())->render($x, new VoiceProfile());

        self::assertEqualsWithDelta(VoiceChain::TARGET_LUFS, Loudness::integratedMono($y), 0.3);
        self::assertSame(
            (new VoiceChain())->render((new TailBreathTrimmer())->trim($x), new VoiceProfile(), trimTailBreath: false),
            $y,
        );
    }

    public function testATrimmerOfAnotherRateIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new VoiceChain(tailTrimmer: new TailBreathTrimmer(22050));
    }

    public function testNormalisesToMinusSixteenLufsUnderTheCeiling(): void
    {
        $y = (new VoiceChain())->render(self::vowel(), new VoiceProfile(pitch: 1.05));

        self::assertEqualsWithDelta(VoiceChain::TARGET_LUFS, Loudness::integratedMono($y), 0.3);
        self::assertLessThanOrEqual(VoiceChain::PEAK_CEILING + 1e-9, max(array_map('abs', $y)));
    }

    public function testThePeakCeilingWinsOverTheLoudnessTarget(): void
    {
        // Sparse clicks: very peaky, would need far more than the ceiling to reach −16 LUFS.
        $x = array_fill(0, 44100, 0.0);
        for ($i = 1000; $i < 44100; $i += 4410) {
            $x[$i] = 0.05;
        }
        $y = (new VoiceChain())->render($x, new VoiceProfile());

        self::assertEqualsWithDelta(VoiceChain::PEAK_CEILING, max(array_map('abs', $y)), 1e-9);
    }

    public function testAVocoderOnlyProfileIsTheVocoderLevelled(): void
    {
        $x = self::vowel();
        $y = (new VoiceChain())->render($x, self::robot());
        $v = (new Vocoder(200.0, 0.75))->process($x);

        $dot = 0.0;
        $ey = 0.0;
        $ev = 0.0;
        foreach ($y as $i => $s) {
            $dot += $s * $v[$i];
            $ey += $s * $s;
            $ev += $v[$i] * $v[$i];
        }
        self::assertEqualsWithDelta(1.0, $dot / sqrt($ey * $ev), 1e-9);
    }

    public function testASpeakerProfileShiftsThePitchByItsFactor(): void
    {
        $p = self::speaker();
        $y = (new VoiceChain())->render(self::vowel(), $p);
        $detector = new PitchDetector();

        $ratio = $detector->track($y)->medianF0() / $detector->track(self::vowel())->medianF0();
        self::assertEqualsWithDelta($p->pitch, $ratio, $p->pitch * 0.02);
        self::assertEqualsWithDelta(count(self::vowel()) / $p->tempo, count($y), 2.0);
    }

    public function testSilenceInSilenceOutForEveryNamedVoice(): void
    {
        $chain = new VoiceChain();
        foreach ([self::robot(), self::broken(0.7), self::broken(0.05), self::speaker()] as $p) {
            $y = $chain->render(array_fill(0, 8820, 0.0), $p);
            self::assertSame(0.0, max(array_map('abs', $y)));
        }
        self::assertSame([], $chain->render([], self::robot()));
    }

    public function testTheCarrierReachesTheVocoder(): void
    {
        $x = self::vowel();
        $carrier = new SawChordCarrier(detune: 0.01);
        $y = (new VoiceChain(carrier: $carrier))->render($x, self::robot());
        $v = (new Vocoder(200.0, 0.75, carrier: $carrier))->process($x);
        $dot = 0.0;
        $ey = 0.0;
        $ev = 0.0;
        foreach ($y as $i => $s) {
            $dot += $s * $v[$i];
            $ey += $s * $s;
            $ev += $v[$i] * $v[$i];
        }

        self::assertEqualsWithDelta(1.0, $dot / sqrt($ey * $ev), 1e-9);
        self::assertNotSame((new VoiceChain())->render($x, self::robot()), $y);
    }

    public function testRunsAtAnotherSampleRate(): void
    {
        $x = Signals::vowel(190.0, 0.6, amp: 0.2, sampleRate: 48000);
        $chain = new VoiceChain(sampleRate: 48000);
        $y = $chain->render($x, new VoiceProfile(pitch: 1.1));

        self::assertSame(48000, $chain->sampleRate());
        self::assertCount(count($x), $y);
        self::assertEqualsWithDelta(190.0 * 1.1, (new PitchDetector(sampleRate: 48000))->track($y)->medianF0(), 190.0 * 1.1 * 0.02);
        self::assertEqualsWithDelta(VoiceChain::TARGET_LUFS, Loudness::integratedMono($y, 48000), 0.3);
    }

    public function testRejectsPartsAtAnotherRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VoiceChain(crusher: new Crusher(sampleRate: 48000));
    }

    public function testIsDeterministicAndTheCrushIsAudible(): void
    {
        $chain = new VoiceChain();
        $x = self::vowel();

        self::assertSame($chain->render($x, self::broken(0.7)), $chain->render($x, self::broken(0.7)));
        self::assertNotSame($chain->render($x, self::broken(0.7)), $chain->render($x, self::broken(0.05)));
    }
}
