<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp;

/**
 * Shared PCM conventions of the offline audio tools: the default sample rate
 * and bit depth, the one-pole filter coefficient, float → 16-bit conversion
 * and two small unit helpers.
 *
 * Every class in {@see \PHPolygon\Audio\Dsp} and {@see \PHPolygon\Audio\Codec}
 * defaults to {@see SAMPLE_RATE}; all of them take another rate as a parameter.
 */
final class Pcm
{
    public const int SAMPLE_RATE = 44100;
    public const int BITS_PER_SAMPLE = 16;

    private function __construct() {}

    /** Coefficient of a one-pole low-pass at $cutoff Hz: y += a·(x − y). */
    public static function lpCoeff(float $cutoff, int $sampleRate = self::SAMPLE_RATE): float
    {
        $rc = 1.0 / (2.0 * M_PI * $cutoff);
        $dt = 1.0 / $sampleRate;
        return $dt / ($rc + $dt);
    }

    /**
     * $hz, held below the Nyquist frequency of $sampleRate: for filters of a recipe
     * written without knowing the rate it will be rendered at.
     */
    public static function belowNyquist(float $hz, int $sampleRate): float
    {
        return min($hz, 0.45 * $sampleRate);
    }

    /** Clamp to ±1 and truncate to a 16-bit little-endian sample. */
    public static function packSample(float $sample): string
    {
        $clamped = max(-1.0, min(1.0, $sample));
        $int16 = (int) ($clamped * 32767);
        return pack('v', $int16 & 0xFFFF);
    }

    /** Clamp to ±1 and round to a signed 16-bit integer (symmetric: ±32767). */
    public static function toInt16(float $sample): int
    {
        if ($sample >= 1.0) {
            return 32767;
        }
        if ($sample <= -1.0) {
            return -32767;
        }
        return (int) round($sample * 32767.0);
    }

    /** MIDI note number → frequency in Hz (A4 = 69 = 440 Hz). */
    public static function hz(float $midi): float
    {
        return 440.0 * 2.0 ** (($midi - 69.0) / 12.0);
    }

    /** Decibels → linear gain. */
    public static function db(float $db): float
    {
        return 10.0 ** ($db / 20.0);
    }
}
