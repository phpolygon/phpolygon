<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

use VioSound;

/**
 * Audio backend on php-vio.
 *
 * Voices play at exactly the volume they are given. php-vio has no
 * device-level gain, so the backend's own output gain (setMasterVolume) is
 * emulated per voice; AudioManager never sets it (it folds its master volume
 * into each voice), so through the manager it stays at 1.0.
 */
class VioAudioBackend implements AudioBackendInterface
{
    /** @var array<string, string> clipId => file path */
    private array $clipPaths = [];

    /** @var array<int, VioSound> playbackId => VioSound */
    private array $activeSounds = [];

    /** @var array<int, float> playbackId => volume the voice was given */
    private array $voiceVolumes = [];

    private int $nextPlaybackId = 1;
    private float $masterVolume = 1.0;

    public function load(string $id, string $path): AudioClip
    {
        $this->clipPaths[$id] = $path;
        return new AudioClip($id, $path);
    }

    public function play(string $clipId, float $volume = 1.0, bool $loop = false): int
    {
        $path = $this->clipPaths[$clipId] ?? null;
        if ($path === null || !file_exists($path)) {
            return 0;
        }

        $sound = vio_audio_load($path);
        if ($sound === false) {
            return 0;
        }

        vio_audio_play($sound, [
            'volume' => $volume * $this->masterVolume,
            'loop' => $loop,
        ]);

        $playbackId = $this->nextPlaybackId++;
        $this->activeSounds[$playbackId] = $sound;
        $this->voiceVolumes[$playbackId] = $volume;

        return $playbackId;
    }

    public function stop(int $playbackId): void
    {
        if (isset($this->activeSounds[$playbackId])) {
            vio_audio_stop($this->activeSounds[$playbackId]);
            unset($this->activeSounds[$playbackId], $this->voiceVolumes[$playbackId]);
        }
    }

    public function stopAll(): void
    {
        foreach ($this->activeSounds as $sound) {
            vio_audio_stop($sound);
        }
        $this->activeSounds = [];
        $this->voiceVolumes = [];
    }

    public function setVolume(int $playbackId, float $volume): void
    {
        if (isset($this->activeSounds[$playbackId])) {
            $this->voiceVolumes[$playbackId] = $volume;
            vio_audio_volume($this->activeSounds[$playbackId], $volume * $this->masterVolume);
        }
    }

    public function isPlaying(int $playbackId): bool
    {
        if (!isset($this->activeSounds[$playbackId])) {
            return false;
        }
        if (vio_audio_playing($this->activeSounds[$playbackId])) {
            return true;
        }
        // Finished one-shot: release the sound handle.
        unset($this->activeSounds[$playbackId], $this->voiceVolumes[$playbackId]);
        return false;
    }

    public function setMasterVolume(float $volume): void
    {
        $this->masterVolume = max(0.0, min(1.0, $volume));
        foreach ($this->activeSounds as $playbackId => $sound) {
            vio_audio_volume($sound, ($this->voiceVolumes[$playbackId] ?? 1.0) * $this->masterVolume);
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
}
