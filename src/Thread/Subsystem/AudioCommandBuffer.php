<?php

declare(strict_types=1);

namespace PHPolygon\Thread\Subsystem;

/**
 * Collects audio commands during a frame for batch transfer to the audio thread.
 * All data is serializable (no objects cross the channel boundary).
 */
final class AudioCommandBuffer
{
    /** @var list<array{type: string, clipId?: string, volume?: float, loop?: bool, playbackId?: int}> */
    private array $commands = [];

    public function play(string $clipId, float $volume = 1.0, bool $loop = false): void
    {
        $this->commands[] = ['type' => 'play', 'clipId' => $clipId, 'volume' => $volume, 'loop' => $loop];
    }

    public function stop(int $playbackId): void
    {
        $this->commands[] = ['type' => 'stop', 'playbackId' => $playbackId];
    }

    public function stopAll(): void
    {
        $this->commands[] = ['type' => 'stopAll'];
    }

    public function setVolume(int $playbackId, float $volume): void
    {
        $this->commands[] = ['type' => 'setVolume', 'playbackId' => $playbackId, 'volume' => $volume];
    }

    public function setMasterVolume(float $volume): void
    {
        $this->commands[] = ['type' => 'setMasterVolume', 'volume' => $volume];
    }

    /**
     * Flush all queued commands and return them as a serializable array.
     *
     * @return list<array<string, string|float|int|bool>>
     */
    public function flush(): array
    {
        $commands = $this->commands;
        $this->commands = [];
        return $commands;
    }

    public function isEmpty(): bool
    {
        return $this->commands === [];
    }
}
