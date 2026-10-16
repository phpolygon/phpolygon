<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Codec;

/**
 * The php-vio encoder behind {@see OpusWriter} and {@see VorbisWriter}:
 * `vio_audio_encode()` (libopus / libvorbis compiled into the extension).
 *
 * @internal
 */
final class NativeEncoder
{
    private function __construct() {}

    /** Whether the loaded php-vio encodes $codec ('opus' | 'vorbis'). */
    public static function available(string $codec): bool
    {
        if (!function_exists('vio_audio_encode') || !function_exists('vio_audio_formats')) {
            return false;
        }
        return in_array($codec, vio_audio_formats()['encode'], true);
    }

    /**
     * @param list<list<int>> $channels one or two equally long channels of signed 16-bit samples
     * @param array<string, int|float|string|bool> $options
     */
    public static function encode(string $codec, array $channels, int $sampleRate, array $options): string
    {
        if (!self::available($codec)) {
            throw new \RuntimeException(
                "encoding $codec needs php-vio with vio_audio_encode() – check AudioFormats::detect()->canEncode('$codec') first",
            );
        }
        $count = count($channels);
        if ($count < 1 || $count > 2) {
            throw new \InvalidArgumentException("1 or 2 channels supported, got $count");
        }
        if ($count === 2 && count($channels[0]) !== count($channels[1])) {
            throw new \InvalidArgumentException('both channels need the same length');
        }
        if ($channels[0] === []) {
            throw new \InvalidArgumentException('no samples to encode');
        }

        $pcm = $count === 2 ? WavWriter::interleave($channels[0], $channels[1]) : self::packMono($channels[0]);
        $bytes = @vio_audio_encode($pcm, $sampleRate, $count, $codec, $options);
        if ($bytes === false) {
            $err = error_get_last();
            throw new \RuntimeException("$codec encoding failed: " . ($err['message'] ?? 'unknown error'));
        }
        return $bytes;
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
