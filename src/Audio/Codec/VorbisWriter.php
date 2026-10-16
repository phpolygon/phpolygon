<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Codec;

use PHPolygon\Audio\Dsp\Pcm;

/**
 * Ogg Vorbis encoder (`.ogg`) through php-vio's libvorbis
 * (`vio_audio_encode`). Any sample rate, no resampling; gapless – the file
 * decodes to exactly the input length, so loops stay seamless. Requires a
 * php-vio that encodes Vorbis – {@see isAvailable()}; {@see AudioEncoder}
 * falls back to FLAC otherwise. Same input and settings, same bytes.
 */
final class VorbisWriter
{
    private function __construct() {}

    public static function isAvailable(): bool
    {
        return NativeEncoder::available(AudioFormats::VORBIS);
    }

    /** @param list<int> $samples */
    public static function encodeMono(array $samples, int $sampleRate = Pcm::SAMPLE_RATE, VorbisSettings $settings = new VorbisSettings()): string
    {
        return NativeEncoder::encode(AudioFormats::VORBIS, [$samples], $sampleRate, $settings->toOptions());
    }

    /**
     * @param list<int> $left
     * @param list<int> $right
     */
    public static function encode(array $left, array $right, int $sampleRate = Pcm::SAMPLE_RATE, VorbisSettings $settings = new VorbisSettings()): string
    {
        return NativeEncoder::encode(AudioFormats::VORBIS, [$left, $right], $sampleRate, $settings->toOptions());
    }
}
