<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio;

use PHPUnit\Framework\TestCase;
use PHPolygon\Audio\AudioBackendInterface;
use PHPolygon\Audio\AudioChannel;
use PHPolygon\Audio\AudioManager;
use PHPolygon\Audio\Backend\PHPGLFWAudioBackend;
use PHPolygon\Audio\NullAudioBackend;

class AudioManagerTest extends TestCase
{
    private AudioManager $audio;

    protected function setUp(): void
    {
        $this->audio = new AudioManager(new NullAudioBackend());
    }

    public function testDefaultChannelVolumes(): void
    {
        foreach (AudioChannel::cases() as $channel) {
            $this->assertEquals(1.0, $this->audio->getChannelVolume($channel));
            $this->assertFalse($this->audio->isChannelMuted($channel));
        }
    }

    public function testSetChannelVolume(): void
    {
        $this->audio->setChannelVolume(AudioChannel::SFX, 0.5);
        $this->assertEquals(0.5, $this->audio->getChannelVolume(AudioChannel::SFX));
    }

    public function testSetChannelVolumeClamped(): void
    {
        $this->audio->setChannelVolume(AudioChannel::Music, 2.0);
        $this->assertEquals(1.0, $this->audio->getChannelVolume(AudioChannel::Music));

        $this->audio->setChannelVolume(AudioChannel::Music, -1.0);
        $this->assertEquals(0.0, $this->audio->getChannelVolume(AudioChannel::Music));
    }

    public function testSetMasterVolume(): void
    {
        $this->audio->setMasterVolume(0.7);
        $this->assertEquals(0.7, $this->audio->getMasterVolume());
    }

    public function testMuteUnmute(): void
    {
        $this->audio->muteChannel(AudioChannel::SFX);
        $this->assertTrue($this->audio->isChannelMuted(AudioChannel::SFX));
        $this->assertFalse($this->audio->isChannelMuted(AudioChannel::Music));

        $this->audio->unmuteChannel(AudioChannel::SFX);
        $this->assertFalse($this->audio->isChannelMuted(AudioChannel::SFX));
    }

    public function testPlaySfx(): void
    {
        $this->audio->loadClip('explosion', '/sounds/boom.wav');

        $playbackId = $this->audio->playSfx('explosion');
        $this->assertGreaterThan(0, $playbackId);
    }

    public function testPlayUI(): void
    {
        $this->audio->loadClip('click', '/sounds/click.wav');

        $playbackId = $this->audio->playUI('click');
        $this->assertGreaterThan(0, $playbackId);
    }

    public function testPlayMusicStopsPrevious(): void
    {
        $this->audio->loadClip('track1', '/music/track1.ogg');
        $this->audio->loadClip('track2', '/music/track2.ogg');

        $this->audio->playMusic('track1');
        $this->assertEquals('track1', $this->audio->getCurrentMusicClipId());

        $this->audio->playMusic('track2');
        $this->assertEquals('track2', $this->audio->getCurrentMusicClipId());
    }

    public function testStopMusic(): void
    {
        $this->audio->loadClip('track1', '/music/track1.ogg');

        $this->audio->playMusic('track1');
        $this->assertNotNull($this->audio->getCurrentMusicClipId());

        $this->audio->stopMusic();
        $this->assertNull($this->audio->getCurrentMusicClipId());
    }

    public function testStopAll(): void
    {
        $this->audio->loadClip('a', '/a.wav');
        $this->audio->loadClip('b', '/b.wav');

        $this->audio->playSfx('a');
        $this->audio->playMusic('b');

        $this->audio->stopAll();
        $this->assertNull($this->audio->getCurrentMusicClipId());
    }

    public function testStopChannel(): void
    {
        $this->audio->loadClip('track', '/music/track.ogg');

        $this->audio->playMusic('track');
        $this->assertNotNull($this->audio->getCurrentMusicClipId());

        $this->audio->stopChannel(AudioChannel::Music);
        $this->assertNull($this->audio->getCurrentMusicClipId());
    }

    public function testLoadAndGetClip(): void
    {
        $clip = $this->audio->loadClip('test', '/sounds/test.wav');

        $this->assertEquals('test', $clip->id);
        $this->assertEquals('/sounds/test.wav', $clip->path);

        $retrieved = $this->audio->getClip('test');
        $this->assertSame($clip, $retrieved);
    }

    public function testGetClipReturnsNullForUnknown(): void
    {
        $this->assertNull($this->audio->getClip('nonexistent'));
    }

    public function testGetBackend(): void
    {
        $this->assertInstanceOf(NullAudioBackend::class, $this->audio->getBackend());
    }

    public function testDefaultConstructorUsesNullBackend(): void
    {
        $audio = new AudioManager();
        $this->assertInstanceOf(NullAudioBackend::class, $audio->getBackend());
    }

    public function testDispose(): void
    {
        $this->audio->loadClip('a', '/a.wav');
        $this->audio->playSfx('a');
        $this->audio->playMusic('a');

        $this->audio->dispose();

        $this->assertNull($this->audio->getCurrentMusicClipId());
        $this->assertNull($this->audio->getClip('a'));
    }

    // ── PHPGLFWAudioBackend ─────────────────────────────────────

    public function testPHPGLFWBackendImplementsInterface(): void
    {
        $this->assertTrue(
            is_subclass_of(PHPGLFWAudioBackend::class, AudioBackendInterface::class)
            || in_array(AudioBackendInterface::class, class_implements(PHPGLFWAudioBackend::class) ?: []),
            'PHPGLFWAudioBackend must implement AudioBackendInterface'
        );
    }

    public function testPHPGLFWBackendIsAvailableReturnsBool(): void
    {
        $this->assertIsBool(PHPGLFWAudioBackend::isAvailable());
    }

    public function testManagerAcceptsBackendViaConstructor(): void
    {
        // NullAudioBackend is always available; just verify the pattern works
        $backend = new NullAudioBackend();
        $manager = new AudioManager($backend);

        $this->assertSame($backend, $manager->getBackend());
    }

    // ── Effective volume reaching the backend ───────────────────

    public function testMasterVolumeIsAppliedExactlyOnce(): void
    {
        $spy = new SpyAudioBackend();
        $audio = new AudioManager($spy);

        $audio->setMasterVolume(0.5);
        $id = $audio->playSfx('boom', 0.8);

        $this->assertEqualsWithDelta(0.4, $spy->volumes[$id], 1e-6);
        // Master is folded into the voice volume; the backend's own output
        // gain must stay neutral or master would be applied a second time.
        $this->assertEqualsWithDelta(1.0, $spy->getMasterVolume(), 1e-6);
    }

    public function testChangingMasterKeepsEachVoiceVolume(): void
    {
        $spy = new SpyAudioBackend();
        $audio = new AudioManager($spy);

        $sfx = $audio->playSfx('boom', 0.5);
        $music = $audio->playMusic('track', 0.25);

        $audio->setMasterVolume(0.8);

        $this->assertEqualsWithDelta(0.4, $spy->volumes[$sfx], 1e-6);
        $this->assertEqualsWithDelta(0.2, $spy->volumes[$music], 1e-6);
    }

    public function testChangingChannelVolumeKeepsVoiceVolume(): void
    {
        $spy = new SpyAudioBackend();
        $audio = new AudioManager($spy);

        $id = $audio->playOnChannel('wind', AudioChannel::Voice, 0.3, true);
        $audio->setChannelVolume(AudioChannel::Voice, 0.5);

        $this->assertEqualsWithDelta(0.15, $spy->volumes[$id], 1e-6);
    }

    public function testChannelVolumeUsesLatestPlaybackVolume(): void
    {
        $spy = new SpyAudioBackend();
        $audio = new AudioManager($spy);

        $id = $audio->playOnChannel('wind', AudioChannel::Voice, 1.0, true);
        $audio->setPlaybackVolume($id, 0.4);
        $audio->setChannelVolume(AudioChannel::Voice, 0.5);

        $this->assertEqualsWithDelta(0.2, $spy->volumes[$id], 1e-6);
    }

    public function testUnmuteRestoresVoiceVolume(): void
    {
        $spy = new SpyAudioBackend();
        $audio = new AudioManager($spy);

        $id = $audio->playSfx('boom', 0.6);
        $audio->muteChannel(AudioChannel::SFX);
        $this->assertEqualsWithDelta(0.0, $spy->volumes[$id], 1e-6);

        $audio->unmuteChannel(AudioChannel::SFX);
        $this->assertEqualsWithDelta(0.6, $spy->volumes[$id], 1e-6);
    }

    public function testChannelChangeLeavesOtherChannelsAlone(): void
    {
        $spy = new SpyAudioBackend();
        $audio = new AudioManager($spy);

        $sfx = $audio->playSfx('boom', 0.6);
        $audio->playMusic('track', 0.5);
        $spy->setVolumeCalls = [];

        $audio->setChannelVolume(AudioChannel::Music, 0.1);

        $this->assertArrayNotHasKey($sfx, $spy->setVolumeCalls);
    }

    public function testFinishedVoicesArePrunedOnChannelChange(): void
    {
        $spy = new SpyAudioBackend();
        $audio = new AudioManager($spy);

        $done = $audio->playSfx('boom', 0.5);
        $running = $audio->playSfx('boom', 0.5);
        $spy->finish($done);
        $spy->setVolumeCalls = [];

        $audio->setChannelVolume(AudioChannel::SFX, 0.5);

        $this->assertArrayNotHasKey($done, $spy->setVolumeCalls);
        $this->assertArrayHasKey($running, $spy->setVolumeCalls);
        $this->assertSame(1, $audio->getTrackedPlaybackCount());
    }

    public function testFireAndForgetVoicesDoNotAccumulate(): void
    {
        $spy = new SpyAudioBackend();
        $audio = new AudioManager($spy);

        for ($i = 0; $i < 1000; $i++) {
            $spy->finish($audio->playSfx('click'));
        }

        $this->assertLessThan(200, $audio->getTrackedPlaybackCount());
    }

    public function testFailedPlayIsNotTracked(): void
    {
        $spy = new SpyAudioBackend();
        $spy->failPlays = true;
        $audio = new AudioManager($spy);

        $this->assertSame(0, $audio->playSfx('missing'));
        $this->assertSame(0, $audio->getTrackedPlaybackCount());
    }
}
