<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Codec;

use PHPolygon\Audio\Dsp\Pcm;

/** RIFF/WAVE container for 16-bit PCM. */
final class WavWriter
{
    private function __construct() {}

    /** Put a RIFF header in front of little-endian 16-bit PCM data. */
    public static function wrap(string $pcmData, int $channels, int $sampleRate = Pcm::SAMPLE_RATE): string
    {
        $dataSize = strlen($pcmData);
        $byteRate = $sampleRate * $channels * (Pcm::BITS_PER_SAMPLE / 8);
        $blockAlign = $channels * (Pcm::BITS_PER_SAMPLE / 8);

        $header = 'RIFF';
        $header .= pack('V', 36 + $dataSize);
        $header .= 'WAVE';
        $header .= 'fmt ';
        $header .= pack('V', 16);
        $header .= pack('v', 1);                     // PCM
        $header .= pack('v', $channels);
        $header .= pack('V', $sampleRate);
        $header .= pack('V', $byteRate);
        $header .= pack('v', $blockAlign);
        $header .= pack('v', Pcm::BITS_PER_SAMPLE);
        $header .= 'data';
        $header .= pack('V', $dataSize);

        return $header . $pcmData;
    }

    /**
     * Interleaved little-endian PCM of two 16-bit channels.
     *
     * @param list<int> $left
     * @param list<int> $right
     */
    public static function interleave(array $left, array $right): string
    {
        $n = count($left);
        $out = '';
        // pack() takes many arguments at once – far cheaper than one call per sample.
        for ($i = 0; $i < $n; $i += 4096) {
            $args = [];
            $end = min($n, $i + 4096);
            for ($j = $i; $j < $end; $j++) {
                $args[] = $left[$j] & 0xFFFF;
                $args[] = $right[$j] & 0xFFFF;
            }
            $out .= pack('v*', ...$args);
        }
        return $out;
    }

    /**
     * Stereo 16-bit WAV file.
     *
     * @param list<int> $left
     * @param list<int> $right
     */
    public static function stereo(array $left, array $right, int $sampleRate = Pcm::SAMPLE_RATE): string
    {
        return self::wrap(self::interleave($left, $right), 2, $sampleRate);
    }
}
