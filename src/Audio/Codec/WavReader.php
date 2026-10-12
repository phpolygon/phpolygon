<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Codec;

use PHPolygon\Audio\Dsp\Pcm;
use PHPolygon\Audio\Dsp\Resampler;

/**
 * Reads a RIFF/WAVE recording into mono float samples at a target rate
 * (default {@see Pcm::SAMPLE_RATE}): 16/24-bit integer PCM or 32-bit float, any channel
 * count (averaged to mono), WAVE_FORMAT_EXTENSIBLE included. Other rates go
 * through the band-limited {@see Resampler}.
 *
 * Malformed or unsupported input throws {@see \InvalidArgumentException};
 * an unreadable file throws {@see \RuntimeException}.
 */
final class WavReader
{
    private const int FORMAT_PCM = 1;
    private const int FORMAT_FLOAT = 3;
    private const int FORMAT_EXTENSIBLE = 0xFFFE;

    private function __construct() {}

    /** @return list<float> */
    public static function read(string $path, int $targetRate = Pcm::SAMPLE_RATE): array
    {
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        if ($bytes === false) {
            throw new \RuntimeException("cannot read WAV file $path");
        }
        return self::decode($bytes, $targetRate);
    }

    /** @return list<float> */
    public static function decode(string $bytes, int $targetRate = Pcm::SAMPLE_RATE): array
    {
        if (strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
            throw new \InvalidArgumentException('not a RIFF/WAVE file');
        }
        $len = strlen($bytes);
        $fmt = null;
        $data = null;
        $pos = 12;
        while ($pos + 8 <= $len) {
            $id = substr($bytes, $pos, 4);
            $size = self::u32($bytes, $pos + 4);
            $body = $pos + 8;
            if ($id === 'fmt ') {
                $fmt = substr($bytes, $body, $size);
            } elseif ($id === 'data') {
                // Streamed files leave the size at 0 / 0xFFFFFFFF: take what is there.
                $avail = $len - $body;
                $data = substr($bytes, $body, $size === 0 || $size > $avail ? $avail : $size);
                break;
            }
            $pos = $body + $size + ($size & 1);
        }
        if ($fmt === null || strlen($fmt) < 16) {
            throw new \InvalidArgumentException('WAV file without a valid fmt chunk');
        }
        if ($data === null) {
            throw new \InvalidArgumentException('WAV file without a data chunk');
        }

        $tag = self::u16($fmt, 0);
        $channels = self::u16($fmt, 2);
        $rate = self::u32($fmt, 4);
        $bits = self::u16($fmt, 14);
        if ($tag === self::FORMAT_EXTENSIBLE) {
            if (strlen($fmt) < 26) {
                throw new \InvalidArgumentException('truncated WAVE_FORMAT_EXTENSIBLE header');
            }
            $tag = self::u16($fmt, 24);
        }
        if ($channels < 1 || $rate < 1000) {
            throw new \InvalidArgumentException("implausible WAV header: $channels channels at $rate Hz");
        }
        $kind = match (true) {
            $tag === self::FORMAT_PCM && $bits === 16 => 16,
            $tag === self::FORMAT_PCM && $bits === 24 => 24,
            $tag === self::FORMAT_FLOAT && $bits === 32 => 32,
            default => throw new \InvalidArgumentException("unsupported WAV encoding: format $tag, $bits bit"),
        };

        $frameBytes = $channels * intdiv($kind, 8);
        $frames = intdiv(strlen($data), $frameBytes);
        $samples = self::samples(substr($data, 0, $frames * $frameBytes), $kind);
        $mono = [];
        if ($channels === 1) {
            $mono = $samples;
        } else {
            $inv = 1.0 / $channels;
            for ($f = 0, $o = 0; $f < $frames; $f++, $o += $channels) {
                $s = 0.0;
                for ($c = 0; $c < $channels; $c++) {
                    $s += $samples[$o + $c];
                }
                $mono[] = $s * $inv;
            }
        }
        return Resampler::convert($mono, $rate, $targetRate);
    }

    /** @return list<float> interleaved samples in [-1, 1] */
    private static function samples(string $raw, int $kind): array
    {
        if ($raw === '') {
            return [];
        }
        $out = [];
        if ($kind === 16) {
            foreach (unpack('v*', $raw) ?: [] as $v) {
                $v = is_int($v) ? $v : 0;
                $out[] = ($v >= 0x8000 ? $v - 0x10000 : $v) / 32768.0;
            }
            return $out;
        }
        if ($kind === 32) {
            foreach (unpack('g*', $raw) ?: [] as $v) {
                $out[] = is_float($v) ? $v : 0.0;
            }
            return $out;
        }
        $b = array_map(static fn (mixed $v): int => is_int($v) ? $v : 0, unpack('C*', $raw) ?: []);
        $n = count($b);
        for ($i = 1; $i + 2 <= $n; $i += 3) {
            $v = ($b[$i] ?? 0) | (($b[$i + 1] ?? 0) << 8) | (($b[$i + 2] ?? 0) << 16);
            $out[] = ($v >= 0x800000 ? $v - 0x1000000 : $v) / 8388608.0;
        }
        return $out;
    }

    private static function u16(string $s, int $at): int
    {
        $v = (unpack('v', $s, $at) ?: [])[1] ?? 0;
        return is_int($v) ? $v : 0;
    }

    private static function u32(string $s, int $at): int
    {
        $v = (unpack('V', $s, $at) ?: [])[1] ?? 0;
        return is_int($v) ? $v : 0;
    }
}
