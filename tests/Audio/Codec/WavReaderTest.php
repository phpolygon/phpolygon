<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Codec;

use PHPolygon\Audio\Dsp\Pcm;
use PHPolygon\Audio\Codec\WavReader;
use PHPolygon\Tests\Audio\Dsp\Signals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WavReaderTest extends TestCase
{
    /** @return iterable<string, array{string, float}> */
    public static function formats(): iterable
    {
        yield '16-bit PCM' => ['pcm16', 1.0 / 32767];
        yield '24-bit PCM' => ['pcm24', 1.0 / 8388607];
        yield '32-bit float' => ['float32', 1e-7];
        yield 'WAVE_FORMAT_EXTENSIBLE 16-bit' => ['extensible16', 1.0 / 32767];
    }

    #[DataProvider('formats')]
    public function testDecodesEveryFormatAtTheDefaultRateBitExactWithinOneLsb(string $format, float $lsb): void
    {
        $x = Signals::sine(440.0, 0.05, 0.6);
        $out = WavReader::decode(Signals::wav([$x], Pcm::SAMPLE_RATE, $format, extraChunk: true));

        self::assertCount(count($x), $out);
        foreach ([0, 17, 1000, 2000] as $i) {
            self::assertEqualsWithDelta($x[$i], $out[$i], $lsb * 0.51 + 1e-9, "sample $i");
        }
    }

    public function testStereoIsMixedToMono(): void
    {
        $l = Signals::sine(300.0, 0.02, 0.4);
        $r = array_map(static fn (float $v): float => -0.5 * $v, $l);
        $out = WavReader::decode(Signals::wav([$l, $r], Pcm::SAMPLE_RATE, 'float32'));

        foreach ([5, 300, 800] as $i) {
            self::assertEqualsWithDelta(($l[$i] + $r[$i]) / 2.0, $out[$i], 1e-6);
        }
    }

    public function testFortyEightKilohertzIsResampledAndKeepsPitchAndLevel(): void
    {
        $x = Signals::sine(1000.0, 0.5, 0.5, 48000);
        $out = WavReader::decode(Signals::wav([$x], 48000, 'float32'));

        self::assertEqualsWithDelta(0.5 * Pcm::SAMPLE_RATE, count($out), 1.0);
        $mid = [(int) (0.1 * Pcm::SAMPLE_RATE), (int) (0.4 * Pcm::SAMPLE_RATE)];
        self::assertEqualsWithDelta(0.5 / sqrt(2.0), Signals::rms($out, ...$mid), 0.005);
        // The tone sits at 1 kHz and nowhere else.
        self::assertGreaterThan(0.24, Signals::at($out, 1000.0, ...$mid));
        self::assertLessThan(0.002, Signals::at($out, 1100.0, ...$mid));
    }

    public function testTheResamplerIsBandLimited(): void
    {
        // 23 kHz is legal at 48 kHz but above the 22.05 kHz Nyquist of the default
        // rate: a naive resampler would fold it down to 21.1 kHz. It must vanish.
        $x = Signals::sine(23000.0, 0.3, 0.5, 48000);
        $out = WavReader::decode(Signals::wav([$x], 48000, 'float32'));

        $mid = [(int) (0.05 * Pcm::SAMPLE_RATE), (int) (0.25 * Pcm::SAMPLE_RATE)];
        self::assertLessThan(0.005, Signals::rms($out, ...$mid), 'out-of-band tone must be filtered, not aliased');
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WavReader::decode('not a wav file at all');
    }

    public function testRejectsUnsupportedBitDepth(): void
    {
        $wav = Signals::wav([[0.0, 0.1]], Pcm::SAMPLE_RATE, 'pcm16');
        $wav = substr_replace($wav, pack('v', 12), 34, 2); // claim 12-bit

        $this->expectException(\InvalidArgumentException::class);
        WavReader::decode($wav);
    }

    public function testRejectsAMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        WavReader::read(__DIR__ . '/does-not-exist.wav');
    }
}
