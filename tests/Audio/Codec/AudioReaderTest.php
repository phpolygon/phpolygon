<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Codec;

use PHPolygon\Audio\Codec\AudioFormats;
use PHPolygon\Audio\Codec\AudioReader;
use PHPolygon\Audio\Codec\FlacReader;
use PHPolygon\Audio\Codec\OpusSettings;
use PHPolygon\Audio\Codec\OpusWriter;
use PHPolygon\Audio\Codec\VorbisWriter;
use PHPolygon\Audio\Codec\WavReader;
use PHPUnit\Framework\TestCase;

/**
 * One reader for any source: WAV and FLAC in plain PHP (identical to their own
 * readers), sniffed by content rather than name, compressed formats through
 * php-vio where it is loaded.
 */
final class AudioReaderTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/fixtures/flac';

    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $f) {
            @unlink($f);
        }
    }

    public function testWavAndFlacGoThroughTheirReaders(): void
    {
        $wav = self::FIXTURES . '/stereo16_48k.wav';
        $flac = self::FIXTURES . '/stereo16_48k__c8.flac';
        self::assertSame(AudioFormats::WAV, AudioReader::format($wav));
        self::assertSame(AudioFormats::FLAC, AudioReader::format($flac));
        self::assertSame(WavReader::read($wav, 22050), AudioReader::read($wav, 22050));
        self::assertSame(FlacReader::read($flac, 22050), AudioReader::read($flac, 22050));
        self::assertSame(AudioReader::read($wav), AudioReader::read($flac));
    }

    public function testTheContentDecidesNotTheName(): void
    {
        $flac = (string) file_get_contents(self::FIXTURES . '/mono16_44k__c5.flac');
        $misnamed = $this->file('wav', $flac);
        self::assertSame(AudioFormats::FLAC, AudioReader::format($misnamed));
        self::assertSame(FlacReader::decode($flac), AudioReader::read($misnamed));

        $id3 = 'ID3' . "\x03\x00\x00" . "\x00\x00\x00\x0A" . str_repeat("\0", 10) . $flac;
        self::assertSame(AudioFormats::FLAC, AudioReader::format($this->file('mp3', $id3)));

        self::assertSame(AudioFormats::OPUS, AudioReader::format($this->file('opus', 'OggS-not-really')));
        self::assertNull(AudioReader::format($this->file('bin', 'nothing audio')));
    }

    public function testOpusAndVorbisNeedPhpVio(): void
    {
        $tone = [];
        for ($i = 0; $i < 24000; $i++) {
            $tone[] = (int) round(8000 * sin(2 * M_PI * 440 * $i / 24000));
        }
        if (!OpusWriter::isAvailable() || !VorbisWriter::isAvailable()) {
            $file = $this->file('opus', 'OggS');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('vio_audio_decode');
            AudioReader::read($file);
        }
        $opus = $this->file('opus', OpusWriter::encodeMono($tone, 24000, new OpusSettings(encodeRate: 24000)));
        $vorbis = $this->file('ogg', VorbisWriter::encodeMono($tone, 24000));
        foreach ([$opus, $vorbis] as $file) {
            $out = AudioReader::read($file, 24000);
            self::assertEqualsWithDelta(24000, count($out), 24000 * 0.02, basename($file));
            $peak = max(array_map('abs', array_slice($out, 2000, 20000)));
            self::assertEqualsWithDelta(8000 / 32768, $peak, 0.05, basename($file));
        }
    }

    public function testUnreadableFilesThrow(): void
    {
        $this->expectException(\RuntimeException::class);
        AudioReader::read(self::FIXTURES . '/missing.wav');
    }

    private function file(string $ext, string $bytes): string
    {
        $f = sys_get_temp_dir() . '/audio-reader-' . bin2hex(random_bytes(4)) . ".$ext";
        file_put_contents($f, $bytes);
        $this->temp[] = $f;
        return $f;
    }
}
