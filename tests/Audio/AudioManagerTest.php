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
}
