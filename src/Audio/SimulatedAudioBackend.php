<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

/**
 * Device-free backend that keeps every voice's state, for tests and tools.
 *
 * Voices stay alive until stopped, {@see finish()}ed, or – when the clip has a
 * duration ({@see setClipDuration()}) – until {@see advance()} has played them
 * to the end (time × pitch; paused and looping voices never end on their own).
 * Everything a voice was told is readable through {@see voice()}.
 */
final class SimulatedAudioBackend implements SpatialAudioBackendInterface
{
    /**
     * @var array<int, array{clip: string, volume: float, loop: bool, pitch: float, pan: float,
     *     direction: array{float, float, float}, paused: bool, time: float}>
     */
    private array $voices = [];

    /** @var array<string, float> clipId => seconds */
    private array $durations = [];

    /** @var array<string, string> clipId => path */
    private array $clips = [];

    /** When true every play fails (returns 0). */
    public bool $failPlays = false;

    /** When false, only clips passed to load() can be played. */
    public bool $playUnloaded = true;

    private int $nextPlaybackId = 1;
    private float $masterVolume = 1.0;

    public function load(string $id, string $path): AudioClip
    {
        $this->clips[$id] = $path;
        return new AudioClip($id, $path, $this->durations[$id] ?? 0.0);
    }

    /** Give a clip a length so advance() can end its one-shot voices. */
    public function setClipDuration(string $clipId, float $seconds): void
    {
        $this->durations[$clipId] = max(0.0, $seconds);
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
        if ($this->failPlays || (!$this->playUnloaded && !isset($this->clips[$clipId]))) {
            return 0;
        }
        $id = $this->nextPlaybackId++;
        $this->voices[$id] = [
            'clip' => $clipId,
            'volume' => $volume,
            'loop' => $loop,
            'pitch' => $pitch,
            'pan' => max(-1.0, min(1.0, $pan)),
            'direction' => $direction ?? [0.0, 0.0, 0.0],
            'paused' => false,
            'time' => 0.0,
        ];
        return $id;
    }

    /** Play every unpaused voice on by $dt seconds (scaled by its pitch). */
    public function advance(float $dt): void
    {
        foreach ($this->voices as $id => $voice) {
            if ($voice['paused']) {
                continue;
            }
            $voice['time'] += max(0.0, $dt) * $voice['pitch'];
            $duration = $this->durations[$voice['clip']] ?? null;
            if ($duration !== null && !$voice['loop'] && $voice['time'] >= $duration) {
                unset($this->voices[$id]);
                continue;
            }
            $this->voices[$id] = $voice;
        }
    }

    /** Simulate a voice reaching its end. */
    public function finish(int $playbackId): void
    {
        unset($this->voices[$playbackId]);
    }

    /**
     * @return array{clip: string, volume: float, loop: bool, pitch: float, pan: float,
     *     direction: array{float, float, float}, paused: bool, time: float}|null
     */
    public function voice(int $playbackId): ?array
    {
        return $this->voices[$playbackId] ?? null;
    }

    /** Number of voices alive (playing or paused). */
    public function activeCount(): int
    {
        return count($this->voices);
    }

    public function setDirection(int $playbackId, float $x, float $y, float $z): void
    {
        if (isset($this->voices[$playbackId])) {
            $this->voices[$playbackId]['direction'] = [$x, $y, $z];
        }
    }

    public function pause(int $playbackId): void
    {
        if (isset($this->voices[$playbackId])) {
            $this->voices[$playbackId]['paused'] = true;
        }
    }

    public function resume(int $playbackId): void
    {
        if (isset($this->voices[$playbackId])) {
            $this->voices[$playbackId]['paused'] = false;
        }
    }

    public function isPaused(int $playbackId): bool
    {
        return $this->voices[$playbackId]['paused'] ?? false;
    }

    public function stop(int $playbackId): void
    {
        unset($this->voices[$playbackId]);
    }

    public function stopAll(): void
    {
        $this->voices = [];
    }

    public function setVolume(int $playbackId, float $volume): void
    {
        if (isset($this->voices[$playbackId])) {
            $this->voices[$playbackId]['volume'] = $volume;
        }
    }

    public function isPlaying(int $playbackId): bool
    {
        return isset($this->voices[$playbackId]);
    }

    public function setMasterVolume(float $volume): void
    {
        $this->masterVolume = $volume;
    }

    public function getMasterVolume(): float
    {
        return $this->masterVolume;
    }

    public function dispose(): void
    {
        $this->voices = [];
    }
}
