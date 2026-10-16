<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Codec;

use PHPolygon\Audio\Dsp\Pcm;

/**
 * Writes 16-bit PCM in the best format the build can encode and the player can
 * decode – the game lists its preference (e.g. `['opus']` for speech,
 * `['vorbis', 'opus']` for music) with the codec settings, the encoder picks
 * the first usable one and falls back to FLAC ({@see AudioFormats::choose()}).
 *
 * ```php
 * $enc = new AudioEncoder(['opus'], new OpusSettings(bitrate: 32000, encodeRate: 24000, application: 'voip'));
 * file_put_contents("$dir/$key." . $enc->extension(), $enc->encode([$samples], 24000));
 * ```
 *
 * Keep {@see fingerprint()} in the build cache key: it changes when the chosen
 * format or its settings change.
 */
final readonly class AudioEncoder
{
    public AudioFormats $formats;
    public string $format;

    /**
     * @param list<string> $preferred formats in order of preference
     * @param AudioFormats|null $formats what this process encodes (default: detected)
     * @param AudioFormats|null $player what the shipped runtime decodes (default: $formats)
     */
    public function __construct(
        public array $preferred = [AudioFormats::FLAC],
        public OpusSettings $opus = new OpusSettings(),
        public VorbisSettings $vorbis = new VorbisSettings(),
        ?AudioFormats $formats = null,
        ?AudioFormats $player = null,
    ) {
        $this->formats = $formats ?? AudioFormats::detect();
        $this->format = $this->formats->choose($preferred, $player);
    }

    public function extension(): string
    {
        return AudioFormats::extension($this->format);
    }

    /** Whether the preferred first choice had to fall back. */
    public function isFallback(): bool
    {
        return $this->format !== ($this->preferred[0] ?? AudioFormats::FLAC);
    }

    /**
     * @param list<list<int>> $channels one (mono) or two (stereo) lists of signed 16-bit samples
     */
    public function encode(array $channels, int $sampleRate = Pcm::SAMPLE_RATE): string
    {
        if (count($channels) < 1 || count($channels) > 2) {
            throw new \InvalidArgumentException('1 or 2 channels supported, got ' . count($channels));
        }
        $left = $channels[0];
        $right = $channels[1] ?? null;
        if ($right === null) {
            return match ($this->format) {
                AudioFormats::OPUS => OpusWriter::encodeMono($left, $sampleRate, $this->opus),
                AudioFormats::VORBIS => VorbisWriter::encodeMono($left, $sampleRate, $this->vorbis),
                AudioFormats::WAV => WavWriter::wrap(self::packMono($left), 1, $sampleRate),
                default => FlacWriter::encodeMono($left, $sampleRate),
            };
        }
        return match ($this->format) {
            AudioFormats::OPUS => OpusWriter::encode($left, $right, $sampleRate, $this->opus),
            AudioFormats::VORBIS => VorbisWriter::encode($left, $right, $sampleRate, $this->vorbis),
            AudioFormats::WAV => WavWriter::stereo($left, $right, $sampleRate),
            default => FlacWriter::encode($left, $right, $sampleRate),
        };
    }

    /** Chosen format plus its settings, for build cache keys. */
    public function fingerprint(): string
    {
        return match ($this->format) {
            AudioFormats::OPUS => $this->opus->fingerprint(),
            AudioFormats::VORBIS => $this->vorbis->fingerprint(),
            default => $this->format,
        };
    }

    /** @param list<int> $samples */
    private static function packMono(array $samples): string
    {
        $out = '';
        foreach (array_chunk($samples, 4096) as $chunk) {
            $out .= pack('v*', ...array_map(static fn (int $v): int => $v & 0xFFFF, $chunk));
        }
        return $out;
    }
}
