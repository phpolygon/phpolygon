<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

use PHPolygon\Audio\Codec\AudioFormats;
use PHPolygon\Audio\Spatial\AudioListener;
use PHPolygon\Audio\Spatial\SoundCone;
use PHPolygon\Audio\Spatial\SpatialSource;
use PHPolygon\Math\Vec3;
use VioSound;

/**
 * Audio backend on php-vio (miniaudio).
 *
 * Voices play at exactly the volume they are given. php-vio has no
 * device-level gain, so the backend's own output gain (setMasterVolume) is
 * emulated per voice; AudioManager never sets it (it folds its master volume
 * into each voice), so through the manager it stays at 1.0.
 *
 * Two spatial modes, chosen once in the constructor:
 *
 * - **Native** (php-vio ≥ 2.36, {@see nativeSpatialAvailable()}): the native
 *   listener follows the pose AudioManager pushes (position, facing, up,
 *   velocity); a 3D voice sits at its world position with its velocity,
 *   Doppler factor, OpenAL clamped attenuation ({@see Spatial\Attenuation::toNative()})
 *   and optional facing/cone; a 2D voice is played with `spatial => false`,
 *   so the moving listener neither attenuates nor pans it. Pitch and pan can
 *   change while a voice plays.
 * - **Fallback** (older php-vio): php-vio exposes neither min/max distance nor
 *   the attenuation model, and every sound is spatialised (unpositioned ones
 *   sit at the origin). So the native listener is never moved – it stays at
 *   the origin facing −Z, which keeps unpositioned voices at full level – and
 *   a positioned voice is placed on the unit sphere around it in listener
 *   space: miniaudio's inverse model gives gain 1 at distance 1, so only its
 *   direction panning acts; AudioManager applies the distance curve through
 *   the volume. Pitch and pan are set only at the start; no Doppler.
 *
 * In both modes miniaudio's stereo panning lowers both channels even straight
 * ahead (and floors the far channel at 0.2); the backend compensates so the
 * louder channel plays at the level the voice was given
 * ({@see directionCompensation()}).
 */
class VioAudioBackend implements NativeSpatialAudioBackendInterface
{
    /** miniaudio's default minSpatializationChannelGain. */
    private const float MIN_CHANNEL_GAIN = 0.2;

    /** @var array<string, string> clipId => file path */
    private array $clipPaths = [];

    /** @var array<int, VioSound> playbackId => VioSound */
    private array $activeSounds = [];

    /** @var array<int, float> playbackId => volume the voice was given */
    private array $voiceVolumes = [];

    /** @var array<int, float> playbackId => direction compensation gain */
    private array $directionGains = [];

    /** @var array<int, true> paused playback ids */
    private array $paused = [];

    /** @var array<int, SpatialSource> native 3D voices: their last world placement */
    private array $sources = [];

    /** @var array<int, true> native voices with the spatializer switched on (3D or listener-relative) */
    private array $spatialVoices = [];

    private int $nextPlaybackId = 1;
    private float $masterVolume = 1.0;
    private ?AudioFormats $formats = null;

    private readonly bool $native;

    /** Native listener pose (null = miniaudio's default at the origin). */
    private ?AudioListener $listener = null;
    private ?float $speedOfSound = null;

    /** The native listener calls need a running engine; they are applied once it is. */
    private bool $engineStarted = false;
    private bool $listenerPending = false;

    /**
     * @param bool|null $nativeSpatial null = native when php-vio has it; false
     *     forces the fallback path; true asks for native but still falls back
     *     on a php-vio without it.
     */
    public function __construct(?bool $nativeSpatial = null)
    {
        $available = self::nativeSpatialAvailable();
        $this->native = $nativeSpatial === null ? $available : ($nativeSpatial && $available);
    }

    /** True when the loaded php-vio has the live spatial API (2.36+). */
    public static function nativeSpatialAvailable(): bool
    {
        return function_exists('vio_audio_spatial')
            && function_exists('vio_audio_attenuation')
            && function_exists('vio_audio_listener_velocity')
            && function_exists('vio_audio_pitch')
            && function_exists('vio_audio_backend');
    }

    public function hasNativeSpatial(): bool
    {
        return $this->native;
    }

    public function load(string $id, string $path): AudioClip
    {
        // A clip shipped in several formats (e.g. line.opus next to line.flac)
        // plays from the smallest one this php-vio decodes.
        $this->clipPaths[$id] = $this->formats()->resolve($path) ?? $path;
        return new AudioClip($id, $path);
    }

    private function formats(): AudioFormats
    {
        return $this->formats ??= AudioFormats::detect();
    }

    public function play(string $clipId, float $volume = 1.0, bool $loop = false): int
    {
        return $this->playVoice($clipId, $volume, $loop);
    }

    public function playVoice(
        string $clipId,
        float $volume = 1.0,
        bool $loop = false,
        float $pitch = 1.0,
        float $pan = 0.0,
        ?array $direction = null,
    ): int {
        $sound = $this->loadSound($clipId);
        if ($sound === null) {
            return 0;
        }

        $options = ['loop' => $loop];
        $compensation = 1.0;
        if ($this->native) {
            if ($direction === null) {
                // 2D: the listener follows the camera, this voice must not.
                $options['spatial'] = false;
            } else {
                $compensation = self::placeOnUnitSphere($sound, $direction[0], $direction[1], $direction[2]);
                $options['spatial'] = true;
                $options['relative'] = true;
            }
        } elseif ($direction !== null) {
            $compensation = self::placeOnUnitSphere($sound, $direction[0], $direction[1], $direction[2]);
        }

        $options['volume'] = $volume * $this->masterVolume * $compensation;
        if ($pitch !== 1.0 && $pitch > 0.0 && is_finite($pitch)) {
            $options['pitch'] = $pitch;
        }
        if ($pan !== 0.0 && $direction === null) {
            $options['pan'] = max(-1.0, min(1.0, $pan));
        }
        vio_audio_play($sound, $options);

        $playbackId = $this->register($sound, $volume, $compensation);
        if ($this->native && $direction !== null) {
            $this->spatialVoices[$playbackId] = true;
        }
        return $playbackId;
    }

    public function playSource(string $clipId, float $volume, bool $loop, float $pitch, SpatialSource $source): int
    {
        if (!$this->native) {
            // Not native: place it like the fallback path does (direction only).
            $local = ($this->listener ?? new AudioListener())->toLocal($source->position);
            return $this->playVoice($clipId, $volume, $loop, $pitch, 0.0, [$local->x, $local->y, $local->z]);
        }
        $sound = $this->loadSound($clipId);
        if ($sound === null) {
            return 0;
        }

        $native = $source->attenuation->toNative();
        $p = $source->position;
        $v = $source->velocity;
        $options = [
            'loop' => $loop,
            'spatial' => true,
            'relative' => false,
            'position' => [$p->x, $p->y, $p->z],
            'velocity' => [$v->x, $v->y, $v->z],
            'doppler' => $source->doppler,
            'attenuation' => $native['model'],
            'min_distance' => $native['min'],
            'max_distance' => $native['max'],
            'rolloff' => $native['rolloff'],
            'min_gain' => 0.0,
            'max_gain' => 1.0,
        ];
        if ($source->direction !== null && $source->direction->lengthSquared() > 1e-12) {
            $d = $source->direction;
            $options['direction'] = [$d->x, $d->y, $d->z];
        }
        if ($pitch !== 1.0 && $pitch > 0.0 && is_finite($pitch)) {
            $options['pitch'] = $pitch;
        }
        self::applyCone($sound, $source->cone);
        $compensation = $this->compensationFor($source);
        $options['volume'] = $volume * $this->masterVolume * $compensation;
        vio_audio_play($sound, $options);

        $playbackId = $this->register($sound, $volume, $compensation);
        $this->sources[$playbackId] = $source;
        $this->spatialVoices[$playbackId] = true;
        return $playbackId;
    }

    public function updateSource(int $playbackId, SpatialSource $source): void
    {
        $sound = $this->activeSounds[$playbackId] ?? null;
        if ($sound === null) {
            return;
        }
        if (!$this->native) {
            $local = ($this->listener ?? new AudioListener())->toLocal($source->position);
            $this->setDirection($playbackId, $local->x, $local->y, $local->z);
            return;
        }
        $previous = $this->sources[$playbackId] ?? null;
        if ($previous === null || !isset($this->spatialVoices[$playbackId])) {
            // A 2D voice that became 3D.
            vio_audio_spatial($sound, true, false);
            $this->spatialVoices[$playbackId] = true;
            $previous = null;
        }
        $p = $source->position;
        $v = $source->velocity;
        vio_audio_position($sound, $p->x, $p->y, $p->z);
        vio_audio_velocity($sound, $v->x, $v->y, $v->z);
        if ($previous === null || $previous->attenuation != $source->attenuation) {
            $native = $source->attenuation->toNative();
            vio_audio_attenuation($sound, $native['model'], $native['min'], $native['max'], $native['rolloff'], 0.0, 1.0);
        }
        if ($previous === null || $previous->doppler !== $source->doppler) {
            vio_audio_doppler($sound, $source->doppler);
        }
        $d = $source->direction;
        if ($d !== null && $d->lengthSquared() > 1e-12
            && ($previous?->direction === null || !$previous->direction->equals($d, 0.0))) {
            vio_audio_direction($sound, $d->x, $d->y, $d->z);
        }
        if ($previous === null || $previous->cone != $source->cone) {
            self::applyCone($sound, $source->cone);
        }
        $this->sources[$playbackId] = $source;
        $this->directionGains[$playbackId] = $this->compensationFor($source);
        $this->applyVolume($playbackId);
    }

    public function setListenerPose(AudioListener $listener): void
    {
        $this->listener = $listener;
        if (!$this->native) {
            return; // the fallback path never moves the native listener
        }
        $this->listenerPending = true;
        $this->flushListener();
        foreach ($this->sources as $playbackId => $source) {
            $gain = $this->compensationFor($source);
            if ($gain !== ($this->directionGains[$playbackId] ?? 1.0)) {
                $this->directionGains[$playbackId] = $gain;
                $this->applyVolume($playbackId);
            }
        }
    }

    public function setSpeedOfSound(float $speed): void
    {
        if (!$this->native || !is_finite($speed) || $speed <= 0.0) {
            return;
        }
        $this->speedOfSound = $speed;
        $this->listenerPending = true;
        $this->flushListener();
    }

    public function setPitch(int $playbackId, float $pitch): void
    {
        $sound = $this->activeSounds[$playbackId] ?? null;
        if ($sound !== null && $this->native && $pitch > 0.0 && is_finite($pitch)) {
            vio_audio_pitch($sound, $pitch);
        }
    }

    public function setPan(int $playbackId, float $pan): void
    {
        $sound = $this->activeSounds[$playbackId] ?? null;
        if ($sound !== null && $this->native && !is_nan($pan)) {
            vio_audio_pan($sound, max(-1.0, min(1.0, $pan)));
        }
    }

    public function setDirection(int $playbackId, float $x, float $y, float $z): void
    {
        $sound = $this->activeSounds[$playbackId] ?? null;
        if ($sound === null || isset($this->sources[$playbackId])) {
            return;
        }
        if ($this->native) {
            if (!isset($this->spatialVoices[$playbackId])) {
                vio_audio_spatial($sound, true, true);
                $this->spatialVoices[$playbackId] = true;
            }
            $this->directionGains[$playbackId] = self::placeOnUnitSphere($sound, $x, $y, $z);
        } else {
            $this->directionGains[$playbackId] = self::placeOnUnitSphere($sound, $x, $y, $z);
        }
        $this->applyVolume($playbackId);
    }

    public function pause(int $playbackId): void
    {
        $sound = $this->activeSounds[$playbackId] ?? null;
        if ($sound === null || isset($this->paused[$playbackId])) {
            return;
        }
        if (!vio_audio_playing($sound)) {
            // Already over: nothing to hold, release it.
            $this->forget($playbackId);
            return;
        }
        vio_audio_pause($sound);
        $this->paused[$playbackId] = true;
    }

    public function resume(int $playbackId): void
    {
        $sound = $this->activeSounds[$playbackId] ?? null;
        if ($sound === null || !isset($this->paused[$playbackId])) {
            return;
        }
        vio_audio_resume($sound);
        unset($this->paused[$playbackId]);
    }

    public function isPaused(int $playbackId): bool
    {
        return isset($this->paused[$playbackId]);
    }

    public function stop(int $playbackId): void
    {
        if (isset($this->activeSounds[$playbackId])) {
            vio_audio_stop($this->activeSounds[$playbackId]);
            $this->forget($playbackId);
        }
    }

    public function stopAll(): void
    {
        foreach ($this->activeSounds as $sound) {
            vio_audio_stop($sound);
        }
        $this->activeSounds = [];
        $this->voiceVolumes = [];
        $this->directionGains = [];
        $this->paused = [];
        $this->sources = [];
        $this->spatialVoices = [];
    }

    public function setVolume(int $playbackId, float $volume): void
    {
        if (isset($this->activeSounds[$playbackId])) {
            $this->voiceVolumes[$playbackId] = $volume;
            $this->applyVolume($playbackId);
        }
    }

    public function isPlaying(int $playbackId): bool
    {
        if (!isset($this->activeSounds[$playbackId])) {
            return false;
        }
        if (isset($this->paused[$playbackId]) || vio_audio_playing($this->activeSounds[$playbackId])) {
            return true;
        }
        // Finished one-shot: release the sound handle.
        $this->forget($playbackId);
        return false;
    }

    public function setMasterVolume(float $volume): void
    {
        $this->masterVolume = max(0.0, min(1.0, $volume));
        foreach ($this->activeSounds as $playbackId => $_) {
            $this->applyVolume($playbackId);
        }
    }

    public function getMasterVolume(): float
    {
        return $this->masterVolume;
    }

    public function dispose(): void
    {
        $this->stopAll();
    }

    /**
     * Gain that brings the louder stereo channel of a voice at listener-space
     * direction (x, y, z) back to 1 under miniaudio's panning
     * (channel gain = max((1 + dot(dir, channelDir)) / 2, 0.2)). For stereo
     * the spatializer always pans onto SIDE_LEFT / SIDE_RIGHT at (∓1, 0, 0) –
     * miniaudio's own stereo default, device channel maps are not used – so a
     * voice straight ahead plays at 0.5 per channel (measured on php-vio
     * 2.36's offline engine). 1 for the zero vector (unpositioned).
     */
    public static function directionCompensation(float $x, float $y, float $z): float
    {
        $len = sqrt($x * $x + $y * $y + $z * $z);
        if ($len <= 0.001) {
            return 1.0;
        }
        $x /= $len;
        $left = max((1.0 - $x) * 0.5, self::MIN_CHANNEL_GAIN);
        $right = max((1.0 + $x) * 0.5, self::MIN_CHANNEL_GAIN);
        return 1.0 / max($left, $right);
    }

    private function loadSound(string $clipId): ?VioSound
    {
        $path = $this->clipPaths[$clipId] ?? null;
        if ($path === null || !file_exists($path)) {
            return null;
        }
        $start = hrtime(true);
        $sound = vio_audio_load($path);
        \PHPolygon\Runtime\FrameSpikeRecorder::note(\PHPolygon\Runtime\FrameSpikeRecorder::NOTE_AUDIO, 1, hrtime(true) - $start);
        if ($sound === false) {
            return null;
        }
        // Loading starts the engine: apply a listener pose set before it.
        $this->flushListener();
        return $sound;
    }

    private function register(VioSound $sound, float $volume, float $compensation): int
    {
        $playbackId = $this->nextPlaybackId++;
        $this->activeSounds[$playbackId] = $sound;
        $this->voiceVolumes[$playbackId] = $volume;
        if ($compensation !== 1.0) {
            $this->directionGains[$playbackId] = $compensation;
        }
        return $playbackId;
    }

    /** Push the pending listener pose and speed of sound once the engine runs. */
    private function flushListener(): void
    {
        if (!$this->native || !$this->listenerPending) {
            return;
        }
        if (!$this->engineStarted) {
            if (vio_audio_backend() === false) {
                return;
            }
            $this->engineStarted = true;
        }
        $this->listenerPending = false;
        if ($this->listener !== null) {
            $l = $this->listener;
            vio_audio_listener(
                $l->position->x, $l->position->y, $l->position->z,
                $l->forward->x, $l->forward->y, $l->forward->z,
                $l->up->x, $l->up->y, $l->up->z,
            );
            $v = $l->velocity ?? new Vec3();
            vio_audio_listener_velocity($v->x, $v->y, $v->z);
        }
        if ($this->speedOfSound !== null) {
            vio_audio_speed_of_sound($this->speedOfSound);
        }
    }

    private function compensationFor(SpatialSource $source): float
    {
        $local = ($this->listener ?? new AudioListener())->toLocal($source->position);
        return self::directionCompensation($local->x, $local->y, $local->z);
    }

    private static function applyCone(VioSound $sound, ?SoundCone $cone): void
    {
        if ($cone === null) {
            vio_audio_cone($sound, SoundCone::FULL_CIRCLE, SoundCone::FULL_CIRCLE, 1.0);
            return;
        }
        vio_audio_cone($sound, $cone->innerAngle, $cone->outerAngle, $cone->outerGain);
    }

    private static function placeOnUnitSphere(VioSound $sound, float $x, float $y, float $z): float
    {
        $len = sqrt($x * $x + $y * $y + $z * $z);
        if ($len <= 0.001) {
            vio_audio_position($sound, 0.0, 0.0, 0.0);
            return 1.0;
        }
        vio_audio_position($sound, $x / $len, $y / $len, $z / $len);
        return self::directionCompensation($x, $y, $z);
    }

    private function applyVolume(int $playbackId): void
    {
        $sound = $this->activeSounds[$playbackId] ?? null;
        if ($sound === null) {
            return;
        }
        $gain = ($this->voiceVolumes[$playbackId] ?? 1.0) * $this->masterVolume * ($this->directionGains[$playbackId] ?? 1.0);
        vio_audio_volume($sound, $gain);
    }

    private function forget(int $playbackId): void
    {
        unset(
            $this->activeSounds[$playbackId],
            $this->voiceVolumes[$playbackId],
            $this->directionGains[$playbackId],
            $this->paused[$playbackId],
            $this->sources[$playbackId],
            $this->spatialVoices[$playbackId],
        );
    }
}
