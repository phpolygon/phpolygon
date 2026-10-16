<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Codec;

/**
 * Encoder settings for {@see OpusWriter}. No presets here – a game passes the
 * values that fit its content (speech typically 24 kHz, 24–48 kbit/s, `voip`;
 * music 48 kHz, 96–128 kbit/s, `audio`).
 */
final readonly class OpusSettings
{
    /** Sample rates libopus encodes at; other input is resampled to {@see $encodeRate}. */
    public const array RATES = [8000, 12000, 16000, 24000, 48000];
    public const array APPLICATIONS = ['audio', 'voip', 'lowdelay'];
    public const array SIGNALS = ['auto', 'voice', 'music'];
    public const array FRAME_MS = [10, 20, 40, 60];

    /**
     * @param int|null $bitrate bits/s (500–512000); null = php-vio's default (48k mono, 96k stereo)
     * @param int $encodeRate rate the encoder runs at, one of {@see RATES}; playback is always 48 kHz
     * @param string $application 'audio' | 'voip' (speech) | 'lowdelay'
     * @param string $signal 'auto' | 'voice' | 'music'
     * @param int $complexity 0–10
     * @param int $frameMs 10, 20, 40 or 60
     */
    public function __construct(
        public ?int $bitrate = null,
        public int $encodeRate = 48000,
        public string $application = 'audio',
        public string $signal = 'auto',
        public int $complexity = 10,
        public bool $vbr = true,
        public int $frameMs = 20,
    ) {
        if (!in_array($encodeRate, self::RATES, true)) {
            throw new \InvalidArgumentException("Opus encode rate must be one of 8000, 12000, 16000, 24000, 48000, got $encodeRate");
        }
        if (!in_array($application, self::APPLICATIONS, true)) {
            throw new \InvalidArgumentException("unknown Opus application '$application'");
        }
        if (!in_array($signal, self::SIGNALS, true)) {
            throw new \InvalidArgumentException("unknown Opus signal '$signal'");
        }
        if ($complexity < 0 || $complexity > 10) {
            throw new \InvalidArgumentException("Opus complexity must be 0–10, got $complexity");
        }
        if (!in_array($frameMs, self::FRAME_MS, true)) {
            throw new \InvalidArgumentException("Opus frame size must be 10, 20, 40 or 60 ms, got $frameMs");
        }
        if ($bitrate !== null && ($bitrate < 500 || $bitrate > 512000)) {
            throw new \InvalidArgumentException("Opus bitrate must be 500–512000 bit/s, got $bitrate");
        }
    }

    /** @return array<string, int|string|bool> the vio_audio_encode() options */
    public function toOptions(): array
    {
        $o = [
            'application' => $this->application,
            'signal' => $this->signal,
            'complexity' => $this->complexity,
            'vbr' => $this->vbr,
            'frame_ms' => $this->frameMs,
        ];
        if ($this->bitrate !== null) {
            $o['bitrate'] = $this->bitrate;
        }
        return $o;
    }

    /** Stable string of every field, for build cache keys. */
    public function fingerprint(): string
    {
        return sprintf(
            'opus:%s:%d:%s:%s:%d:%d:%d',
            $this->bitrate ?? 'default',
            $this->encodeRate,
            $this->application,
            $this->signal,
            $this->complexity,
            $this->vbr ? 1 : 0,
            $this->frameMs,
        );
    }
}
