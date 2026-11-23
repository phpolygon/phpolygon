<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio;

use PHPolygon\Audio\AudioBackendInterface;
use PHPolygon\Audio\AudioClip;
use PHPolygon\Audio\AudioManager;
use PHPolygon\Audio\Codec\WavWriter;
use PHPolygon\Audio\StemPlayer;
use PHPolygon\Audio\VioAudioBackend;
use PHPolygon\Audio\WarmableAudioBackendInterface;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/** A backend that opens clips only on request, like php-vio. */
final class WarmSpyBackend implements WarmableAudioBackendInterface
{
    /** @var array<string, int> */
    public array $ready = [];
    /** @var list<string> */
    public array $warmCalls = [];
    /** @var list<string> */
    public array $missing = [];

    public function load(string $id, string $path): AudioClip
    {
        return new AudioClip($id, $path);
    }

    public function warm(string $clipId, int $copies = 1): bool
    {
        $this->warmCalls[] = $clipId;
        if (in_array($clipId, $this->missing, true)) {
            return false;
        }
        $this->ready[$clipId] = $copies;

        return true;
    }

    public function warmCount(string $clipId): int
    {
        return $this->ready[$clipId] ?? 0;
    }

    public function play(string $clipId, float $volume = 1.0, bool $loop = false): int
    {
        return 1;
    }

    public function stop(int $playbackId): void {}
    public function stopAll(): void {}
    public function setVolume(int $playbackId, float $volume): void {}
    public function isPlaying(int $playbackId): bool
    {
        return false;
    }
    public function setMasterVolume(float $volume): void {}
    public function getMasterVolume(): float
    {
        return 1.0;
    }
    public function dispose(): void {}
}

#[RunTestsInSeparateProcesses]
final class AudioWarmTest extends TestCase
{
    /** The php-vio tests mix into an offline engine: no sound leaves the machine. */
    private static function offlineEngine(): void
    {
        if (!vio_audio_init(['device' => false])) {
            self::markTestSkipped('php-vio offline audio engine did not start');
        }
    }

    public function testManagerWarmOpensTheNamedClips(): void
    {
        $backend = new WarmSpyBackend();
        $audio = new AudioManager($backend);
        $audio->loadClip('a', 'a.ogg');
        $audio->loadClip('b', 'b.ogg');
        self::assertSame([], $backend->warmCalls, 'loadClip alone does not open');

        self::assertSame(1, $audio->warm('a'));
        self::assertSame(['a'], $backend->warmCalls);
        self::assertSame(0, $audio->warm('a'), 'a clip that is ready is not opened twice');
        self::assertSame(['a'], $backend->warmCalls);
    }

    public function testWarmWithoutIdsOpensEveryLoadedClipAndCountsOnlyNewOnes(): void
    {
        $backend = new WarmSpyBackend();
        $backend->missing = ['gone'];
        $audio = new AudioManager($backend);
        $audio->loadClip('a', 'a.ogg');
        $audio->loadClip('gone', 'gone.ogg');
        $audio->loadClip('b', 'b.ogg');
        $audio->warm('b');
        self::assertSame(['b'], $backend->warmCalls);

        self::assertSame(1, $audio->warm(), 'a is new, gone fails, b was ready already');
        self::assertSame(['b', 'a', 'gone'], $backend->warmCalls);
    }

    public function testABackendWithoutWarmingIsLeftAlone(): void
    {
        $audio = new AudioManager(new SpyAudioBackend());
        $audio->loadClip('a', 'a.ogg');
        self::assertSame(0, $audio->warm());
        self::assertSame(0, $audio->warm('a'));
    }

    public function testStemPreloadWarmsOnlyItsOwnStems(): void
    {
        $backend = new WarmSpyBackend();
        $audio = new AudioManager($backend);
        $audio->loadClip('sfx', 'sfx.ogg');
        $player = new StemPlayer($audio);
        $player->addStem('intro', 'intro.ogg');
        $player->addStem('silent', null);
        $player->preload();

        self::assertSame(['stem:intro'], $backend->warmCalls, 'neither the silent stem nor the manager\'s other clips');
    }

    public function testStemPreloadWithNoStemWarmsNothing(): void
    {
        $backend = new WarmSpyBackend();
        $audio = new AudioManager($backend);
        $audio->loadClip('sfx', 'sfx.ogg');
        (new StemPlayer($audio))->preload();
        self::assertSame([], $backend->warmCalls);
    }

    #[RequiresFunction('vio_audio_load')]
    public function testVioBackendOpensAheadAndPlaysTheReadySound(): void
    {
        self::offlineEngine();
        $wav = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpolygon-warm-' . getmypid() . '.wav';
        $pcm = '';
        for ($i = 0; $i < 4410; $i++) {
            $pcm .= pack('v', (int) round(8000 * sin(2 * M_PI * 441 * $i / 44100)) & 0xFFFF);
        }
        file_put_contents($wav, WavWriter::wrap($pcm, 1, 44100));
        try {
            $backend = new VioAudioBackend(false);
            self::assertInstanceOf(AudioBackendInterface::class, $backend);
            $audio = new AudioManager($backend);
            $audio->loadClip('tone', $wav);
            self::assertSame(0, $backend->warmCount('tone'));

            self::assertSame(1, $audio->warm('tone'));
            self::assertSame(1, $backend->warmCount('tone'), 'opened ahead of the first play');
            self::assertSame(0, $audio->warm('tone'), 'already ready');

            self::assertTrue($backend->warm('tone', 3));
            self::assertSame(3, $backend->warmCount('tone'));

            $id = $audio->playSfx('tone');
            self::assertGreaterThan(0, $id, 'a ready sound plays');
            self::assertSame(2, $backend->warmCount('tone'), 'one ready instance was used');

            self::assertFalse($backend->warm('nope'), 'an unknown clip cannot be warmed');
            $audio->loadClip('tone', $wav); // re-loading the id drops instances opened from the old path
            self::assertSame(0, $backend->warmCount('tone'));
            $backend->dispose();
        } finally {
            @unlink($wav);
        }
    }

    #[RequiresFunction('vio_audio_load')]
    public function testVioBackendDoesNotWarmAMissingFile(): void
    {
        self::offlineEngine();
        $backend = new VioAudioBackend(false);
        $backend->load('ghost', sys_get_temp_dir() . '/phpolygon-no-such-file.wav');
        self::assertFalse($backend->warm('ghost'));
        self::assertSame(0, $backend->warmCount('ghost'));
    }
}
