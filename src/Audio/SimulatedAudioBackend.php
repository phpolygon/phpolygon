<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

use PHPolygon\Audio\Spatial\AudioListener;
use PHPolygon\Audio\Spatial\SpatialSource;
use PHPolygon\Math\Vec3;

/**
 * Device-free backend that keeps every voice's state, for tests and tools.
 *
 * Voices stay alive until stopped, {@see finish()}ed, or – when the clip has a
 * duration ({@see setClipDuration()}) – until {@see advance()} has played them
 * to the end (time × pitch; paused and looping voices never end on their own).
 * Everything a voice was told is readable through {@see voice()}.
 *
 * With `new SimulatedAudioBackend(nativeSpatial: true)` it stands in for a
 * native spatial backend (php-vio ≥ 2.36): 3D voices arrive with their world
 * {@see SpatialSource}, the listener pose is kept ({@see listener()}), pitch
 * and pan change live, and {@see dopplerPitch()} gives the OpenAL Doppler
 * ratio miniaudio would apply. The default (false) is the fallback path.
 */
final class SimulatedAudioBackend implements NativeSpatialAudioBackendInterface
{
    /**
     * @var array<int, array{clip: string, volume: float, loop: bool, pitch: float, pan: float,
     *     direction: array{float, float, float}, paused: bool, time: float, spatial: bool, source: ?SpatialSource}>
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
    private ?AudioListener $listener = null;
    private float $speedOfSound = AudioManager::SPEED_OF_SOUND;

    public function __construct(private readonly bool $nativeSpatial = false) {}

    public function hasNativeSpatial(): bool
    {
        return $this->nativeSpatial;
    }

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
        return $this->start($clipId, $volume, $loop, $pitch, $pan, $direction, null);
    }

    public function playSource(string $clipId, float $volume, bool $loop, float $pitch, SpatialSource $source): int
    {
        return $this->start($clipId, $volume, $loop, $pitch, 0.0, null, $source);
    }

    /**
     * @param array{float, float, float}|null $direction
     */
    private function start(string $clipId, float $volume, bool $loop, float $pitch, float $pan, ?array $direction, ?SpatialSource $source): int
    {
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
            'spatial' => $source !== null || $direction !== null || !$this->nativeSpatial,
            'source' => $source,
        ];
        return $id;
    }

    public function updateSource(int $playbackId, SpatialSource $source): void
    {
        if (isset($this->voices[$playbackId])) {
            $this->voices[$playbackId]['source'] = $source;
            $this->voices[$playbackId]['spatial'] = true;
        }
    }

    public function setListenerPose(AudioListener $listener): void
    {
        $this->listener = $listener;
    }

    /** The last listener pose a native manager pushed (null = never). */
    public function listener(): ?AudioListener
    {
        return $this->listener;
    }

    public function setSpeedOfSound(float $speed): void
    {
        $this->speedOfSound = $speed;
    }

    public function getSpeedOfSound(): float
    {
        return $this->speedOfSound;
    }

    public function setPitch(int $playbackId, float $pitch): void
    {
        if (isset($this->voices[$playbackId])) {
            $this->voices[$playbackId]['pitch'] = $pitch;
        }
    }

    public function setPan(int $playbackId, float $pan): void
    {
        if (isset($this->voices[$playbackId])) {
            $this->voices[$playbackId]['pan'] = max(-1.0, min(1.0, $pan));
        }
    }

    /**
     * The Doppler pitch ratio of a 3D voice as miniaudio computes it (OpenAL:
     * (c − f·v_listener) / (c − f·v_source), velocities projected onto the
     * source-to-listener line, each capped at c / f; the listener at rest at
     * the origin until a pose arrives). 1 without a source, a factor or a
     * distance.
     */
    public function dopplerPitch(int $playbackId): float
    {
        $source = $this->voices[$playbackId]['source'] ?? null;
        if ($source === null || $source->doppler <= 0.0) {
            return 1.0;
        }
        $listener = $this->listener ?? new AudioListener();
        $rel = $listener->position->sub($source->position);
        $len = $rel->length();
        if ($len <= 0.0) {
            return 1.0;
        }
        $listenerVelocity = $listener->velocity ?? new Vec3();
        $f = $source->doppler;
        $c = $this->speedOfSound;
        $vls = min($rel->dot($listenerVelocity) / $len, $c / $f);
        $vss = min($rel->dot($source->velocity) / $len, $c / $f);
        return ($c - $f * $vls) / ($c - $f * $vss);
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
     * spatial: false for a 2D voice on the native path (it bypasses the
     * spatializer); source: the world placement of a native 3D voice.
     *
     * @return array{clip: string, volume: float, loop: bool, pitch: float, pan: float,
     *     direction: array{float, float, float}, paused: bool, time: float, spatial: bool, source: ?SpatialSource}|null
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
