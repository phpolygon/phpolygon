<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio;

use InvalidArgumentException;
use PHPolygon\Audio\AudioChannel;
use PHPolygon\Audio\AudioManager;
use PHPolygon\Audio\NullAudioBackend;
use PHPolygon\Audio\PlaybackOptions;
use PHPolygon\Audio\SimulatedAudioBackend;
use PHPolygon\Audio\Spatial\Attenuation;
use PHPolygon\Audio\Spatial\AttenuationCurve;
use PHPolygon\Audio\Spatial\AudioListener;
use PHPolygon\Audio\Spatial\SoundCone;
use PHPolygon\Audio\Spatial\SpatialSource;
use PHPolygon\Math\Vec3;
use PHPUnit\Framework\TestCase;

/**
 * AudioManager on a native spatial backend (php-vio ≥ 2.36 semantics),
 * simulated, plus the fallback behaviour of the new controls.
 */
final class NativeSpatialAudioManagerTest extends TestCase
{
    private SimulatedAudioBackend $backend;
    private AudioManager $audio;

    protected function setUp(): void
    {
        $this->backend = new SimulatedAudioBackend(nativeSpatial: true);
        $this->audio = new AudioManager($this->backend);
    }

    /** @return array{clip: string, volume: float, loop: bool, pitch: float, pan: float, direction: array{float, float, float}, paused: bool, time: float, spatial: bool, source: ?SpatialSource} */
    private function voice(int $id): array
    {
        $v = $this->backend->voice($id);
        $this->assertNotNull($v, "voice {$id} is gone");
        return $v;
    }

    private function source(int $id): SpatialSource
    {
        $s = $this->voice($id)['source'];
        $this->assertNotNull($s);
        return $s;
    }

    public function testNativeIsDetectedOnlyWhenTheBackendSaysSo(): void
    {
        $this->assertTrue($this->audio->hasNativeSpatial());
        $this->assertFalse((new AudioManager(new SimulatedAudioBackend()))->hasNativeSpatial());
        $this->assertFalse((new AudioManager(new NullAudioBackend()))->hasNativeSpatial());
    }

    public function testTwoDimensionalVoicesBypassTheSpatializer(): void
    {
        $id = $this->audio->playWith('ui', AudioChannel::UI, new PlaybackOptions(0.5, pan: 0.3));
        $v = $this->voice($id);
        $this->assertFalse($v['spatial']);
        $this->assertNull($v['source']);
        $this->assertSame(0.3, $v['pan']);
        $plain = $this->audio->playMusic('theme');
        $this->assertFalse($this->voice($plain)['spatial']);
    }

    public function testThreeDimensionalVoicesGetTheirWorldPlacementAndNoEngineDistanceGain(): void
    {
        $att = new Attenuation(0.0, 10.0, AttenuationCurve::Linear);
        $id = $this->audio->playAt('hum', new Vec3(5.0, 0.0, 0.0), volume: 0.8, attenuation: $att);
        $v = $this->voice($id);
        $this->assertTrue($v['spatial']);
        $this->assertSame(0.8, $v['volume'], 'distance is the backend\'s job');
        $this->assertSame([0.0, 0.0, 0.0], $v['direction']);
        $source = $this->source($id);
        $this->assertTrue($source->position->equals(new Vec3(5.0, 0.0, 0.0)));
        $this->assertSame($att, $source->attenuation);
        $this->assertSame(1.0, $source->doppler);
        $this->assertTrue($source->velocity->equals(new Vec3()));
        $this->assertEqualsWithDelta(0.5, $this->audio->getPlaybackDistanceGain($id), 1e-9);
    }

    public function testOutOfRangeVoicesAreSilencedByTheManager(): void
    {
        $att = new Attenuation(1.0, 20.0, AttenuationCurve::Inverse);
        $id = $this->audio->playAt('hum', new Vec3(0.0, 0.0, -25.0), volume: 0.6, attenuation: $att);
        $this->assertSame(0.0, $this->voice($id)['volume']);
        $this->audio->setPlaybackPosition($id, new Vec3(0.0, 0.0, -4.0));
        $this->assertSame(0.6, $this->voice($id)['volume']);
        $this->assertEqualsWithDelta(0.25, $this->audio->getPlaybackDistanceGain($id), 1e-9);
        // The listener walks away: out of range again, without touching the source.
        $this->audio->setListener(new AudioListener(new Vec3(0.0, 0.0, 30.0)));
        $this->assertSame(0.0, $this->voice($id)['volume']);

        // None never cuts.
        $flat = $this->audio->playAt('hum', new Vec3(0.0, 0.0, -500.0), volume: 0.6, attenuation: new Attenuation(curve: AttenuationCurve::None));
        $this->assertSame(0.6, $this->voice($flat)['volume']);
    }

    public function testListenerPoseReachesTheBackend(): void
    {
        $this->audio->setListener(new AudioListener(new Vec3(1.0, 2.0, 3.0), new Vec3(1.0, 0.0, 0.0)));
        $l = $this->backend->listener();
        $this->assertNotNull($l);
        $this->assertTrue($l->position->equals(new Vec3(1.0, 2.0, 3.0)));
        $this->assertTrue($l->forward->equals(new Vec3(1.0, 0.0, 0.0)));
        $this->assertNotNull($l->velocity);
        $this->assertTrue($l->velocity->equals(new Vec3()), 'first pose: no motion yet');
    }

    public function testListenerVelocityIsDerivedPerTickAndSettles(): void
    {
        $this->audio->setListener(new AudioListener(new Vec3()));
        $this->audio->update(0.2);
        $this->audio->setListener(new AudioListener(new Vec3(0.4, 0.0, 0.0)));
        $this->assertTrue($this->backend->listener()?->velocity?->equals(new Vec3(2.0, 0.0, 0.0)) ?? false);

        // A second pose in the same tick keeps the velocity.
        $this->audio->setListener(new AudioListener(new Vec3(0.5, 0.0, 0.0)));
        $this->assertTrue($this->audio->getListener()->velocity?->equals(new Vec3(2.0, 0.0, 0.0)) ?? false);

        $this->audio->update(0.1); // moved this tick: kept
        $this->assertTrue($this->audio->getListener()->velocity?->equals(new Vec3(2.0, 0.0, 0.0)) ?? false);
        $this->audio->update(0.1); // not moved: at rest
        $this->assertTrue($this->backend->listener()?->velocity?->equals(new Vec3()) ?? false);
    }

    public function testListenerTeleportAndGapsGiveNoVelocity(): void
    {
        $this->audio->setListener(new AudioListener(new Vec3()));
        $this->audio->update(0.1);
        $this->audio->setListener(new AudioListener(new Vec3(100.0, 0.0, 0.0))); // 1000 u/s
        $this->assertTrue($this->audio->getListener()->velocity?->equals(new Vec3()) ?? false);

        $this->audio->update(1.0); // a long pause between two poses
        $this->audio->setListener(new AudioListener(new Vec3(101.0, 0.0, 0.0)));
        $this->assertTrue($this->audio->getListener()->velocity?->equals(new Vec3()) ?? false);

        // An explicit velocity is taken as given and never settled.
        $this->audio->setListener(new AudioListener(new Vec3(), velocity: new Vec3(0.0, 0.0, -7.0)));
        $this->audio->update(0.1);
        $this->audio->update(0.1);
        $this->assertTrue($this->backend->listener()?->velocity?->equals(new Vec3(0.0, 0.0, -7.0)) ?? false);
    }

    public function testSourceVelocityIsDerivedFromPositionUpdates(): void
    {
        $id = $this->audio->playAt('car', new Vec3(0.0, 0.0, -50.0), loop: true);
        $this->audio->update(0.1);
        $this->audio->setPlaybackPosition($id, new Vec3(0.0, 0.0, -48.0));
        $this->assertTrue($this->source($id)->velocity->equals(new Vec3(0.0, 0.0, 20.0)));
        $this->assertTrue($this->audio->getPlaybackVelocity($id)?->equals(new Vec3(0.0, 0.0, 20.0)) ?? false);
        $c = AudioManager::SPEED_OF_SOUND;
        $this->assertEqualsWithDelta($c / ($c - 20.0), $this->backend->dopplerPitch($id), 1e-9);

        $this->audio->update(0.1); // moved last tick: kept
        $this->assertTrue($this->source($id)->velocity->equals(new Vec3(0.0, 0.0, 20.0)));
        $this->audio->update(0.1); // stopped: at rest
        $this->assertTrue($this->source($id)->velocity->equals(new Vec3()));
        $this->assertSame(1.0, $this->backend->dopplerPitch($id));

        // Teleport: no velocity.
        $this->audio->update(0.1);
        $this->audio->setPlaybackPosition($id, new Vec3(0.0, 0.0, 500.0));
        $this->assertTrue($this->source($id)->velocity->equals(new Vec3()));
    }

    public function testExplicitVelocityWinsUntilHandedBack(): void
    {
        $id = $this->audio->playWith('car', AudioChannel::SFX, new PlaybackOptions(
            loop: true, position: new Vec3(0.0, 0.0, -10.0), velocity: new Vec3(3.0, 0.0, 0.0),
        ));
        $this->assertTrue($this->source($id)->velocity->equals(new Vec3(3.0, 0.0, 0.0)));
        $this->audio->update(0.1);
        $this->audio->update(0.1);
        $this->audio->setPlaybackPosition($id, new Vec3(0.0, 0.0, -9.0));
        $this->assertTrue($this->source($id)->velocity->equals(new Vec3(3.0, 0.0, 0.0)), 'not derived, not settled');

        $this->audio->setPlaybackPosition($id, new Vec3(0.0, 0.0, -8.0), new Vec3(0.0, 1.0, 0.0));
        $this->assertTrue($this->source($id)->velocity->equals(new Vec3(0.0, 1.0, 0.0)));

        $this->audio->setPlaybackVelocity($id, null);
        $this->assertTrue($this->source($id)->velocity->equals(new Vec3()));
        $this->audio->update(0.1);
        $this->audio->setPlaybackPosition($id, new Vec3(0.0, 0.0, -7.0));
        $this->assertTrue($this->source($id)->velocity->equals(new Vec3(0.0, 0.0, 10.0)));
    }

    public function testDopplerCanBeSwitchedOffGloballyAndScaledPerVoice(): void
    {
        $id = $this->audio->playWith('car', AudioChannel::SFX, new PlaybackOptions(
            loop: true, position: new Vec3(0.0, 0.0, -10.0), velocity: new Vec3(0.0, 0.0, 30.0), doppler: 0.5,
        ));
        $this->assertSame(0.5, $this->source($id)->doppler);
        $this->audio->setDopplerEnabled(false);
        $this->assertFalse($this->audio->isDopplerEnabled());
        $this->assertSame(0.0, $this->source($id)->doppler);
        $this->assertSame(1.0, $this->backend->dopplerPitch($id));
        $this->audio->setDopplerEnabled(true);
        $this->assertSame(0.5, $this->source($id)->doppler);
        $this->audio->setPlaybackDoppler($id, 2.0);
        $this->assertSame(2.0, $this->source($id)->doppler);
        $this->audio->setPlaybackDoppler($id, -1.0); // ignored
        $this->assertSame(2.0, $this->source($id)->doppler);

        $this->expectException(InvalidArgumentException::class);
        new PlaybackOptions(doppler: -0.1);
    }

    public function testSpeedOfSoundReachesTheBackend(): void
    {
        $this->audio->setSpeedOfSound(100.0);
        $this->assertSame(100.0, $this->audio->getSpeedOfSound());
        $this->assertSame(100.0, $this->backend->getSpeedOfSound());
        // The teleport threshold scales with it: 60 u/s is now a jump.
        $id = $this->audio->playAt('car', new Vec3());
        $this->audio->update(0.1);
        $this->audio->setPlaybackPosition($id, new Vec3(6.0, 0.0, 0.0));
        $this->assertTrue($this->source($id)->velocity->equals(new Vec3()));

        $this->expectException(InvalidArgumentException::class);
        $this->audio->setSpeedOfSound(0.0);
    }

    public function testDirectionAndConeReachTheBackend(): void
    {
        $cone = SoundCone::degrees(60.0, 120.0, 0.25);
        $id = $this->audio->playWith('horn', AudioChannel::SFX, new PlaybackOptions(
            position: new Vec3(0.0, 0.0, -5.0), direction: new Vec3(0.0, 0.0, 1.0), cone: $cone,
        ));
        $this->assertSame($cone, $this->source($id)->cone);
        $this->assertTrue($this->source($id)->direction?->equals(new Vec3(0.0, 0.0, 1.0)) ?? false);
        $this->audio->setPlaybackDirection($id, new Vec3(1.0, 0.0, 0.0));
        $this->assertTrue($this->source($id)->direction?->equals(new Vec3(1.0, 0.0, 0.0)) ?? false);
        $this->assertSame($cone, $this->source($id)->cone, 'a null cone keeps the current one');
        $this->audio->setPlaybackCone($id, null);
        $this->assertNull($this->source($id)->cone);
        $this->assertSame(1.0, $this->voice($id)['volume'], 'native: the cone is not in the volume');
    }

    public function testPitchAndPanChangeLive(): void
    {
        $id = $this->audio->playWith('blip', AudioChannel::SFX, new PlaybackOptions(pitch: 1.2, pan: -0.5));
        $this->audio->setPlaybackPitch($id, 0.75);
        $this->assertSame(0.75, $this->voice($id)['pitch']);
        $this->assertSame(0.75, $this->audio->getPlaybackPitch($id));
        $this->audio->setPlaybackPitch($id, 0.0); // ignored
        $this->assertSame(0.75, $this->voice($id)['pitch']);
        $this->audio->setPlaybackPan($id, 3.0);
        $this->assertSame(1.0, $this->voice($id)['pan']);
        $this->assertSame(1.0, $this->audio->getPlaybackPan($id));

        $spatial = $this->audio->playAt('hum', new Vec3(1.0, 0.0, 0.0));
        $this->audio->setPlaybackPan($spatial, -1.0);
        $this->assertSame(0.0, $this->voice($spatial)['pan'], '3D voices pan by direction');
        $this->audio->setPlaybackPitch($spatial, 1.5);
        $this->assertSame(1.5, $this->voice($spatial)['pitch']);
    }

    public function testATwoDimensionalVoiceBecomesThreeDimensional(): void
    {
        $id = $this->audio->playOnChannel('hum', AudioChannel::SFX, 1.0, true);
        $this->assertFalse($this->voice($id)['spatial']);
        $this->audio->setPlaybackPosition($id, new Vec3(0.0, 0.0, -2.0));
        $this->assertTrue($this->voice($id)['spatial']);
        $this->assertTrue($this->source($id)->position->equals(new Vec3(0.0, 0.0, -2.0)));
    }

    public function testFallbackPathKeepsStartPitchAndAppliesTheConeThroughTheVolume(): void
    {
        $backend = new SimulatedAudioBackend();
        $audio = new AudioManager($backend);
        $id = $audio->playWith('blip', AudioChannel::SFX, new PlaybackOptions(pitch: 1.2));
        $audio->setPlaybackPitch($id, 2.0);
        $this->assertSame(1.2, $backend->voice($id)['pitch'] ?? null, 'old php-vio: pitch is start-only');
        $this->assertSame(2.0, $audio->getPlaybackPitch($id));

        $horn = $audio->playWith('horn', AudioChannel::SFX, new PlaybackOptions(
            position: new Vec3(0.0, 0.0, -5.0),
            attenuation: new Attenuation(0.0, 10.0, AttenuationCurve::Linear),
            direction: new Vec3(0.0, 0.0, -1.0), // facing away from the listener
            cone: SoundCone::degrees(60.0, 120.0, 0.25),
        ));
        $this->assertNull($backend->voice($horn)['source'] ?? null);
        $this->assertEqualsWithDelta(0.125, $backend->voice($horn)['volume'] ?? 0.0, 1e-9); // 0.5 distance × 0.25 cone
        $this->assertEqualsWithDelta(0.5, $audio->getPlaybackDistanceGain($horn), 1e-9);
        $audio->setPlaybackDirection($horn, new Vec3(0.0, 0.0, 1.0));
        $this->assertEqualsWithDelta(0.5, $backend->voice($horn)['volume'] ?? 0.0, 1e-9);
    }
}
