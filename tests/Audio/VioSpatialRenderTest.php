<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio;

use PHPolygon\Audio\AudioChannel;
use PHPolygon\Audio\AudioManager;
use PHPolygon\Audio\Codec\WavWriter;
use PHPolygon\Audio\PlaybackOptions;
use PHPolygon\Audio\Spatial\Attenuation;
use PHPolygon\Audio\Spatial\AttenuationCurve;
use PHPolygon\Audio\Spatial\AudioListener;
use PHPolygon\Audio\Spatial\SoundCone;
use PHPolygon\Audio\VioAudioBackend;
use PHPolygon\Math\Vec3;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Measures what php-vio ≥ 2.36 actually plays for AudioManager's voices: an
 * offline engine (no device) is mixed block by block with vio_audio_render(),
 * so level and pitch are deterministic numbers. The tone is a 441 Hz sine
 * (exactly 100 samples per period at 44.1 kHz), looped.
 */
#[RunTestsInSeparateProcesses]
#[RequiresFunction('vio_audio_render')]
#[RequiresFunction('vio_audio_spatial')]
final class VioSpatialRenderTest extends TestCase
{
    private const int RATE = 44100;
    private const float TONE_HZ = 441.0;

    private static string $tone = '';
    private static float $reference = 0.0;

    protected function setUp(): void
    {
        if (!vio_audio_init(['device' => false, 'sample_rate' => self::RATE, 'channels' => 2])) {
            self::markTestSkipped('php-vio offline audio engine did not start (an engine already runs in this process)');
        }
        $pcm = '';
        for ($i = 0; $i < self::RATE; $i++) {
            $pcm .= pack('v', (int) round(16000 * sin(2 * M_PI * self::TONE_HZ * $i / self::RATE)) & 0xFFFF);
        }
        self::$tone = sys_get_temp_dir() . '/phpolygon-vio-spatial-' . getmypid() . '.wav';
        file_put_contents(self::$tone, WavWriter::wrap($pcm, 1, self::RATE));
        // RMS of the clip played as plain 2D sound at volume 1.
        self::$reference = 16000 / 32767 * M_SQRT1_2;
    }

    protected function tearDown(): void
    {
        if (self::$tone !== '' && is_file(self::$tone)) {
            unlink(self::$tone);
        }
    }

    private function manager(?bool $native = null): AudioManager
    {
        // Every test starts with the native listener at rest at the origin.
        vio_audio_listener(0.0, 0.0, 0.0, 0.0, 0.0, -1.0, 0.0, 1.0, 0.0);
        vio_audio_listener_velocity(0.0, 0.0, 0.0);
        vio_audio_speed_of_sound(AudioManager::SPEED_OF_SOUND);
        $audio = new AudioManager(new VioAudioBackend($native));
        $audio->loadClip('tone', self::$tone);
        return $audio;
    }

    /**
     * Render past the gain smoothing, then measure one second.
     *
     * @return array{left: float, right: float, hz: float} levels relative to the 2D reference
     */
    private function measure(): array
    {
        vio_audio_render(4410);
        $raw = vio_audio_render(self::RATE);
        $this->assertIsString($raw);
        $f = array_values(unpack('g*', $raw) ?: []);
        $l = 0.0;
        $r = 0.0;
        $crossings = 0;
        $prev = $f[0] ?? 0.0;
        for ($i = 0, $n = count($f); $i < $n; $i += 2) {
            $l += $f[$i] ** 2;
            $r += $f[$i + 1] ** 2;
            $sample = $f[$i] + $f[$i + 1];
            if ($i > 0 && ($prev < 0.0) !== ($sample < 0.0)) {
                $crossings++;
            }
            $prev = $sample;
        }
        return [
            'left' => sqrt($l / self::RATE) / self::$reference,
            'right' => sqrt($r / self::RATE) / self::$reference,
            'hz' => $crossings / 2.0,
        ];
    }

    private function linear(float $max = 10.0): Attenuation
    {
        return new Attenuation(0.0, $max, AttenuationCurve::Linear);
    }

    public function testNativeSpatialIsDetected(): void
    {
        $this->assertTrue(VioAudioBackend::nativeSpatialAvailable());
        $this->assertTrue($this->manager()->hasNativeSpatial());
        $this->assertFalse($this->manager(false)->hasNativeSpatial());
    }

    public function testTwoDimensionalVoiceIgnoresTheMovingListener(): void
    {
        $audio = $this->manager();
        $id = $audio->playOnChannel('tone', AudioChannel::SFX, 0.5, true);
        $atOrigin = $this->measure();
        $this->assertEqualsWithDelta(0.5, $atOrigin['left'], 0.005);
        $this->assertEqualsWithDelta(0.5, $atOrigin['right'], 0.005);

        $audio->setListener(new AudioListener(new Vec3(400.0, 30.0, -90.0), new Vec3(1.0, 0.0, 0.0)));
        $state = vio_audio_listener_state();
        $this->assertIsArray($state);
        $this->assertEqualsWithDelta(400.0, $state['position'][0], 1e-3, 'the native listener follows');
        $far = $this->measure();
        $this->assertEqualsWithDelta(0.5, $far['left'], 0.005);
        $this->assertEqualsWithDelta(0.5, $far['right'], 0.005);
        $this->assertEqualsWithDelta(self::TONE_HZ, $far['hz'], 1.0);
        $audio->stop($id);
    }

    public function testNativeAttenuationKeepsTheLouderChannelAtTheVoiceLevel(): void
    {
        $audio = $this->manager();
        $id = $audio->playWith('tone', AudioChannel::SFX, new PlaybackOptions(
            0.8, true, position: new Vec3(0.0, 0.0, -5.0), attenuation: $this->linear(),
        ));
        $ahead = $this->measure();
        $this->assertEqualsWithDelta(0.4, $ahead['left'], 0.005); // 0.8 × (1 − 5/10)
        $this->assertEqualsWithDelta(0.4, $ahead['right'], 0.005);

        $audio->setPlaybackPosition($id, new Vec3(5.0, 0.0, 0.0));
        $right = $this->measure();
        $this->assertEqualsWithDelta(0.4, $right['right'], 0.005);
        $this->assertEqualsWithDelta(0.08, $right['left'], 0.005); // far channel at miniaudio's 0.2 floor

        // The listener turns to face the source: it is ahead again.
        $audio->setListener(new AudioListener(new Vec3(), new Vec3(1.0, 0.0, 0.0)));
        $turned = $this->measure();
        $this->assertEqualsWithDelta(0.4, $turned['left'], 0.005);
        $this->assertEqualsWithDelta(0.4, $turned['right'], 0.005);
        $audio->stop($id);
    }

    public function testInverseCurveFollowsTheNativeModel(): void
    {
        $audio = $this->manager();
        $attenuation = new Attenuation(1.0, 50.0, AttenuationCurve::Inverse);
        $id = $audio->playWith('tone', AudioChannel::SFX, new PlaybackOptions(
            1.0, true, position: new Vec3(0.0, 0.0, -4.0), attenuation: $attenuation,
        ));
        $this->assertEqualsWithDelta(0.25, $audio->getPlaybackDistanceGain($id), 1e-9);
        $m = $this->measure();
        $this->assertEqualsWithDelta(0.25, $m['left'], 0.005);
        $this->assertEqualsWithDelta(0.25, $m['right'], 0.005);
        $audio->stop($id);
    }

    public function testOutOfRangeIsSilentAndComesBack(): void
    {
        $audio = $this->manager();
        $attenuation = new Attenuation(1.0, 20.0, AttenuationCurve::Inverse);
        $id = $audio->playWith('tone', AudioChannel::SFX, new PlaybackOptions(
            1.0, true, position: new Vec3(0.0, 0.0, -25.0), attenuation: $attenuation,
        ));
        $out = $this->measure();
        $this->assertSame(0.0, $out['left'] + $out['right']);
        $audio->setPlaybackPosition($id, new Vec3(0.0, 0.0, -2.0));
        $in = $this->measure();
        $this->assertEqualsWithDelta(0.5, $in['left'], 0.005);
        $audio->stop($id);
    }

    public function testSourceVelocityShiftsThePitch(): void
    {
        $audio = $this->manager();
        $id = $audio->playWith('tone', AudioChannel::SFX, new PlaybackOptions(
            1.0, true, position: new Vec3(0.0, 0.0, -5.0), attenuation: $this->linear(),
            velocity: new Vec3(0.0, 0.0, 50.0), // towards the listener
        ));
        $c = AudioManager::SPEED_OF_SOUND;
        $this->assertEqualsWithDelta(self::TONE_HZ * $c / ($c - 50.0), $this->measure()['hz'], 1.5);

        $audio->setDopplerEnabled(false);
        $this->assertEqualsWithDelta(self::TONE_HZ, $this->measure()['hz'], 1.0);
        $audio->setDopplerEnabled(true);
        $audio->setPlaybackDoppler($id, 0.5);
        $this->assertEqualsWithDelta(self::TONE_HZ * $c / ($c - 25.0), $this->measure()['hz'], 1.5);
        $audio->stop($id);
    }

    public function testListenerVelocityIsDerivedFromItsMotion(): void
    {
        $audio = $this->manager();
        $c = AudioManager::SPEED_OF_SOUND;
        $audio->setListener(new AudioListener(new Vec3(0.0, 0.0, 0.0)));
        $id = $audio->playWith('tone', AudioChannel::SFX, new PlaybackOptions(
            1.0, true, position: new Vec3(0.0, 0.0, -50.0), attenuation: new Attenuation(0.0, 100.0, AttenuationCurve::None),
        ));
        // One tick of 0.1 s in which the camera moves 3.433 units towards the source.
        $audio->update(0.1);
        $audio->setListener(new AudioListener(new Vec3(0.0, 0.0, -0.1 * $c * 0.1)));
        $velocity = $audio->getListener()->velocity;
        $this->assertNotNull($velocity);
        $this->assertEqualsWithDelta(-0.1 * $c, $velocity->z, 1e-9);
        $this->assertEqualsWithDelta(self::TONE_HZ * 1.1, $this->measure()['hz'], 1.5);

        // Not moved for a tick: back at rest, no shift.
        $audio->update(0.1);
        $audio->update(0.1);
        $this->assertEqualsWithDelta(0.0, $audio->getListener()->velocity?->length() ?? -1.0, 1e-12);
        $this->assertEqualsWithDelta(self::TONE_HZ, $this->measure()['hz'], 1.0);
        $audio->stop($id);
    }

    public function testPitchAndPanChangeWhilePlaying(): void
    {
        $audio = $this->manager();
        $id = $audio->playOnChannel('tone', AudioChannel::SFX, 1.0, true);
        $audio->setPlaybackPitch($id, 2.0);
        $this->assertEqualsWithDelta(2 * self::TONE_HZ, $this->measure()['hz'], 2.0);
        $audio->setPlaybackPitch($id, 1.0);
        $audio->setPlaybackPan($id, 1.0);
        $panned = $this->measure();
        $this->assertEqualsWithDelta(0.0, $panned['left'], 0.005);
        $this->assertEqualsWithDelta(1.0, $panned['right'], 0.005);
        $this->assertEqualsWithDelta(self::TONE_HZ, $panned['hz'], 1.0);
        $audio->stop($id);
    }

    public function testConeLowersASourceFacingAway(): void
    {
        $audio = $this->manager();
        $id = $audio->playWith('tone', AudioChannel::SFX, new PlaybackOptions(
            1.0, true, position: new Vec3(0.0, 0.0, -5.0), attenuation: $this->linear(),
            direction: new Vec3(0.0, 0.0, 1.0), cone: SoundCone::degrees(60.0, 120.0, 0.25),
        ));
        $facing = $this->measure();
        $this->assertEqualsWithDelta(0.5, $facing['left'], 0.005);
        $audio->setPlaybackDirection($id, new Vec3(0.0, 0.0, -1.0));
        $away = $this->measure();
        $this->assertEqualsWithDelta(0.125, $away['left'], 0.005);
        $this->assertEqualsWithDelta(0.125, $away['right'], 0.005);
        $audio->stop($id);
    }

    public function testFallbackPathNeverMovesTheNativeListener(): void
    {
        $audio = $this->manager(false);
        $flat = $audio->playOnChannel('tone', AudioChannel::SFX, 0.5, true);
        $audio->setListener(new AudioListener(new Vec3(100.0, 0.0, 0.0)));
        $state = vio_audio_listener_state();
        $this->assertIsArray($state);
        $this->assertSame(0.0, (float) $state['position'][0]);
        $m = $this->measure();
        $this->assertEqualsWithDelta(0.5, $m['left'], 0.005);
        $audio->stop($flat);

        $audio->setListener(new AudioListener());
        $id = $audio->playWith('tone', AudioChannel::SFX, new PlaybackOptions(
            0.8, true, position: new Vec3(5.0, 0.0, 0.0), attenuation: $this->linear(),
        ));
        $right = $this->measure();
        $this->assertEqualsWithDelta(0.4, $right['right'], 0.005);
        $this->assertEqualsWithDelta(0.08, $right['left'], 0.005);
        $audio->stop($id);
    }
}
