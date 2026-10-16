<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Codec;

use PHPolygon\Audio\Codec\AudioEncoder;
use PHPolygon\Audio\Codec\AudioFormats;
use PHPolygon\Audio\Codec\FlacWriter;
use PHPolygon\Audio\Codec\OpusSettings;
use PHPolygon\Audio\Codec\OpusWriter;
use PHPolygon\Audio\Codec\VorbisSettings;
use PHPolygon\Audio\Codec\VorbisWriter;
use PHPUnit\Framework\TestCase;

/**
 * Opus / Vorbis through php-vio's encoder: the files decode (php-vio again) to
 * the input length and the input signal, are deterministic and small; the
 * settings validate; AudioEncoder falls back to FLAC where the codec is missing.
 * The encoder tests skip on a php-vio without vio_audio_encode().
 */
final class OpusVorbisWriterTest extends TestCase
{
    /** @return list<int> */
    private static function tone(float $hz, int $rate, int $frames, float $amp = 12000.0): array
    {
        $out = [];
        for ($i = 0; $i < $frames; $i++) {
            $out[] = (int) round($amp * sin(2 * M_PI * $hz * $i / $rate));
        }
        return $out;
    }

    private static function requireCodec(string $codec): void
    {
        if (!AudioFormats::detect()->canEncode($codec)) {
            self::markTestSkipped("php-vio without $codec encoding (vio_audio_encode)");
        }
    }

    /** @return array{format: string, channels: int, sample_rate: int, input_sample_rate: int, frames: int, pcm: string} */
    private static function decode(string $bytes): array
    {
        $d = vio_audio_decode($bytes, ['data' => true]);
        self::assertIsArray($d);
        /** @var array{format: string, channels: int, sample_rate: int, input_sample_rate: int, frames: int, pcm: string} $d */
        return $d;
    }

    /**
     * Zero crossings of channel $ch per second / 2.
     *
     * @param array{channels: int, sample_rate: int, frames: int, pcm: string} $d
     */
    private static function frequency(array $d, int $ch = 0): float
    {
        /** @var list<int> $s */
        $s = array_values((array) unpack('s*', $d['pcm']));
        $zc = 0;
        $prev = null;
        for ($i = $ch; $i < count($s); $i += $d['channels']) {
            if ($prev !== null && ($prev < 0) !== ($s[$i] < 0)) {
                $zc++;
            }
            $prev = $s[$i];
        }
        return $zc / 2 / ($d['frames'] / $d['sample_rate']);
    }

    public function testOpusMonoSpeechSettingsAtItsOwnRate(): void
    {
        self::requireCodec('opus');
        $settings = new OpusSettings(bitrate: 32000, encodeRate: 24000, application: 'voip', signal: 'voice');
        $bytes = OpusWriter::encodeMono(self::tone(300, 24000, 24000), 24000, $settings);

        $d = self::decode($bytes);
        self::assertSame('opus', $d['format']);
        self::assertSame(1, $d['channels']);
        self::assertSame(48000, $d['sample_rate']);
        self::assertSame(24000, $d['input_sample_rate']);
        self::assertSame(48000, $d['frames']);              // 1 s, sample-exact
        self::assertEqualsWithDelta(300, self::frequency($d), 5);
        self::assertLessThan(24000 * 2 / 8, strlen($bytes)); // far below 16-bit PCM
        self::assertSame($bytes, OpusWriter::encodeMono(self::tone(300, 24000, 24000), 24000, $settings));
    }

    public function testOpusResamplesOtherRates(): void
    {
        self::requireCodec('opus');
        $frames = 22050; // 0.5 s at 44.1 kHz
        $bytes = OpusWriter::encodeMono(self::tone(440, 44100, $frames), 44100, new OpusSettings(encodeRate: 24000));
        $d = self::decode($bytes);
        self::assertSame(24000, $d['input_sample_rate']);
        self::assertSame(24000, $d['frames']);              // 12000 frames at 24 kHz → 24000 at 48 kHz
        self::assertEqualsWithDelta(440, self::frequency($d), 6);
    }

    public function testOpusStereoKeepsBothChannels(): void
    {
        self::requireCodec('opus');
        $bytes = OpusWriter::encode(self::tone(440, 48000, 48000), self::tone(660, 48000, 48000, 9000), 48000, new OpusSettings(bitrate: 128000));
        $d = self::decode($bytes);
        self::assertSame(2, $d['channels']);
        self::assertSame(48000, $d['frames']);
        self::assertEqualsWithDelta(440, self::frequency($d, 0), 6);
        self::assertEqualsWithDelta(660, self::frequency($d, 1), 6);
    }

    public function testVorbisStereoAndMono(): void
    {
        self::requireCodec('vorbis');
        $left = self::tone(440, 44100, 44100);
        $right = self::tone(660, 44100, 44100, 9000);
        $q5 = VorbisWriter::encode($left, $right, 44100);
        $d = self::decode($q5);
        self::assertSame('vorbis', $d['format']);
        self::assertSame([2, 44100, 44100], [$d['channels'], $d['sample_rate'], $d['frames']]);
        self::assertEqualsWithDelta(660, self::frequency($d, 1), 6);
        self::assertLessThan(strlen(FlacWriter::encode($left, $right)) / 2, strlen($q5));
        self::assertSame($q5, VorbisWriter::encode($left, $right, 44100));

        $abr = VorbisWriter::encodeMono(self::tone(440, 22050, 11025), 22050, new VorbisSettings(bitrate: 48000));
        $d = self::decode($abr);
        self::assertSame([1, 22050, 11025], [$d['channels'], $d['sample_rate'], $d['frames']]);
    }

    public function testEncoderUsesThePreferredCodecWhereItWorks(): void
    {
        self::requireCodec('opus');
        $enc = new AudioEncoder(['opus', 'vorbis'], new OpusSettings(encodeRate: 24000));
        self::assertSame('opus', $enc->format);
        self::assertSame('opus', $enc->extension());
        self::assertFalse($enc->isFallback());
        $d = self::decode($enc->encode([self::tone(440, 24000, 4800)], 24000));
        self::assertSame(9600, $d['frames']);
        self::assertStringStartsWith('opus:', $enc->fingerprint());
    }

    public function testEncoderFallsBackToFlac(): void
    {
        // a player without Opus/Vorbis: the build writes FLAC even where it could encode them
        $builder = new AudioFormats(['wav', 'flac', 'mp3', 'vorbis', 'opus'], ['wav', 'flac', 'opus', 'vorbis']);
        $enc = new AudioEncoder(['opus', 'vorbis'], formats: $builder, player: new AudioFormats());
        self::assertSame('flac', $enc->format);
        self::assertSame('flac', $enc->extension());
        self::assertTrue($enc->isFallback());
        self::assertSame('flac', $enc->fingerprint());
        $samples = self::tone(440, 44100, 5000);
        self::assertSame(FlacWriter::encodeMono($samples), $enc->encode([$samples]));

        // no encoder in this process at all
        $enc = new AudioEncoder(['vorbis'], formats: new AudioFormats());
        self::assertSame('flac', $enc->format);
        self::assertSame(FlacWriter::encode($samples, $samples), $enc->encode([$samples, $samples]));

        $wav = new AudioEncoder(['wav'], formats: new AudioFormats());
        self::assertStringStartsWith('RIFF', $wav->encode([$samples], 22050));
    }

    public function testWritersRefuseWithoutTheEncoder(): void
    {
        if (OpusWriter::isAvailable()) {
            self::assertTrue(VorbisWriter::isAvailable());
            $this->expectException(\InvalidArgumentException::class);
            OpusWriter::encodeMono([]);
        }
        $this->expectException(\RuntimeException::class);
        OpusWriter::encodeMono([1, 2, 3]);
    }

    public function testSettingsValidateAndFingerprint(): void
    {
        $a = new OpusSettings(bitrate: 32000, encodeRate: 24000, application: 'voip');
        self::assertSame(['application' => 'voip', 'signal' => 'auto', 'complexity' => 10, 'vbr' => true, 'frame_ms' => 20, 'bitrate' => 32000], $a->toOptions());
        self::assertNotSame($a->fingerprint(), (new OpusSettings(bitrate: 48000, encodeRate: 24000, application: 'voip'))->fingerprint());
        self::assertSame(['quality' => 0.5], (new VorbisSettings())->toOptions());
        self::assertSame(['bitrate' => 96000], (new VorbisSettings(bitrate: 96000))->toOptions());
        self::assertNotSame((new VorbisSettings(0.5))->fingerprint(), (new VorbisSettings(0.4))->fingerprint());

        foreach ([
            static fn () => new OpusSettings(encodeRate: 44100),
            static fn () => new OpusSettings(application: 'radio'),
            static fn () => new OpusSettings(signal: 'speech'),
            static fn () => new OpusSettings(complexity: 11),
            static fn () => new OpusSettings(frameMs: 5),
            static fn () => new OpusSettings(bitrate: 100),
            static fn () => new VorbisSettings(1.5),
            static fn () => new VorbisSettings(bitrate: 0),
        ] as $i => $make) {
            try {
                $make();
                self::fail("case $i accepted");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
