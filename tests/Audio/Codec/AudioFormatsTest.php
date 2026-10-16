<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Codec;

use PHPolygon\Audio\Codec\AudioFormats;
use PHPUnit\Framework\TestCase;

/**
 * Format detection and the two choices built on it: what a build writes
 * (encodable here, decodable by the player, else FLAC) and which sibling file
 * a player loads.
 */
final class AudioFormatsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpolygon-audio-formats-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    private static function modern(): AudioFormats
    {
        return new AudioFormats(['wav', 'flac', 'mp3', 'vorbis', 'opus'], ['wav', 'flac', 'opus', 'vorbis']);
    }

    public function testBaselineIsWhatEveryBackendPlays(): void
    {
        $f = new AudioFormats();
        self::assertTrue($f->canDecode('flac'));
        self::assertTrue($f->canDecode('mp3'));
        self::assertFalse($f->canDecode('opus'));
        self::assertTrue($f->canEncode('flac'));
        self::assertFalse($f->canEncode('vorbis'));
    }

    public function testDetectAlwaysContainsTheBaseline(): void
    {
        $f = AudioFormats::detect();
        foreach (AudioFormats::BASELINE_DECODE as $format) {
            self::assertTrue($f->canDecode($format), $format);
        }
        foreach (AudioFormats::BASELINE_ENCODE as $format) {
            self::assertTrue($f->canEncode($format), $format);
        }
        if (function_exists('vio_audio_formats')) {
            self::assertTrue($f->canDecode('opus'));
            self::assertTrue($f->canEncode('opus'));
        } else {
            self::assertFalse($f->canDecode('opus'));
        }
    }

    public function testChooseTakesTheFirstUsableFormatElseFlac(): void
    {
        $modern = self::modern();
        self::assertSame('opus', $modern->choose(['opus', 'vorbis']));
        self::assertSame('vorbis', $modern->choose(['vorbis', 'opus']));
        self::assertSame('flac', $modern->choose([]));
        self::assertSame('flac', (new AudioFormats())->choose(['opus', 'vorbis']));
        self::assertSame('wav', (new AudioFormats())->choose(['opus', 'wav']));
    }

    public function testChooseRespectsWhatThePlayerDecodes(): void
    {
        $builder = self::modern();
        $oldRuntime = new AudioFormats();
        self::assertSame('flac', $builder->choose(['opus', 'vorbis'], $oldRuntime));
        $vorbisOnly = new AudioFormats(['wav', 'flac', 'mp3', 'vorbis'], []);
        self::assertSame('vorbis', $builder->choose(['opus', 'vorbis'], $vorbisOnly));
        // the player decodes Opus but this process cannot write it
        self::assertSame('flac', (new AudioFormats())->choose(['opus'], $builder));
    }

    public function testResolvePrefersTheSmallestDecodableSibling(): void
    {
        touch("$this->dir/line.flac");
        touch("$this->dir/line.opus");
        $modern = self::modern();
        $old = new AudioFormats();

        self::assertSame("$this->dir/line.opus", $modern->resolve("$this->dir/line.flac"));
        self::assertSame("$this->dir/line.opus", $modern->resolve("$this->dir/line.opus"));
        self::assertSame("$this->dir/line.flac", $old->resolve("$this->dir/line.opus"));
        self::assertSame("$this->dir/line.flac", $old->resolve("$this->dir/line.flac"));
    }

    public function testResolveWithoutAPlayableFile(): void
    {
        touch("$this->dir/theme.ogg");
        self::assertNull((new AudioFormats())->resolve("$this->dir/theme.ogg"));
        self::assertSame("$this->dir/theme.ogg", self::modern()->resolve("$this->dir/theme.ogg"));
        self::assertNull(self::modern()->resolve("$this->dir/missing.flac"));

        touch("$this->dir/blob.bin");
        self::assertSame("$this->dir/blob.bin", self::modern()->resolve("$this->dir/blob.bin"));
    }

    public function testExtensionsAndPaths(): void
    {
        self::assertSame('ogg', AudioFormats::extension('vorbis'));
        self::assertSame('opus', AudioFormats::extension('opus'));
        self::assertSame('flac', AudioFormats::extension('flac'));
        self::assertSame('vorbis', AudioFormats::forPath('music/Theme.OGG'));
        self::assertSame('opus', AudioFormats::forPath('voice/de/a.b.opus'));
        self::assertSame('wav', AudioFormats::forPath('x.wav'));
        self::assertNull(AudioFormats::forPath('x.aiff'));
        self::assertNull(AudioFormats::forPath('noext'));

        $this->expectException(\InvalidArgumentException::class);
        AudioFormats::extension('aac');
    }
}
