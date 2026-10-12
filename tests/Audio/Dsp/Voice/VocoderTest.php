<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Pcm;
use PHPolygon\Audio\Dsp\Voice\Vocoder;
use PHPolygon\Audio\Dsp\Voice\VocoderCarrier;
use PHPUnit\Framework\TestCase;

final class VocoderTest extends TestCase
{
    /** @return list<float> a 300 Hz buzz for $on seconds, then silence for $off seconds */
    private static function burst(float $on, float $off): array
    {
        $out = [];
        $nOn = (int) ($on * Pcm::SAMPLE_RATE);
        $nOff = (int) ($off * Pcm::SAMPLE_RATE);
        for ($i = 0; $i < $nOn; $i++) {
            $out[] = 0.5 * sin(2 * M_PI * 300.0 * $i / Pcm::SAMPLE_RATE) + 0.2 * sin(2 * M_PI * 2400.0 * $i / Pcm::SAMPLE_RATE);
        }
        for ($i = 0; $i < $nOff; $i++) {
            $out[] = 0.0;
        }
        return $out;
    }

    /** @param list<float> $x */
    private static function rms(array $x, int $from, int $to): float
    {
        $sum = 0.0;
        for ($i = $from; $i < $to; $i++) {
            $sum += $x[$i] * $x[$i];
        }
        return sqrt($sum / max(1, $to - $from));
    }

    public function testDefaultsAreATwoHundredHertzCarrierAndThreeQuartersVoice(): void
    {
        self::assertSame(200.0, Vocoder::DEFAULT_CARRIER_HZ);
        self::assertSame(0.75, Vocoder::DEFAULT_DRY);
    }

    public function testKeepsTheLengthAndStaysWithinFullScale(): void
    {
        $in = self::burst(0.3, 0.2);
        $out = (new Vocoder())->process($in);

        self::assertCount(count($in), $out);
        self::assertLessThanOrEqual(Vocoder::PEAK + 1e-9, max(array_map('abs', $out)));
    }

    public function testSilenceStaysSilent(): void
    {
        $out = (new Vocoder())->process(array_fill(0, 4410, 0.0));

        self::assertSame(0.0, max(array_map('abs', $out)));
    }

    public function testTheCarrierOnlySoundsWhileTheVoiceSpeaks(): void
    {
        $in = self::burst(0.3, 0.3);
        $out = (new Vocoder(dry: 0.0))->process($in);
        $sr = Pcm::SAMPLE_RATE;

        $speaking = self::rms($out, (int) (0.05 * $sr), (int) (0.25 * $sr));
        $after = self::rms($out, (int) (0.40 * $sr), (int) (0.60 * $sr));
        self::assertGreaterThan(0.05, $speaking);
        self::assertLessThan($speaking * 0.01, $after, 'the robot tone must die with the voice');
    }

    public function testTheVocodedPartAddsTheCarrierPitch(): void
    {
        // Pure vocoded output of a 300 Hz voice carries the 200 Hz carrier.
        $in = self::burst(0.5, 0.0);
        $out = (new Vocoder(dry: 0.0))->process($in);
        $sr = Pcm::SAMPLE_RATE;
        $at = static function (array $x, float $f) use ($sr): float {
            $re = 0.0;
            $im = 0.0;
            for ($i = (int) (0.1 * $sr); $i < (int) (0.45 * $sr); $i++) {
                $re += $x[$i] * cos(2 * M_PI * $f * $i / $sr);
                $im += $x[$i] * sin(2 * M_PI * $f * $i / $sr);
            }
            return sqrt($re * $re + $im * $im);
        };

        self::assertGreaterThan($at($in, 200.0) * 10 + 1.0, $at($out, 200.0));
    }

    public function testDryOneIsTheVoiceItself(): void
    {
        $in = self::burst(0.2, 0.1);
        $out = (new Vocoder(dry: 1.0))->process($in);
        $scale = $out[100] / $in[100];

        foreach ([200, 1000, 5000] as $i) {
            self::assertEqualsWithDelta($in[$i] * $scale, $out[$i], 1e-9);
        }
    }

    public function testUsesTheCarrierItIsGiven(): void
    {
        $carrier = new class implements VocoderCarrier {
            /** @var list<array{float, int, int, int}> */
            public array $calls = [];

            public function render(float $rootHz, int $length, int $sampleRate, int $seed): array
            {
                $this->calls[] = [$rootHz, $length, $sampleRate, $seed];
                return array_fill(0, $length, 0.0);
            }
        };
        $in = self::burst(0.1, 0.0);
        $out = (new Vocoder(150.0, dry: 0.0, seed: 7, carrier: $carrier))->process($in);

        self::assertSame([[150.0, count($in), Pcm::SAMPLE_RATE, 7]], $carrier->calls);
        // A silent carrier leaves only the noise mixed into the upper bands.
        self::assertCount(count($in), $out);
    }

    public function testRejectsACarrierThatReturnsTooFewSamples(): void
    {
        $carrier = new class implements VocoderCarrier {
            public function render(float $rootHz, int $length, int $sampleRate, int $seed): array
            {
                return [0.0];
            }
        };

        $this->expectException(\LogicException::class);
        (new Vocoder(carrier: $carrier))->process(self::burst(0.05, 0.0));
    }

    public function testIsDeterministic(): void
    {
        $in = self::burst(0.2, 0.1);

        self::assertSame((new Vocoder())->process($in), (new Vocoder())->process($in));
    }
}
