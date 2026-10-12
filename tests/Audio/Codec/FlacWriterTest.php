<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Codec;

use PHPolygon\Audio\Codec\FlacWriter;
use PHPolygon\Audio\Codec\WavWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The FLAC encoder round-trips bit-exactly through an independent decoder for
 * its subset (CRCs, frame numbers and the STREAMINFO MD5 included), shrinks
 * tonal material well below the WAV size, and php-vio accepts what it writes.
 */
final class FlacWriterTest extends TestCase
{
    /** @return iterable<string, array{list<int>, list<int>}> */
    public static function signals(): iterable
    {
        $n = 10000; // two full blocks and a short one
        $sine = [];
        $sine2 = [];
        for ($i = 0; $i < $n; $i++) {
            $sine[] = (int) round(12000 * sin(2 * M_PI * 440 * $i / 44100));
            $sine2[] = (int) round(9000 * sin(2 * M_PI * 554.37 * $i / 44100 + 0.3));
        }
        yield 'tones' => [$sine, $sine2];
        yield 'mono content (side = 0)' => [$sine, $sine];
        yield 'silence' => [array_fill(0, $n, 0), array_fill(0, $n, 0)];
        yield 'dc offset' => [array_fill(0, 5000, -1234), array_fill(0, 5000, 77)];

        $rng = 0x1234567;
        $noiseL = [];
        $noiseR = [];
        for ($i = 0; $i < 6000; $i++) {
            $rng = ($rng * 1103515245 + 12345) & 0x7FFFFFFF;
            $noiseL[] = ($rng >> 8) % 65536 - 32768;
            $rng = ($rng * 1103515245 + 12345) & 0x7FFFFFFF;
            $noiseR[] = ($rng >> 8) % 65536 - 32768;
        }
        yield 'full-scale noise' => [$noiseL, $noiseR];

        $extremeL = [];
        $extremeR = [];
        for ($i = 0; $i < 4500; $i++) {
            $extremeL[] = $i % 2 === 0 ? 32767 : -32768;
            $extremeR[] = $i % 2 === 0 ? -32768 : 32767;
        }
        yield 'opposite extremes (17-bit side)' => [$extremeL, $extremeR];
        yield 'one sample' => [[5], [-3]];
        yield 'shorter than a block' => [array_slice($sine, 0, 100), array_slice($sine2, 0, 100)];
    }

    /**
     * @param list<int> $left
     * @param list<int> $right
     */
    #[DataProvider('signals')]
    public function testRoundTripIsBitExact(array $left, array $right): void
    {
        $decoded = FlacTestDecoder::decode(FlacWriter::encode($left, $right));

        self::assertSame(44100, $decoded['sampleRate']);
        self::assertSame(count($left), $decoded['totalSamples']);
        self::assertSame(FlacWriter::BLOCK_SIZE, $decoded['maxBlock']);
        self::assertSame((int) ceil(count($left) / FlacWriter::BLOCK_SIZE), $decoded['frames']);
        self::assertSame($left, $decoded['left']);
        self::assertSame($right, $decoded['right']);
    }

    public function testManyFramesKeepTheirNumbers(): void
    {
        // > 128 frames: the frame number needs the two-byte UTF-8 form.
        $n = FlacWriter::BLOCK_SIZE * 130 + 7;
        $left = array_fill(0, $n, 0);
        $right = array_fill(0, $n, 0);
        $left[$n - 1] = 100;

        $decoded = FlacTestDecoder::decode(FlacWriter::encode($left, $right));

        self::assertSame(131, $decoded['frames']);
        self::assertSame($left, $decoded['left']);
    }

    public function testTonalMaterialShrinksBelowSixtyPercentOfTheWav(): void
    {
        [$left, $right] = self::tonal(3 * 44100);
        $flac = FlacWriter::encode($left, $right);
        $wav = WavWriter::stereo($left, $right);

        self::assertLessThan(0.6 * strlen($wav), strlen($flac), sprintf('FLAC is %.0f %% of the WAV', 100 * strlen($flac) / strlen($wav)));

        // A slice of it round-trips (the whole clip would take the bit-by-bit decoder too long).
        $left = array_slice($left, 44100, 20000);
        $right = array_slice($right, 44100, 20000);
        $decoded = FlacTestDecoder::decode(FlacWriter::encode($left, $right));
        self::assertSame($left, $decoded['left']);
        self::assertSame($right, $decoded['right']);
    }

    public function testReadWavReturnsTheSamplesItWasGiven(): void
    {
        $left = [0, 1, -1, 32767, -32768];
        $right = [5, -5, 300, -300, 0];

        $read = FlacWriter::readWav(WavWriter::stereo($left, $right));

        self::assertSame($left, $read['left']);
        self::assertSame($right, $read['right']);
    }

    /**
     * Mono (the spoken lines): one channel in STREAMINFO, independent
     * assignment, bit-exact round trip incl. MD5.
     *
     * @param list<int> $left
     * @param list<int> $right unused (the provider's second channel)
     */
    #[DataProvider('signals')]
    public function testMonoRoundTripIsBitExact(array $left, array $right): void
    {
        unset($right);
        $decoded = FlacTestDecoder::decode(FlacWriter::encodeMono($left));

        self::assertSame(1, $decoded['channels']);
        self::assertSame(44100, $decoded['sampleRate']);
        self::assertSame(count($left), $decoded['totalSamples']);
        self::assertSame($left, $decoded['left']);
    }

    public function testMonoIsAboutHalfTheStereoSizeOfTheSameSignal(): void
    {
        $tone = [];
        for ($i = 0; $i < 20000; $i++) {
            $tone[] = (int) round(9000 * sin(2 * M_PI * 220 * $i / 44100) + 3000 * sin(2 * M_PI * 1330 * $i / 44100));
        }
        $other = array_map(static fn (int $v): int => intdiv($v, 3), array_reverse($tone));

        self::assertLessThan(0.6 * strlen(FlacWriter::encode($tone, $other)), strlen(FlacWriter::encodeMono($tone)));
    }

    public function testPhpVioLoadsAMonoFile(): void
    {
        if (!function_exists('vio_audio_load')) {
            self::markTestSkipped('php-vio not loaded');
        }
        $tone = [];
        for ($i = 0; $i < 8000; $i++) {
            $tone[] = (int) round(8000 * sin(2 * M_PI * 330 * $i / 44100));
        }
        $path = sys_get_temp_dir() . '/phpolygon-flac-mono-' . uniqid() . '.flac';
        file_put_contents($path, FlacWriter::encodeMono($tone));
        try {
            self::assertNotFalse(vio_audio_load($path));
        } finally {
            @unlink($path);
        }
    }

    public function testPhpVioLoadsTheFile(): void
    {
        if (!function_exists('vio_audio_load')) {
            self::markTestSkipped('php-vio not loaded');
        }
        [$left, $right] = self::tonal(2 * 44100);
        $path = sys_get_temp_dir() . '/phpolygon-flac-' . uniqid() . '.flac';
        file_put_contents($path, FlacWriter::encode($left, $right));
        try {
            $sound = vio_audio_load($path);
            self::assertNotFalse($sound);
        } finally {
            @unlink($path);
        }
    }

    /**
     * A chord progression with a slow tremolo and a little noise – a fair stand-in for
     * rendered music (harmonic, mostly predictable, never digitally silent).
     *
     * @return array{list<int>, list<int>}
     */
    private static function tonal(int $n): array
    {
        $chords = [[220.0, 277.18, 329.63], [174.61, 220.0, 261.63], [196.0, 246.94, 293.66]];
        $rng = 0x2468ACE;
        $left = [];
        $right = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $i / 44100;
            $chord = $chords[intdiv($i, 22050) % 3];
            $trem = 0.75 + 0.25 * sin(2 * M_PI * 0.5 * $t);
            $l = 0.0;
            $r = 0.0;
            foreach ($chord as $k => $hz) {
                $l += sin(2 * M_PI * $hz * $t) * (0.3 - 0.05 * $k);
                $r += sin(2 * M_PI * $hz * 1.003 * $t + $k) * (0.3 - 0.05 * $k);
            }
            $rng = ($rng * 1103515245 + 12345) & 0x7FFFFFFF;
            $noise = ($rng / 1073741824.0 - 1.0) * 0.002;
            $left[] = (int) round(9000 * $l * $trem + 32767 * $noise);
            $right[] = (int) round(9000 * $r * $trem - 32767 * $noise);
        }
        return [$left, $right];
    }
}
