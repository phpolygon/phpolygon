<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Codec;

use PHPolygon\Audio\Dsp\Pcm;
use PHPolygon\Audio\Dsp\Resampler;

/**
 * One entry point for tools that read "any audio source" as mono floats at a
 * target rate: WAV through {@see WavReader}, FLAC through {@see FlacReader}
 * (both plain PHP, bit-exact), everything else (Opus, Vorbis, MP3) through
 * php-vio's `vio_audio_decode()` when the extension is loaded.
 *
 * The format is sniffed from the first bytes (`RIFF…WAVE`, `fLaC`, an ID3v2
 * tag in front of `fLaC`), so a misnamed file still reads; the extension
 * decides only when the bytes say nothing.
 *
 * Malformed input throws {@see \InvalidArgumentException}; an unreadable file or
 * a format no decoder in this process handles throws {@see \RuntimeException}.
 */
final class AudioReader
{
    private function __construct() {}

    /** @return list<float> */
    public static function read(string $path, int $targetRate = Pcm::SAMPLE_RATE): array
    {
        return match (self::format($path)) {
            AudioFormats::WAV => WavReader::read($path, $targetRate),
            AudioFormats::FLAC => FlacReader::read($path, $targetRate),
            default => self::viaVio($path, $targetRate),
        };
    }

    /**
     * The format of a file: by its first bytes, else by its extension (null
     * when neither tells).
     */
    public static function format(string $path): ?string
    {
        $head = is_file($path) ? @file_get_contents($path, false, null, 0, 12) : false;
        if ($head === false) {
            throw new \RuntimeException("cannot read audio file $path");
        }
        if (strlen($head) >= 12 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE') {
            return AudioFormats::WAV;
        }
        if (str_starts_with($head, 'fLaC')) {
            return AudioFormats::FLAC;
        }
        if (str_starts_with($head, 'ID3') && strlen($head) >= 10) {
            $size = 10 + ((ord($head[6]) & 0x7F) << 21 | (ord($head[7]) & 0x7F) << 14
                | (ord($head[8]) & 0x7F) << 7 | (ord($head[9]) & 0x7F))
                + ((ord($head[5]) & 0x10) !== 0 ? 10 : 0);
            if (@file_get_contents($path, false, null, $size, 4) === 'fLaC') {
                return AudioFormats::FLAC;
            }
            return AudioFormats::MP3;
        }
        return AudioFormats::forPath($path);
    }

    /** @return list<float> */
    private static function viaVio(string $path, int $targetRate): array
    {
        if (!function_exists('vio_audio_decode')) {
            throw new \RuntimeException(
                "cannot decode $path: only WAV and FLAC are read without php-vio (vio_audio_decode)",
            );
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("cannot read audio file $path");
        }
        $d = vio_audio_decode($bytes, ['data' => true, 'format' => 'f32']);
        if ($d === false) {
            throw new \InvalidArgumentException("php-vio cannot decode $path");
        }
        $channels = max(1, $d['channels']);
        $samples = [];
        foreach (unpack('g*', $d['pcm']) ?: [] as $v) {
            $samples[] = is_float($v) ? $v : 0.0;
        }
        if ($channels === 1) {
            $mono = $samples;
        } else {
            $mono = [];
            $inv = 1.0 / $channels;
            $frames = intdiv(count($samples), $channels);
            for ($f = 0, $o = 0; $f < $frames; $f++, $o += $channels) {
                $s = 0.0;
                for ($c = 0; $c < $channels; $c++) {
                    $s += $samples[$o + $c];
                }
                $mono[] = $s * $inv;
            }
        }
        return Resampler::convert($mono, $d['sample_rate'], $targetRate);
    }
}
