<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Codec;

/**
 * Encoder settings for {@see VorbisWriter}: VBR quality (−0.1 … 1.0, 0.5 is
 * `oggenc -q5`) or, with a bitrate, managed ABR. Vorbis takes any sample rate.
 */
final readonly class VorbisSettings
{
    /**
     * @param float $quality −0.1 … 1.0, used when $bitrate is null
     * @param int|null $bitrate average bits/s (managed mode) instead of quality
     */
    public function __construct(
        public float $quality = 0.5,
        public ?int $bitrate = null,
    ) {
        if ($quality < -0.1 || $quality > 1.0) {
            throw new \InvalidArgumentException("Vorbis quality must be between -0.1 and 1.0, got $quality");
        }
        if ($bitrate !== null && $bitrate <= 0) {
            throw new \InvalidArgumentException("Vorbis bitrate must be positive, got $bitrate");
        }
    }

    /** @return array<string, int|float> the vio_audio_encode() options */
    public function toOptions(): array
    {
        return $this->bitrate !== null ? ['bitrate' => $this->bitrate] : ['quality' => $this->quality];
    }

    /** Stable string of every field, for build cache keys. */
    public function fingerprint(): string
    {
        return $this->bitrate !== null
            ? sprintf('vorbis:abr:%d', $this->bitrate)
            : sprintf('vorbis:q:%.3F', $this->quality);
    }
}
