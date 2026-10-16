<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

/**
 * A playback a {@see StemPlayer} gives up while it is still sounding: the new
 * owner takes it at {@see $volume} and fades it out over {@see $fade} seconds
 * (e.g. while its own music fades in – no gap, no second copy).
 */
final readonly class StemHandoff
{
    public function __construct(
        public string $stem,
        public int $playback,
        public float $volume,
        public float $fade,
        public AudioChannel $channel = AudioChannel::Music,
    ) {}
}
