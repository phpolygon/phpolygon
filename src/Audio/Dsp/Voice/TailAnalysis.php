<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp\Voice;

/**
 * What {@see TailBreathTrimmer} found at the end of a recording.
 */
final readonly class TailAnalysis
{
    /**
     * @param int $length samples of the recording
     * @param float $speechDb speech level in dBFS, -INF for silence
     * @param int|null $voiceEnd sample after the last voiced speech, null = none found
     * @param list<TailEvent> $events sounds after $voiceEnd, in order
     * @param int|null $cut new length of the recording, null = leave it alone
     */
    public function __construct(
        public int $length,
        public float $speechDb,
        public ?int $voiceEnd,
        public array $events,
        public ?int $cut,
    ) {}
}
