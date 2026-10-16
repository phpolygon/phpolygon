<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio;

use PHPolygon\Audio\AudioChannel;
use PHPolygon\Audio\AudioClip;
use PHPolygon\Audio\AudioManager;
use PHPolygon\Audio\Dsp\GainFollower;
use PHPolygon\Audio\Dsp\Pcm;
use PHPolygon\Audio\StemPlayer;
use PHPUnit\Framework\TestCase;

final class StemPlayerTest extends TestCase
{
    private SpyAudioBackend $backend;
    private AudioManager $audio;
    private StemPlayer $player;

    protected function setUp(): void
    {
        $this->backend = new SpyAudioBackend();
        $this->audio = new AudioManager($this->backend);
        $this->player = new StemPlayer($this->audio);
        $this->player->addStem('intro', 'music/intro.ogg');
        $this->player->addStem('main', 'music/main.ogg');
    }

    public function testStartsAStemWhenItsTimeComesAndFadesItIn(): void
    {
        $this->player->schedule()->start('intro', 1.0, fadeIn: 2.0, gain: 0.5);

        $this->player->update(0.0);
        $this->player->update(0.5);
        self::assertNull($this->player->playback('intro'));

        $this->player->update(1.0);
        $id = $this->player->playback('intro');
        self::assertNotNull($id);
        self::assertSame('stem:intro', $this->audio->getClip('stem:intro')?->id);

        $this->player->update(2.0);
        self::assertEqualsWithDelta(0.25, $this->backend->volumes[$id], 1e-12);
        $this->player->update(3.0);
        self::assertEqualsWithDelta(0.5, $this->player->volumes()['intro'], 1e-12);
    }

    public function testCrossfadeEndsTheOldStem(): void
    {
        $schedule = $this->player->schedule();
        $schedule->start('intro', 0.0, 0.0, 0.4);
        $this->player->update(0.0);
        $intro = $this->player->playback('intro');
        self::assertNotNull($intro);

        $schedule->crossfade('intro', 'main', 1.0, 0.5, gain: 0.4);
        $this->player->update(1.0);
        $main = $this->player->playback('main');
        self::assertNotNull($main);

        $this->player->update(1.25);
        self::assertEqualsWithDelta(0.2, $this->backend->volumes[$intro], 1e-12);
        self::assertEqualsWithDelta(0.2, $this->backend->volumes[$main], 1e-12);

        $this->player->update(1.5);
        self::assertNull($this->player->playback('intro'));
        self::assertFalse($this->backend->isPlaying($intro));
        self::assertEqualsWithDelta(0.4, $this->backend->volumes[$main], 1e-12);
    }

    public function testHardCutSilencesWithinItsLength(): void
    {
        $schedule = $this->player->schedule();
        $schedule->start('intro', 0.0, 0.0, 1.0);
        $schedule->start('main', 0.0, 0.0, 1.0);
        $this->player->update(0.0);
        $intro = $this->player->playback('intro');
        self::assertNotNull($intro);

        $schedule->cut(2.0, 0.06);
        $this->player->update(2.03);
        self::assertEqualsWithDelta(0.5, $this->backend->volumes[$intro], 1e-9);

        $this->player->update(2.06);
        self::assertFalse($this->player->isPlaying());
        self::assertFalse($this->backend->isPlaying($intro));
    }

    public function testDuckFollowsItsRampsOnThePlayerClock(): void
    {
        $player = new StemPlayer($this->audio, AudioChannel::Music, new GainFollower(0.3, 0.6, -8.0));
        $player->addStem('bed', 'bed.ogg');
        $player->schedule()->start('bed', 0.0, 0.0, 0.5);
        $player->update(0.0);
        $id = $player->playback('bed');
        self::assertNotNull($id);

        $player->duck(-8.0);
        $player->update(0.15);
        $half = 1.0 - (1.0 - Pcm::db(-8.0)) / 2.0;
        self::assertEqualsWithDelta(0.5 * $half, $this->backend->volumes[$id], 1e-12);

        $player->update(0.3);
        self::assertEqualsWithDelta(0.5 * Pcm::db(-8.0), $this->backend->volumes[$id], 1e-12);

        $player->duck(0.0);
        $player->update(0.5);
        $player->update(0.7);
        $player->update(0.9);
        self::assertEqualsWithDelta(0.5, $this->backend->volumes[$id], 1e-12);
    }

    public function testPerStemDuckLeavesOtherStems(): void
    {
        $schedule = $this->player->schedule();
        $schedule->start('intro', 0.0, 0.0, 1.0);
        $schedule->start('main', 0.0, 0.0, 1.0);
        $this->player->update(0.0);

        $this->player->duck(-6.0, 'main'); // instant follower by default
        $this->player->update(0.1);

        self::assertSame(1.0, $this->player->volumes()['intro']);
        self::assertEqualsWithDelta(Pcm::db(-6.0), $this->player->volumes()['main'], 1e-12);
    }

    public function testStemsSitUnderTheManagersChannelVolumeAndDuck(): void
    {
        $this->audio->setChannelVolume(AudioChannel::Music, 0.5);
        $this->audio->setChannelDuck(AudioChannel::Music, -6.0);
        $this->player->schedule()->start('intro', 0.0, 0.0, 0.8);
        $this->player->update(0.0);
        $id = $this->player->playback('intro');
        self::assertNotNull($id);

        self::assertEqualsWithDelta(0.8 * 0.5 * Pcm::db(-6.0), $this->backend->volumes[$id], 1e-9);
        // The player reports its own volume, before channel and duck.
        self::assertSame(0.8, $this->player->volumes()['intro']);
    }

    public function testReleaseHandsSoundingStemsToANewOwner(): void
    {
        $schedule = $this->player->schedule();
        $schedule->start('intro', 0.0, 0.0, 0.6);
        $schedule->start('main', 5.0, 1.0, 0.6); // not yet started
        $this->player->update(0.0);
        $this->player->update(1.0);
        $intro = $this->player->playback('intro');
        self::assertNotNull($intro);

        $handoffs = $this->player->release(2.0);
        self::assertCount(1, $handoffs);
        self::assertSame('intro', $handoffs[0]->stem);
        self::assertSame($intro, $handoffs[0]->playback);
        self::assertSame(0.6, $handoffs[0]->volume);
        self::assertSame(2.0, $handoffs[0]->fade);
        self::assertSame(AudioChannel::Music, $handoffs[0]->channel);

        // The released player no longer drives or starts anything.
        $this->player->update(6.0);
        self::assertNull($this->player->playback('main'));
        self::assertTrue($this->backend->isPlaying($intro));

        // The new owner fades it out on its own clock.
        $owner = new StemPlayer($this->audio);
        $owner->adopt($handoffs[0]);
        for ($t = 10.0; $t <= 11.0; $t += 0.25) {
            $owner->update($t);
        }
        self::assertEqualsWithDelta(0.3, $this->backend->volumes[$intro], 1e-12);
        self::assertTrue($owner->isPlaying());
        for ($t = 11.25; $t <= 12.0; $t += 0.25) {
            $owner->update($t);
        }
        self::assertFalse($this->backend->isPlaying($intro));
        self::assertFalse($owner->isPlaying());
    }

    public function testReleaseStopsSilentStems(): void
    {
        $this->player->schedule()->start('intro', 0.0, fadeIn: 1.0, gain: 1.0);
        $this->player->update(0.0); // starts at volume 0
        $intro = $this->player->playback('intro');
        self::assertNotNull($intro);

        self::assertSame([], $this->player->release(1.0));
        self::assertFalse($this->backend->isPlaying($intro));
    }

    public function testMissingFileIsASilentStemReportedOnce(): void
    {
        $problems = [];
        $player = new StemPlayer($this->audio, onProblem: function (string $m) use (&$problems): void {
            $problems[] = $m;
        });
        $player->addStem('ghost', null);
        $player->schedule()->start('ghost', 0.0);
        $player->update(0.0);
        $player->update(0.1);

        self::assertNull($player->playback('ghost'));
        self::assertCount(1, $problems);
    }

    public function testFailedLoadAndPlayAreSilent(): void
    {
        $audio = new class ($this->backend) extends AudioManager {
            public function loadClip(string $id, string $path): AudioClip
            {
                throw new \RuntimeException('decode failed');
            }
        };
        $problems = [];
        $player = new StemPlayer($audio, onProblem: function (string $m) use (&$problems): void {
            $problems[] = $m;
        });
        $player->addStem('broken', 'broken.ogg');
        $player->preload();
        $player->schedule()->start('broken', 0.0);
        $player->update(0.0);
        self::assertNull($player->playback('broken'));
        self::assertCount(1, $problems);

        $this->backend->failPlays = true;
        $this->player->schedule()->start('intro', 0.0);
        $this->player->update(0.0);
        self::assertNull($this->player->playback('intro'));
    }

    public function testLongStallDoesNotSkipTheDuckRamp(): void
    {
        $player = new StemPlayer($this->audio, AudioChannel::Music, new GainFollower(1.0, 1.0, -12.0));
        $player->addStem('bed', 'bed.ogg');
        $player->schedule()->start('bed', 0.0);
        $player->update(0.0);
        $player->duck(-12.0);
        $player->update(5.0); // a 5 s hitch counts as MAX_STEP

        $expected = 1.0 - (1.0 - Pcm::db(-12.0)) * StemPlayer::MAX_STEP;
        self::assertEqualsWithDelta($expected, $player->duckGain(), 1e-12);
    }

    public function testResetStartsOver(): void
    {
        $this->player->schedule()->start('intro', 0.0);
        $this->player->update(0.0);
        $first = $this->player->playback('intro');
        self::assertNotNull($first);

        $this->player->reset();
        self::assertFalse($this->backend->isPlaying($first));
        self::assertSame([], $this->player->schedule()->stems());

        $this->player->schedule()->start('intro', 0.0);
        $this->player->update(0.0);
        self::assertNotNull($this->player->playback('intro'));
        self::assertNotSame($first, $this->player->playback('intro'));
    }
}
