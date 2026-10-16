<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Codec;

use PHPolygon\Audio\Dsp\Pcm;
use PHPolygon\Audio\Dsp\Resampler;

/**
 * Ogg Opus encoder (`.opus`) for rendered or recorded audio, through php-vio's
 * libopus (`vio_audio_encode`). Requires a php-vio that encodes Opus –
 * {@see isAvailable()}; {@see AudioEncoder} falls back to FLAC otherwise.
 *
 * Input that is not at {@see OpusSettings::$encodeRate} goes through the
 * band-limited {@see Resampler} first. That costs PHP time per sample: for long
 * material, render at the encode rate (24 or 48 kHz) instead.
 *
 * The file decodes sample-exactly to the input length (pre-skip + end trim), at
 * 48 kHz; its header records the encode rate. Same input and settings, same
 * bytes.
 */
final class OpusWriter
{
    private function __construct() {}

    public static function isAvailable(): bool
    {
        return NativeEncoder::available(AudioFormats::OPUS);
    }

    /**
     * Encode one channel of signed 16-bit samples (e.g. speech).
     *
     * @param list<int> $samples
     */
    public static function encodeMono(array $samples, int $sampleRate = Pcm::SAMPLE_RATE, OpusSettings $settings = new OpusSettings()): string
    {
        return NativeEncoder::encode(
            AudioFormats::OPUS,
            [self::toRate($samples, $sampleRate, $settings->encodeRate)],
            $settings->encodeRate,
            $settings->toOptions(),
        );
    }

    /**
     * Encode two equally long channels of signed 16-bit samples.
     *
     * @param list<int> $left
     * @param list<int> $right
     */
    public static function encode(array $left, array $right, int $sampleRate = Pcm::SAMPLE_RATE, OpusSettings $settings = new OpusSettings()): string
    {
        if (count($left) !== count($right)) {
            throw new \InvalidArgumentException('both channels need the same length');
        }
        return NativeEncoder::encode(
            AudioFormats::OPUS,
            [self::toRate($left, $sampleRate, $settings->encodeRate), self::toRate($right, $sampleRate, $settings->encodeRate)],
            $settings->encodeRate,
            $settings->toOptions(),
        );
    }

    /**
     * @param list<int> $samples
     * @return list<int>
     */
    private static function toRate(array $samples, int $from, int $to): array
    {
        if ($from === $to) {
            return $samples;
        }
        $x = array_map(static fn (int $v): float => $v / 32767.0, $samples);
        return array_map(Pcm::toInt16(...), Resampler::convert($x, $from, $to));
    }
}
