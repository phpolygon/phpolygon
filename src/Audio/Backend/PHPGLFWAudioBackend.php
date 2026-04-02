<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Backend;

use GL\Audio\Engine as GLAudioEngine;
use GL\Audio\Sound as GLSound;
use PHPolygon\Audio\AudioBackendInterface;
use PHPolygon\Audio\AudioClip;

/**
 * Audio backend using php-glfw's built-in audio engine (miniaudio).
 * Works without external dependencies (SDL3/OpenAL).
 */
class PHPGLFWAudioBackend implements AudioBackendInterface
{
    private GLAudioEngine $engine;

    /** @var array<string, GLSound> loaded sounds by clip ID */
    private array $sounds = [];

    /** @var array<string, string> clip ID => file path */
    private array $clipPaths = [];

    /** @var array<int, array{sound: GLSound, clipId: string}> active playbacks */
    private array $playbacks = [];

    private int $nextPlaybackId = 1;
    private float $masterVolume = 1.0;
    private bool $disposed = false;

    public function __construct()
    {
        if (!class_exists(GLAudioEngine::class)) {
            throw new \RuntimeException(
                'GL\Audio\Engine not available — php-glfw was built without audio support.'
            );
        }
        $this->engine = new GLAudioEngine([]);
        $this->engine->start();
    }

    public static function isAvailable(): bool
    {
        return class_exists(GLAudioEngine::class);
    }

    public function load(string $id, string $path): AudioClip
    {
        $this->clipPaths[$id] = $path;
        // Lazy-load the actual sound on first play
        return new AudioClip($id, $path);
    }

    public function play(string $clipId, float $volume = 1.0, bool $loop = false): int
    {
        $sound = $this->getOrLoadSound($clipId);
        $sound->setVolume($volume * $this->masterVolume);
        $sound->setLoop($loop);
        $sound->play();

        $id = $this->nextPlaybackId++;
        $this->playbacks[$id] = ['sound' => $sound, 'clipId' => $clipId];

        return $id;
    }

    public function stop(int $playbackId): void
    {
        if (isset($this->playbacks[$playbackId])) {
            $this->playbacks[$playbackId]['sound']->stop();
            unset($this->playbacks[$playbackId]);
        }
    }

    public function stopAll(): void
    {
        foreach ($this->playbacks as $pb) {
            $pb['sound']->stop();
        }
        $this->playbacks = [];
    }

    public function setVolume(int $playbackId, float $volume): void
    {
        if (isset($this->playbacks[$playbackId])) {
            $this->playbacks[$playbackId]['sound']->setVolume($volume * $this->masterVolume);
        }
    }

    public function isPlaying(int $playbackId): bool
    {
        if (!isset($this->playbacks[$playbackId])) {
            return false;
        }
        return $this->playbacks[$playbackId]['sound']->isPlaying();
    }

    public function setMasterVolume(float $volume): void
    {
        $this->masterVolume = max(0.0, min(1.0, $volume));
    }

    public function getMasterVolume(): float
    {
        return $this->masterVolume;
    }

    public function dispose(): void
    {
        if ($this->disposed) return;
        $this->disposed = true;
        $this->stopAll();
        $this->sounds = [];
        $this->clipPaths = [];
        $this->engine->stop();
    }

    public function __destruct()
    {
        $this->dispose();
    }

    private function getOrLoadSound(string $clipId): GLSound
    {
        if (!isset($this->sounds[$clipId])) {
            $path = $this->clipPaths[$clipId] ?? null;
            if ($path === null) {
                throw new \RuntimeException("Audio clip not loaded: {$clipId}");
            }
            $this->sounds[$clipId] = $this->engine->soundFromDisk($path);
        }

        return $this->sounds[$clipId];
    }
}
