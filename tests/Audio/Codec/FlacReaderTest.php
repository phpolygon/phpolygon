<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Codec;

use PHPolygon\Audio\Codec\FlacReader;
use PHPolygon\Audio\Codec\FlacWriter;
use PHPolygon\Audio\Codec\WavReader;
use PHPolygon\Audio\Dsp\Pcm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The FLAC decoder returns the encoder's input bit for bit: for streams of the
 * engine's own {@see FlacWriter}, for ffmpeg encodings at compression levels
 * 0–12 (LPC, Rice2, every stereo mode, wasted bits, 8- and 16-bit block size
 * codes, every sample rate code – fixtures in fixtures/flac, made by
 * generate.php), and for hand-built {@see FlacStreamBuilder} streams covering the
 * rest of RFC 9639 (LPC orders 1–32, VERBATIM, escape partitions, variable block
 * size, 8/12/20/32-bit). Damaged input fails loudly.
 */
final class FlacReaderTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/fixtures/flac';

    /** @return iterable<string, array{string}> */
    public static function ffmpegFixtures(): iterable
    {
        $files = glob(self::FIXTURES . '/*__*.flac') ?: [];
        sort($files);
        foreach ($files as $file) {
            yield basename($file, '.flac') => [$file];
        }
    }

    #[DataProvider('ffmpegFixtures')]
    public function testFfmpegEncodingsDecodeBitExact(string $file): void
    {
        $source = self::FIXTURES . '/' . explode('__', basename($file))[0] . '.wav';
        $wav = self::wavInts($source);

        $r = FlacReader::readSamples($file, verifyMd5: true);

        self::assertSame($wav['rate'], $r['info']->sampleRate);
        self::assertSame($wav['bits'], $r['info']->bitsPerSample);
        self::assertSame(count($wav['channels']), $r['info']->channels);
        self::assertSame(count($wav['channels'][0]), $r['info']->totalSamples);
        self::assertNotNull($r['info']->md5);
        foreach ($wav['channels'] as $c => $expected) {
            self::assertSame($expected, $r['channels'][$c], "channel $c");
        }
    }

    public function testFixturesCoverTheEncoderFeatures(): void
    {
        // Guards the fixture set itself: regenerating with another ffmpeg must
        // still exercise what the tests above claim.
        self::assertGreaterThanOrEqual(30, iterator_count(self::ffmpegFixtures()));
        $info = FlacReader::readInfo(self::FIXTURES . '/stereo24_96k__c12.flac');
        self::assertSame(96000, $info->sampleRate);
        self::assertSame(24, $info->bitsPerSample);
        self::assertSame(2, $info->channels);
        self::assertSame(1200, $info->totalSamples);
        self::assertEqualsWithDelta(0.0125, $info->duration(), 1e-12);
    }

    /** @return iterable<string, array{list<list<int>>}> */
    public static function writerSignals(): iterable
    {
        $n = 10000; // two full blocks and a short one
        $sine = [];
        $sine2 = [];
        for ($i = 0; $i < $n; $i++) {
            $sine[] = (int) round(12000 * sin(2 * M_PI * 440 * $i / 44100));
            $sine2[] = (int) round(9000 * sin(2 * M_PI * 554.37 * $i / 44100 + 0.3));
        }
        $rng = 0x1234567;
        $noise = [];
        $noise2 = [];
        for ($i = 0; $i < 6000; $i++) {
            $rng = ($rng * 1103515245 + 12345) & 0x7FFFFFFF;
            $noise[] = ($rng >> 8) % 65536 - 32768;
            $rng = ($rng * 1103515245 + 12345) & 0x7FFFFFFF;
            $noise2[] = ($rng >> 8) % 65536 - 32768;
        }
        yield 'stereo tones' => [[$sine, $sine2]];
        yield 'stereo, identical channels' => [[$sine, $sine]];
        yield 'stereo silence' => [[array_fill(0, $n, 0), array_fill(0, $n, 0)]];
        yield 'stereo full-scale noise' => [[$noise, $noise2]];
        yield 'mono tone' => [[$sine]];
        yield 'mono noise' => [[$noise]];
        yield 'mono silence' => [[array_fill(0, 5000, 0)]];
        yield 'mono, one sample' => [[[-7]]];
        yield 'stereo, one sample' => [[[5], [-3]]];
        yield 'mono, shorter than a block' => [[array_slice($sine, 0, 100)]];
        yield 'mono, block size + 1' => [[array_slice($sine, 0, FlacWriter::BLOCK_SIZE + 1)]];
    }

    /** @param list<list<int>> $channels */
    #[DataProvider('writerSignals')]
    public function testFlacWriterRoundTripIsBitExact(array $channels): void
    {
        $bytes = count($channels) === 1
            ? FlacWriter::encodeMono($channels[0])
            : FlacWriter::encode($channels[0], $channels[1]);

        $r = FlacReader::decodeSamples($bytes, verifyMd5: true);

        self::assertSame(Pcm::SAMPLE_RATE, $r['info']->sampleRate);
        self::assertSame(16, $r['info']->bitsPerSample);
        self::assertSame($channels, $r['channels']);
    }

    public function testEmptyStreamDecodesToNothing(): void
    {
        $r = FlacReader::decodeSamples(FlacWriter::encodeMono([]));
        self::assertSame([[]], $r['channels']);
        self::assertSame([], FlacReader::decode(FlacWriter::encodeMono([])));
    }

    public function testReadMatchesWavReaderForTheSamePcm(): void
    {
        foreach (['mono16_44k__c8', 'stereo16_48k__c5', 'stereo24_96k__c5'] as $name) {
            $flac = self::FIXTURES . "/$name.flac";
            $wav = self::FIXTURES . '/' . explode('__', $name)[0] . '.wav';
            self::assertSame(WavReader::read($wav, 48000), FlacReader::read($flac, 48000), $name);
        }
        // At its own rate nothing is resampled.
        $mono = FlacReader::read(self::FIXTURES . '/mono16_44k__c5.flac');
        self::assertCount(3001, $mono);
        self::assertSame(WavReader::read(self::FIXTURES . '/mono16_44k.wav'), $mono);
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function sampleSizes(): iterable
    {
        // bits, frame header sample size code (0 = from STREAMINFO)
        yield '8 bit' => [8, 1, 'c*'];
        yield '12 bit' => [12, 2, 'v*'];
        yield '16 bit from STREAMINFO' => [16, 0, 'v*'];
        yield '20 bit' => [20, 5, ''];
        yield '24 bit' => [24, 6, ''];
        yield '32 bit' => [32, 7, 'V*'];
    }

    #[DataProvider('sampleSizes')]
    public function testEverySampleSizeAndSubframeType(int $bits, int $sizeCode): void
    {
        $max = (1 << ($bits - 1)) - 1;
        $min = -$max - 1;
        $left = self::wave(300, $bits, 1);
        $right = self::wave(300, $bits, 2);
        $left[0] = $max;
        $left[1] = $min;
        $b = new FlacStreamBuilder(44100, 2, $bits);
        $codes = ['sizeCode' => $sizeCode, 'rateCode' => 9];
        $b->frame([$left, $right], [['verbatim'], ['verbatim']], $codes + ['chCode' => 1]);
        $b->frame([array_fill(0, 192, $min), array_fill(0, 192, $max)], [['constant'], ['constant']], $codes + ['chCode' => 1]);
        $fl = self::wave(1000, $bits, 3, 0.4);
        $fr = self::wave(1000, $bits, 4, 0.4);
        foreach ([8, 9, 10] as $stereo) {
            $b->frame([$fl, $fr], [['fixed', 2], ['fixed', 3]], $codes + ['chCode' => $stereo]);
        }
        $b->frame([$fl, $fr], [['lpc', [3, -2, 1], 12, 1, 'method' => 1, 'partitionOrder' => 3], ['fixed', 4]], $codes + ['chCode' => 1]);

        $bytes = $b->bytes();
        $r = FlacReader::decodeSamples($bytes, verifyMd5: true);

        self::assertSame($bits, $r['info']->bitsPerSample);
        self::assertSame(
            [array_merge($left, array_fill(0, 192, $min), $fl, $fl, $fl, $fl), array_merge($right, array_fill(0, 192, $max), $fr, $fr, $fr, $fr)],
            $r['channels'],
        );
    }

    public function testLpcOfEveryOrderWithRiceRice2AndEscapes(): void
    {
        $s = self::wave(4096, 16, 5, 0.6);
        $b = new FlacStreamBuilder(48000, 1, 16);
        $expected = [];
        for ($order = 1; $order <= 32; $order++) {
            // Any coefficients are lossless – the residual absorbs the rest.
            $coefs = [];
            for ($j = 0; $j < $order; $j++) {
                $coefs[] = (int) round(((($order * 7 + $j * 13) % 29) - 14) * 70 / ($j + 1));
            }
            $coefs[0] = 1800;
            $sub = ['lpc', $coefs, 15, 10, 'method' => $order % 2, 'partitionOrder' => $order % 5];
            if ($order % 7 === 0) {
                $sub['escape'] = 22; // unencoded binary partitions
            }
            $block = array_slice($s, ($order * 101) % 3000, 1024);
            $b->frame([$block], [$sub]);
            $expected[] = $block;
        }
        $b->frame([array_fill(0, 256, 0)], [['fixed', 1, 'escape' => 0]]); // zero-width escape

        $r = FlacReader::decodeSamples($b->bytes(), verifyMd5: true);
        self::assertSame([array_merge(...$expected, ...[array_fill(0, 256, 0)])], $r['channels']);
    }

    public function testVariableBlockSizesHeaderCodesAndWastedBits(): void
    {
        // Variable blocking (sample numbers, beyond 2^11 so the UTF-8 form
        // needs several bytes), 8- and 16-bit block sizes from the header end,
        // all three sample rate escape codes.
        $sizes = [192, 77, 4096, 1000, 576, 256, 3000, 16, 2304];
        $rates = [[12000, 12], [11025, 13], [37800, 14], [44100, 0]];
        foreach ($rates as [$rate, $rateCode]) {
            $b = new FlacStreamBuilder($rate, 2, 24, variable: true);
            $left = [];
            $right = [];
            foreach ($sizes as $i => $n) {
                $l = array_map(static fn (int $v): int => $v & ~0x3FF, self::wave($n, 24, $i + 1, 0.8));
                $r = self::wave($n, 24, $i + 20, 0.3);
                $b->frame([$l, $r], [
                    ['fixed', min(4, $n - 1), 'wasted' => 10, 'method' => 1],
                    ['lpc', [2, -1], 4, 0, 'partitionOrder' => $n % 16 === 0 && $n >= 64 ? 4 : 0],
                ], ['rateCode' => $rateCode, 'chCode' => 1]);
                array_push($left, ...$l);
                array_push($right, ...$r);
            }
            $out = FlacReader::decodeSamples($b->bytes(), verifyMd5: true);
            self::assertSame($rate, $out['info']->sampleRate);
            self::assertSame([$left, $right], $out['channels'], "$rate Hz");
        }
    }

    public function testUnknownLengthAndBlankMd5(): void
    {
        $s = self::wave(500, 16, 9);
        $b = (new FlacStreamBuilder(44100, 1, 16))->frame([$s], [['verbatim']]);
        $r = FlacReader::decodeSamples($b->bytes(totalSamples: 0, md5: false), verifyMd5: true);
        self::assertSame(0, $r['info']->totalSamples);
        self::assertNull($r['info']->md5);
        self::assertSame([$s], $r['channels']);
    }

    public function testLeadingId3TagIsSkipped(): void
    {
        $flac = (string) file_get_contents(self::FIXTURES . '/mono16_44k__c5.flac');
        $id3 = 'ID3' . "\x04\x00\x00" . "\x00\x00\x01\x05" . str_repeat("\0", 133); // syncsafe 133
        $file = self::temp($id3 . $flac);
        try {
            self::assertSame(44100, FlacReader::readInfo($file)->sampleRate);
            self::assertSame(
                FlacReader::decodeSamples($flac)['channels'],
                FlacReader::readSamples($file, true)['channels'],
            );
        } finally {
            unlink($file);
        }
    }

    public function testTruncatedInsideAFrameFails(): void
    {
        $flac = (string) file_get_contents(self::FIXTURES . '/mono16_44k__c0.flac');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/truncated.*frame \d+/');
        FlacReader::decodeSamples(substr($flac, 0, strlen($flac) - 300));
    }

    public function testTruncatedAtAFrameBoundaryFails(): void
    {
        $s = self::wave(2000, 16, 3);
        $b = new FlacStreamBuilder(44100, 1, 16);
        $b->frame([array_slice($s, 0, 1000)], [['verbatim']]);
        $one = $b->bytes(totalSamples: 2000, md5: false);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ends after 1000 of 2000 samples');
        FlacReader::decodeSamples($one);
    }

    public function testCorruptFrameBodyFailsTheCrc16(): void
    {
        $file = self::FIXTURES . '/stereo16_48k__c8.flac';
        $flac = (string) file_get_contents($file);
        $at = strlen($flac) - 40; // inside the last frame's residual
        $flac[$at] = chr(ord($flac[$at]) ^ 0x10);
        try {
            FlacReader::decodeSamples($flac);
            self::fail('a corrupt frame decoded');
        } catch (\InvalidArgumentException $e) {
            self::assertMatchesRegularExpression('/^FLAC data: frame \d+ at byte \d+: /', $e->getMessage());
        }
    }

    public function testCorruptHeaderFailsTheCrc8(): void
    {
        $s = self::wave(300, 16, 3);
        $bytes = (new FlacStreamBuilder(44100, 1, 16))->frame([$s], [['verbatim']], ['blockCode' => 7])->bytes();
        $frame = strpos($bytes, "\xFF\xF8");
        self::assertIsInt($frame);
        $bytes[$frame + 5] = chr(ord($bytes[$frame + 5]) ^ 0x01); // the 16-bit block size
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('frame 0 at byte ' . $frame . ': header CRC-8 mismatch');
        FlacReader::decodeSamples($bytes);
    }

    public function testMd5MismatchIsOnlyCheckedOnRequest(): void
    {
        $flac = (string) file_get_contents(self::FIXTURES . '/mono16_44k__c5.flac');
        $md5At = 8 + 18; // marker + block header + STREAMINFO offset of the MD5
        $flac[$md5At] = chr(ord($flac[$md5At]) ^ 0xFF);
        self::assertCount(3001, FlacReader::decodeSamples($flac)['channels'][0]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MD5');
        FlacReader::decodeSamples($flac, verifyMd5: true);
    }

    public function testNotFlacAndMissingFiles(): void
    {
        try {
            FlacReader::decodeSamples('RIFF....WAVE');
            self::fail('accepted a WAV header');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('no fLaC marker', $e->getMessage());
        }
        $missing = self::FIXTURES . '/does-not-exist.flac';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($missing);
        FlacReader::read($missing);
    }

    public function testErrorsNameTheFile(): void
    {
        $flac = (string) file_get_contents(self::FIXTURES . '/mono16_44k__c0.flac');
        $file = self::temp(substr($flac, 0, strlen($flac) - 100));
        try {
            FlacReader::read($file);
            self::fail('a truncated file decoded');
        } catch (\InvalidArgumentException $e) {
            self::assertStringStartsWith("$file: truncated", $e->getMessage());
        } finally {
            unlink($file);
        }
    }

    /**
     * A deterministic test signal: two partials and a little LCG noise.
     *
     * @return list<int>
     */
    private static function wave(int $n, int $bits, int $seed, float $amp = 0.9): array
    {
        $full = (1 << ($bits - 1)) - 1;
        $rng = $seed * 7919 + 1;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $rng = ($rng * 48271) % 0x7FFFFFFF;
            $v = $amp * (0.7 * sin($i * 0.031 * $seed) + 0.25 * sin($i * 0.17 + $seed) + 0.05 * ($rng / 0x3FFFFFFF - 1.0));
            $out[] = (int) max(-$full - 1, min($full, round($v * $full)));
        }
        return $out;
    }

    /** @return array{rate: int, bits: int, channels: list<list<int>>} */
    private static function wavInts(string $file): array
    {
        $b = (string) file_get_contents($file);
        /** @var array{ch: int, rate: int, bits: int} $fmt */
        $fmt = unpack('vtag/vch/Vrate/Vbyterate/valign/vbits', $b, 20);
        $data = substr($b, 44);
        $width = intdiv($fmt['bits'], 8);
        $channels = array_fill(0, $fmt['ch'], []);
        $frames = intdiv(strlen($data), $width * $fmt['ch']);
        for ($i = 0, $o = 0; $i < $frames; $i++) {
            for ($c = 0; $c < $fmt['ch']; $c++, $o += $width) {
                $v = 0;
                for ($k = $width - 1; $k >= 0; $k--) {
                    $v = ($v << 8) | ord($data[$o + $k]);
                }
                $sign = 1 << ($fmt['bits'] - 1);
                $channels[$c][] = $v >= $sign ? $v - 2 * $sign : $v;
            }
        }
        return ['rate' => $fmt['rate'], 'bits' => $fmt['bits'], 'channels' => $channels];
    }

    private static function temp(string $bytes): string
    {
        $file = sys_get_temp_dir() . '/flac-reader-' . bin2hex(random_bytes(4)) . '.flac';
        file_put_contents($file, $bytes);
        return $file;
    }
}
