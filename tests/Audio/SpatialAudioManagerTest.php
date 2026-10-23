<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio;

use PHPolygon\Audio\AudioChannel;
use PHPolygon\Audio\AudioManager;
use PHPolygon\Audio\NullAudioBackend;
use PHPolygon\Audio\PlaybackOptions;
use PHPolygon\Audio\SimulatedAudioBackend;
use PHPolygon\Audio\Spatial\Attenuation;
use PHPolygon\Audio\Spatial\AttenuationCurve;
use PHPolygon\Audio\Spatial\AudioListener;
use PHPolygon\Audio\VioAudioBackend;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\System\AudioListenerSystem;
use PHPUnit\Framework\TestCase;

final class SpatialAudioManagerTest extends TestCase
{
    private SimulatedAudioBackend $backend;
    private AudioManager $audio;

    protected function setUp(): void
    {
        $this->backend = new SimulatedAudioBackend();
        $this->audio = new AudioManager($this->backend);
    }

    /** @return array{clip: string, volume: float, loop: bool, pitch: float, pan: float, direction: array{float, float, float}, paused: bool, time: float} */
    private function voice(int $id): array
    {
        $v = $this->backend->voice($id);
        $this->assertNotNull($v, "voice {$id} is gone");
        return $v;
    }

    private function linear(float $max = 10.0): Attenuation
    {
        return new Attenuation(0.0, $max, AttenuationCurve::Linear);
    }

    public function testPitchAndPanReachTheBackend(): void
    {
        $id = $this->audio->playWith('blip', AudioChannel::SFX, new PlaybackOptions(0.5, pitch: 1.5, pan: -0.4));
        $v = $this->voice($id);
        $this->assertSame(1.5, $v['pitch']);
        $this->assertSame(-0.4, $v['pan']);
        $this->assertSame(0.5, $v['volume']);
        $this->assertSame([0.0, 0.0, 0.0], $v['direction']);
        $this->assertFalse($this->audio->isSpatial($id));
    }

    public function testDistanceAttenuatesAndDirectionPans(): void
    {
        $id = $this->audio->playAt('hum', new Vec3(5.0, 0.0, 0.0), volume: 0.8, attenuation: $this->linear());
        $v = $this->voice($id);
        $this->assertEqualsWithDelta(0.4, $v['volume'], 1e-9); // 0.8 × (1 − 5/10)
        $this->assertEqualsWithDelta(1.0, $v['direction'][0], 1e-9); // right
        $this->assertSame(0.0, $v['pan'], 'positioned voices pan by direction, not pan');
        $this->assertEqualsWithDelta(0.5, $this->audio->getPlaybackDistanceGain($id), 1e-9);
    }

    public function testChannelAndMasterStackWithDistance(): void
    {
        $this->audio->setChannelVolume(AudioChannel::SFX, 0.5);
        $this->audio->setMasterVolume(0.5);
        $id = $this->audio->playAt('hum', new Vec3(0.0, 0.0, -5.0), attenuation: $this->linear());
        $this->assertEqualsWithDelta(0.125, $this->voice($id)['volume'], 1e-9);
        $this->audio->setChannelVolume(AudioChannel::SFX, 1.0);
        $this->assertEqualsWithDelta(0.25, $this->voice($id)['volume'], 1e-9);
        $this->audio->setPlaybackVolume($id, 0.5);
        $this->assertEqualsWithDelta(0.125, $this->voice($id)['volume'], 1e-9);
    }

    public function testMovingListenerReattenuatesAndRepans(): void
    {
        $id = $this->audio->playAt('hum', new Vec3(0.0, 0.0, -4.0), attenuation: $this->linear(), loop: true);
        $this->assertEqualsWithDelta(-1.0, $this->voice($id)['direction'][2], 1e-9); // ahead

        // Step 2 m to +X of the source and turn round (facing +Z, right is −X):
        // the source, at −X from the listener, is now on the right.
        $this->audio->setListener(new AudioListener(new Vec3(2.0, 0.0, -4.0), new Vec3(0.0, 0.0, 1.0)));
        $v = $this->voice($id);
        $this->assertEqualsWithDelta(0.8, $v['volume'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $v['direction'][0], 1e-9);

        // Out of range: silent, still alive.
        $this->audio->setListener(new AudioListener(new Vec3(0.0, 0.0, 50.0)));
        $this->assertSame(0.0, $this->voice($id)['volume']);
        $this->assertTrue($this->audio->isPlaying($id));
    }

    public function testMovingSourceIsTracked(): void
    {
        $id = $this->audio->playAt('car', new Vec3(-2.0, 0.0, 0.0), attenuation: $this->linear(), loop: true);
        $this->assertEqualsWithDelta(-1.0, $this->voice($id)['direction'][0], 1e-9);
        $this->audio->setPlaybackPosition($id, new Vec3(0.0, 0.0, 8.0));
        $v = $this->voice($id);
        $this->assertEqualsWithDelta(0.2, $v['volume'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $v['direction'][2], 1e-9); // behind
        $this->assertTrue($this->audio->getPlaybackPosition($id)?->equals(new Vec3(0.0, 0.0, 8.0)) ?? false);

        $this->audio->setPlaybackAttenuation($id, $this->linear(16.0));
        $this->assertEqualsWithDelta(0.5, $this->voice($id)['volume'], 1e-9);
    }

    public function testUnpositionedVoiceCanBecomeSpatial(): void
    {
        $this->audio->setDefaultAttenuation($this->linear(4.0));
        $id = $this->audio->playOnChannel('drone', AudioChannel::SFX, 1.0, true);
        $this->audio->setPlaybackPosition($id, new Vec3(0.0, 2.0, 0.0));
        $this->assertTrue($this->audio->isSpatial($id));
        $this->assertEqualsWithDelta(0.5, $this->voice($id)['volume'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $this->voice($id)['direction'][1], 1e-9);
    }

    public function testSourceOnTheListenerIsCentred(): void
    {
        $id = $this->audio->playAt('hum', new Vec3(), attenuation: $this->linear());
        $this->assertSame([0.0, 0.0, 0.0], $this->voice($id)['direction']);
        $this->assertSame(1.0, $this->voice($id)['volume']);
    }

    public function testPausePerVoiceChannelAndGlobalStack(): void
    {
        $music = $this->audio->playMusic('theme');
        $sfx = $this->audio->playSfx('step');
        $ui = $this->audio->playUI('click');

        $this->audio->pause($sfx);
        $this->assertTrue($this->voice($sfx)['paused']);
        $this->assertTrue($this->audio->isPaused($sfx));

        $this->audio->pauseAll(AudioChannel::UI);
        $this->assertTrue($this->voice($music)['paused']);
        $this->assertFalse($this->voice($ui)['paused'], 'kept channel plays on');

        $this->audio->resumeAll();
        $this->assertFalse($this->voice($music)['paused']);
        $this->assertTrue($this->voice($sfx)['paused'], 'own pause survives resumeAll');

        $this->audio->resume($sfx);
        $this->assertFalse($this->voice($sfx)['paused']);

        $this->audio->pauseChannel(AudioChannel::SFX);
        $this->audio->pause($sfx);
        $this->audio->resumeChannel(AudioChannel::SFX);
        $this->assertTrue($this->voice($sfx)['paused'], 'still paused on its own');
        $this->audio->resume($sfx);
        $this->assertFalse($this->audio->isPaused($sfx));
    }

    public function testMasterPausePausesEverything(): void
    {
        $a = $this->audio->playSfx('a');
        $b = $this->audio->playUI('b');
        $this->audio->pauseChannel(AudioChannel::Master);
        $this->assertTrue($this->voice($a)['paused']);
        $this->assertTrue($this->voice($b)['paused']);
        $this->audio->resumeChannel(AudioChannel::Master);
        $this->assertFalse($this->voice($a)['paused']);
    }

    public function testVoiceStartedOnPausedChannelStartsPausedAtItsLevel(): void
    {
        $this->audio->pauseChannel(AudioChannel::SFX);
        $id = $this->audio->playSfx('late', 0.6);
        $v = $this->voice($id);
        $this->assertTrue($v['paused']);
        $this->assertEqualsWithDelta(0.6, $v['volume'], 1e-9);
        $this->audio->resumeChannel(AudioChannel::SFX);
        $this->assertFalse($this->voice($id)['paused']);
    }

    public function testPausedVoicesAreNotSweptAndDoNotAdvance(): void
    {
        $this->backend->setClipDuration('shot', 1.0);
        $id = $this->audio->playSfx('shot');
        $this->audio->pause($id);
        $this->backend->advance(5.0);
        $this->assertTrue($this->audio->isPlaying($id), 'paused counts as alive');
        $this->audio->setChannelVolume(AudioChannel::SFX, 0.5); // sweeps finished voices
        $this->assertSame(1, $this->audio->getTrackedPlaybackCount());

        $this->audio->resume($id);
        $this->backend->advance(1.0);
        $this->assertFalse($this->audio->isPlaying($id));
    }

    public function testPitchScalesSimulatedPlaybackTime(): void
    {
        $this->backend->setClipDuration('shot', 1.0);
        $fast = $this->audio->playWith('shot', AudioChannel::SFX, new PlaybackOptions(pitch: 2.0));
        $slow = $this->audio->playSfx('shot');
        $this->backend->advance(0.6);
        $this->assertFalse($this->audio->isPlaying($fast));
        $this->assertTrue($this->audio->isPlaying($slow));
    }

    public function testStopForgetsSpatialAndPauseState(): void
    {
        $id = $this->audio->playAt('hum', new Vec3(1.0, 0.0, 0.0), loop: true);
        $this->audio->pause($id);
        $this->audio->stop($id);
        $this->assertFalse($this->audio->isSpatial($id));
        $this->assertFalse($this->audio->isPaused($id));
        $this->assertSame(0, $this->audio->getTrackedPlaybackCount());
        $this->assertSame(0, $this->backend->activeCount());
    }

    public function testFallbackBackendAttenuatesAndEmulatesPauseByMuting(): void
    {
        $spy = new SpyAudioBackend();
        $audio = new AudioManager($spy);
        $id = $audio->playAt('hum', new Vec3(0.0, 0.0, -5.0), volume: 0.8, attenuation: $this->linear(), loop: true);
        $this->assertEqualsWithDelta(0.4, $spy->volumes[$id], 1e-9);

        $audio->pause($id);
        $this->assertSame(0.0, $spy->volumes[$id]);
        $this->assertTrue($audio->isPaused($id));
        $audio->setListener(new AudioListener(new Vec3(0.0, 0.0, -5.0)));
        $this->assertSame(0.0, $spy->volumes[$id], 'stays muted while paused');
        $audio->resume($id);
        $this->assertEqualsWithDelta(0.8, $spy->volumes[$id], 1e-9);
    }

    public function testNullBackendAcceptsEverything(): void
    {
        $audio = new AudioManager(new NullAudioBackend());
        $id = $audio->playAt('x', new Vec3(3.0, 0.0, 0.0), pitch: 0.5);
        $this->assertGreaterThan(0, $id);
        $audio->setPlaybackPosition($id, new Vec3());
        $audio->pauseAll();
        $audio->resumeAll();
        $this->assertFalse($audio->isPlaying($id));
    }

    public function testListenerSystemFollowsTheActiveCamera(): void
    {
        $world = new World();
        $cam = $world->createEntity();
        $cam->attach(new Transform3D(new Vec3(0.0, 0.0, -5.0)));
        $cam->attach(new Camera3DComponent());

        $id = $this->audio->playAt('hum', new Vec3(0.0, 0.0, -5.0), attenuation: $this->linear(), loop: true);
        $this->assertEqualsWithDelta(0.5, $this->voice($id)['volume'], 1e-9);

        (new AudioListenerSystem($this->audio))->update($world, 1.0 / 60.0);
        $this->assertSame(1.0, $this->voice($id)['volume']);
        $this->assertTrue($this->audio->getListener()->position->equals(new Vec3(0.0, 0.0, -5.0)));
    }

    public function testVioDirectionCompensationRestoresTheLouderChannel(): void
    {
        $this->assertSame(1.0, VioAudioBackend::directionCompensation(0.0, 0.0, 0.0));
        // miniaudio's spatializer pans stereo onto SIDE_LEFT/SIDE_RIGHT (±1, 0, 0),
        // measured on php-vio 2.36's offline engine.
        // Ahead: each channel at (1 + 0) / 2.
        $this->assertEqualsWithDelta(2.0, VioAudioBackend::directionCompensation(0.0, 0.0, -1.0), 1e-9);
        // Hard right: the right channel at full level.
        $this->assertEqualsWithDelta(1.0, VioAudioBackend::directionCompensation(1.0, 0.0, 0.0), 1e-9);
        // Behind and above: like ahead.
        $this->assertEqualsWithDelta(2.0, VioAudioBackend::directionCompensation(0.0, 0.0, 1.0), 1e-9);
        $this->assertEqualsWithDelta(2.0, VioAudioBackend::directionCompensation(0.0, 1.0, 0.0), 1e-9);
        // 45° right-front: right channel at (1 + 0.7071) / 2.
        $this->assertEqualsWithDelta(2.0 / 1.7071, VioAudioBackend::directionCompensation(1.0, 0.0, -1.0), 1e-4);
        // Length does not matter.
        $this->assertEqualsWithDelta(
            VioAudioBackend::directionCompensation(0.3, 0.1, -0.5),
            VioAudioBackend::directionCompensation(3.0, 1.0, -5.0),
            1e-12,
        );
    }
}
