<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio;

use PHPUnit\Framework\TestCase;
use PHPolygon\Audio\AudioChannel;
use PHPolygon\Audio\AudioManager;

class AudioDuckTest extends TestCase
{
    private SpyAudioBackend $backend;
    private AudioManager $audio;

    protected function setUp(): void
    {
        $this->backend = new SpyAudioBackend();
        $this->audio = new AudioManager($this->backend);
    }

    private static function gain(float $db): float
    {
        return 10.0 ** ($db / 20.0);
    }

    public function testImmediateDuckLowersPlayingVoicesWithoutTouchingChannelVolume(): void
    {
        $this->audio->setChannelVolume(AudioChannel::Music, 0.8);
        $id = $this->audio->playMusic('theme', 0.5);

        $this->audio->setChannelDuck(AudioChannel::Music, -6.0);

        $this->assertEqualsWithDelta(0.5 * 0.8 * self::gain(-6.0), $this->backend->volumes[$id], 1e-9);
        $this->assertSame(0.8, $this->audio->getChannelVolume(AudioChannel::Music));
        $this->assertSame(-6.0, $this->audio->getChannelDuck(AudioChannel::Music));
    }

    public function testNewVoicesStartDucked(): void
    {
        $this->audio->setChannelDuck(AudioChannel::Music, -12.0);
        $id = $this->audio->playMusic('theme');

        $this->assertEqualsWithDelta(self::gain(-12.0), $this->backend->volumes[$id], 1e-9);
    }

    public function testOtherChannelsAreUnaffected(): void
    {
        $sfx = $this->audio->playSfx('click', 0.7);
        $this->audio->setChannelDuck(AudioChannel::Music, -20.0);

        $this->assertEqualsWithDelta(0.7, $this->backend->volumes[$sfx], 1e-9);
    }

    public function testStrongestSourceWins_andReleaseFallsBackToTheNext(): void
    {
        $id = $this->audio->playMusic('theme');
        $this->audio->setChannelDuck(AudioChannel::Music, -6.0, source: 'speech');
        $this->audio->setChannelDuck(AudioChannel::Music, -15.0, source: 'focus');

        $this->assertSame(-15.0, $this->audio->getChannelDuck(AudioChannel::Music));

        $this->audio->releaseChannelDuck(AudioChannel::Music, 'focus');
        $this->assertSame(-6.0, $this->audio->getChannelDuck(AudioChannel::Music));
        $this->assertTrue($this->audio->hasChannelDuck(AudioChannel::Music, 'speech'));
        $this->assertFalse($this->audio->hasChannelDuck(AudioChannel::Music, 'focus'));

        $this->audio->releaseChannelDuck(AudioChannel::Music, 'speech');
        $this->assertSame(0.0, $this->audio->getChannelDuck(AudioChannel::Music));
        $this->assertEqualsWithDelta(1.0, $this->backend->volumes[$id], 1e-9);
    }

    public function testRampIsLinearInDbAndDrivenByUpdate(): void
    {
        $id = $this->audio->playMusic('theme');
        $this->audio->setChannelDuck(AudioChannel::Music, -12.0, 1.0);

        // Nothing happens before the first tick.
        $this->assertSame(0.0, $this->audio->getChannelDuck(AudioChannel::Music));
        $this->assertSame(-12.0, $this->audio->getChannelDuckTarget(AudioChannel::Music));

        $this->audio->update(0.25);
        $this->assertEqualsWithDelta(-3.0, $this->audio->getChannelDuck(AudioChannel::Music), 1e-9);
        $this->assertEqualsWithDelta(self::gain(-3.0), $this->backend->volumes[$id], 1e-9);

        $this->audio->update(1.0);
        $this->assertSame(-12.0, $this->audio->getChannelDuck(AudioChannel::Music));
    }

    public function testReleaseRampsBackFromTheCurrentLevel(): void
    {
        $this->audio->setChannelDuck(AudioChannel::Music, -12.0, 1.0, 'speech');
        $this->audio->update(0.5); // -6 dB, ramp still running

        $this->audio->releaseChannelDuck(AudioChannel::Music, 'speech', 0.5);
        $this->audio->update(0.25);
        $this->assertEqualsWithDelta(-3.0, $this->audio->getChannelDuck(AudioChannel::Music), 1e-9);

        $this->audio->update(0.25);
        $this->assertSame(0.0, $this->audio->getChannelDuck(AudioChannel::Music));
    }

    public function testChannelVolumeChangeKeepsTheDuck(): void
    {
        $id = $this->audio->playMusic('theme');
        $this->audio->setChannelDuck(AudioChannel::Music, -6.0);

        $this->audio->setChannelVolume(AudioChannel::Music, 0.5);
        $this->audio->setMasterVolume(0.5);

        $this->assertEqualsWithDelta(0.25 * self::gain(-6.0), $this->backend->volumes[$id], 1e-9);
    }

    public function testDuckingMasterLowersEveryChannelOnce(): void
    {
        $music = $this->audio->playMusic('theme');
        $voice = $this->audio->playOnChannel('line', AudioChannel::Voice, 0.5);
        $this->audio->setChannelDuck(AudioChannel::Master, -6.0);
        $this->audio->setChannelDuck(AudioChannel::Voice, -6.0);

        $this->assertEqualsWithDelta(self::gain(-6.0), $this->backend->volumes[$music], 1e-9);
        $this->assertEqualsWithDelta(0.5 * self::gain(-12.0), $this->backend->volumes[$voice], 1e-9);
    }

    public function testFloorSilencesAndPositiveIsClamped(): void
    {
        $id = $this->audio->playMusic('theme');
        $this->audio->setChannelDuck(AudioChannel::Music, -200.0);
        $this->assertSame(0.0, $this->backend->volumes[$id]);
        $this->assertSame(AudioManager::DUCK_FLOOR_DB, $this->audio->getChannelDuck(AudioChannel::Music));

        $this->audio->setChannelDuck(AudioChannel::Music, 6.0);
        $this->assertSame(0.0, $this->audio->getChannelDuck(AudioChannel::Music));
        $this->assertEqualsWithDelta(1.0, $this->backend->volumes[$id], 1e-9);
    }

    public function testMuteStillWinsAndUnmuteRestoresTheDuckedLevel(): void
    {
        $id = $this->audio->playMusic('theme');
        $this->audio->setChannelDuck(AudioChannel::Music, -6.0);
        $this->audio->muteChannel(AudioChannel::Music);
        $this->assertSame(0.0, $this->backend->volumes[$id]);

        $this->audio->unmuteChannel(AudioChannel::Music);
        $this->assertEqualsWithDelta(self::gain(-6.0), $this->backend->volumes[$id], 1e-9);
    }

    public function testClearChannelDucks(): void
    {
        $this->audio->setChannelDuck(AudioChannel::Music, -6.0, source: 'a');
        $this->audio->setChannelDuck(AudioChannel::SFX, -6.0, source: 'b');

        $this->audio->clearChannelDucks(AudioChannel::Music);
        $this->assertFalse($this->audio->hasChannelDuck(AudioChannel::Music));
        $this->assertTrue($this->audio->hasChannelDuck(AudioChannel::SFX));

        $this->audio->clearChannelDucks();
        $this->assertSame(0.0, $this->audio->getChannelDuck(AudioChannel::SFX));
    }

    public function testUpdateWithoutRampsTouchesNoVoice(): void
    {
        $id = $this->audio->playMusic('theme');
        $this->audio->update(0.1);
        $this->assertArrayNotHasKey($id, $this->backend->setVolumeCalls);
    }
}
