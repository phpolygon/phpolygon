<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Codec;

/**
 * The STREAMINFO block of a FLAC stream ({@see FlacReader::info()}).
 *
 * `totalSamples` counts samples per channel (inter-channel frames); 0 means the
 * encoder did not know the length. `md5` is the hex MD5 of the interleaved
 * little-endian PCM, or null when the encoder left it blank (all zero).
 */
final readonly class FlacInfo
{
    public function __construct(
        public int $sampleRate,
        public int $channels,
        public int $bitsPerSample,
        public int $totalSamples,
        public ?string $md5,
        public int $minBlockSize,
        public int $maxBlockSize,
        public int $minFrameSize,
        public int $maxFrameSize,
    ) {}

    /** Length in seconds (0.0 when the stream does not say). */
    public function duration(): float
    {
        return $this->sampleRate > 0 ? $this->totalSamples / $this->sampleRate : 0.0;
    }
}
