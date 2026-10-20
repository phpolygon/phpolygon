<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

use PHPolygon\Audio\Codec\AudioFormats;
use VioSound;

/**
 * Audio backend on php-vio (miniaudio).
 *
 * Voices play at exactly the volume they are given. php-vio has no
 * device-level gain, so the backend's own output gain (setMasterVolume) is
 * emulated per voice; AudioManager never sets it (it folds its master volume
 * into each voice), so through the manager it stays at 1.0.
 *
 * Spatial voices: php-vio exposes miniaudio's per-sound position, but neither
 * min/max distance nor the attenuation model, and every sound is spatialised
 * (unpositioned ones sit at the origin). So the native listener is never
 * moved – it stays at the origin facing −Z, which keeps unpositioned voices
 * at full level – and a positioned voice is placed on the unit sphere around
 * it in listener space: miniaudio's inverse model gives gain 1 at distance 1,
 * so only its direction panning acts; AudioManager applies the distance curve
 * through the volume. miniaudio's stereo panning lowers both channels even
 * straight ahead (and floors the far channel at 0.2); the backend compensates
 * so the louder channel plays at the level the voice was given
 * ({@see directionCompensation()}).
 */
class VioAudioBackend implements SpatialAudioBackendInterface
{
    /** miniaudio's default minSpatializationChannelGain. */
    private const float MIN_CHANNEL_GAIN = 0.2;

    /** x / z of miniaudio's FRONT_LEFT / FRONT_RIGHT channel directions. */
    private const float CHANNEL_DIR = 0.7071;

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

    private int $nextPlaybackId = 1;
    private float $masterVolume = 1.0;
    private ?AudioFormats $formats = null;

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
        $path = $this->clipPaths[$clipId] ?? null;
        if ($path === null || !file_exists($path)) {
            return 0;
        }

        $sound = vio_audio_load($path);
        if ($sound === false) {
            return 0;
        }

        $compensation = 1.0;
        if ($direction !== null) {
            [$x, $y, $z] = $direction;
            $compensation = self::placeOnUnitSphere($sound, $x, $y, $z);
        }

        $options = [
            'volume' => $volume * $this->masterVolume * $compensation,
            'loop' => $loop,
        ];
        if ($pitch !== 1.0 && $pitch > 0.0) {
            $options['pitch'] = $pitch;
        }
        if ($pan !== 0.0 && $direction === null) {
            $options['pan'] = max(-1.0, min(1.0, $pan));
        }
        vio_audio_play($sound, $options);

        $playbackId = $this->nextPlaybackId++;
        $this->activeSounds[$playbackId] = $sound;
        $this->voiceVolumes[$playbackId] = $volume;
        if ($compensation !== 1.0) {
            $this->directionGains[$playbackId] = $compensation;
        }

        return $playbackId;
    }

    public function setDirection(int $playbackId, float $x, float $y, float $z): void
    {
        $sound = $this->activeSounds[$playbackId] ?? null;
        if ($sound === null) {
            return;
        }
        $this->directionGains[$playbackId] = self::placeOnUnitSphere($sound, $x, $y, $z);
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
     * (channel gain = max((1 + dot(dir, channelDir)) / 2, 0.2), with the front
     * channels at (∓0.7071, 0, −0.7071)). 1 for the zero vector (unpositioned).
     */
    public static function directionCompensation(float $x, float $y, float $z): float
    {
        $len = sqrt($x * $x + $y * $y + $z * $z);
        if ($len <= 0.001) {
            return 1.0;
        }
        $x /= $len;
        $z /= $len;
        $left = max((1.0 - self::CHANNEL_DIR * $x - self::CHANNEL_DIR * $z) * 0.5, self::MIN_CHANNEL_GAIN);
        $right = max((1.0 + self::CHANNEL_DIR * $x - self::CHANNEL_DIR * $z) * 0.5, self::MIN_CHANNEL_GAIN);
        return 1.0 / max($left, $right);
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
        );
    }
}
