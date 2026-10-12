<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp;

use PHPolygon\Audio\Dsp\Pcm;

/**
 * Test signals and measurements for the audio DSP: tones, a synthetic vowel
 * (harmonics weighted by formant resonances – no aliasing, exact f0), WAV byte
 * builders in every format the reader accepts, and a small FFT for spectral
 * checks.
 */
final class Signals
{
    private function __construct() {}

    /** @return list<float> */
    public static function sine(float $hz, float $seconds, float $amp = 0.5, int $sr = Pcm::SAMPLE_RATE): array
    {
        $n = (int) round($seconds * $sr);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $amp * sin(2.0 * M_PI * $hz * $i / $sr);
        }
        return $out;
    }

    /**
     * A sung vowel the source-filter way: a band-limited pulse train (all
     * harmonics of $f0 up to 8 kHz, equal amplitude) through one two-pole
     * resonator per formant – what LPC models exactly. $glide multiplies f0
     * linearly over the clip (1.0 = steady); the formants stay put.
     *
     * @param list<array{float, float}> $formants [centre Hz, bandwidth Hz]
     * @return list<float>
     */
    public static function vowel(
        float $f0,
        float $seconds,
        array $formants = [[700.0, 110.0], [1220.0, 120.0], [2600.0, 160.0]],
        float $glide = 1.0,
        float $amp = 0.5,
        int $sampleRate = Pcm::SAMPLE_RATE,
    ): array {
        $sr = $sampleRate;
        $n = (int) round($seconds * $sr);
        $maxK = (int) floor(8000.0 / min($f0, $f0 * $glide));
        $out = [];
        $phase = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $f = $f0 * (1.0 + ($glide - 1.0) * $i / max(1, $n - 1));
            $phase += 2.0 * M_PI * $f / $sr;
            $v = 0.0;
            for ($k = 1; $k <= $maxK; $k++) {
                if ($k * $f >= 8000.0) {
                    break;
                }
                $v += cos($k * $phase);
            }
            $out[] = $v;
        }
        foreach ($formants as [$fc, $bw]) {
            $r = exp(-M_PI * $bw / $sr);
            $c1 = 2.0 * $r * cos(2.0 * M_PI * $fc / $sr);
            $c2 = -$r * $r;
            $g = 1.0 - $c1 - $c2;
            $y1 = $y2 = 0.0;
            foreach ($out as $i => $x) {
                $y = $g * $x + $c1 * $y1 + $c2 * $y2;
                $y2 = $y1;
                $y1 = $y;
                $out[$i] = $y;
            }
        }
        $peak = max(1e-12, max(array_map('abs', $out)));
        foreach ($out as $i => $v) {
            $out[$i] = $v * $amp / $peak;
        }
        return $out;
    }
    /** Formants [Hz, bandwidth] of the vowels a, i, u, e (adult, average). */
    public const array VOWELS = [
        'a' => [[730.0, 110.0], [1090.0, 120.0], [2440.0, 160.0]],
        'i' => [[290.0, 70.0], [2250.0, 140.0], [3000.0, 200.0]],
        'u' => [[320.0, 80.0], [870.0, 100.0], [2240.0, 160.0]],
        'e' => [[530.0, 90.0], [1840.0, 130.0], [2480.0, 160.0]],
    ];

    /**
     * A synthetic "speaker": the four vowels in turn, each with a rising or
     * falling intonation around $f0 (±$swing), short pauses and a hiss burst
     * between them. $formantScale moves every resonance (vocal-tract size).
     *
     * @return list<float>
     */
    public static function speech(float $f0, float $formantScale = 1.0, float $swing = 0.12, float $amp = 0.4): array
    {
        $out = [];
        $gap = array_fill(0, (int) (0.06 * Pcm::SAMPLE_RATE), 0.0);
        $k = 0;
        foreach (self::VOWELS as $formants) {
            $scaled = array_map(static fn (array $f): array => [$f[0] * $formantScale, $f[1] * $formantScale], $formants);
            $up = $k % 2 === 0;
            $start = $f0 * ($up ? 1.0 - $swing : 1.0 + $swing);
            $glide = $up ? (1.0 + $swing) / (1.0 - $swing) : (1.0 - $swing) / (1.0 + $swing);
            array_push($out, ...self::vowel($start, 0.35, $scaled, $glide, $amp));
            array_push($out, ...$gap);
            array_push($out, ...self::noise(0.05, $amp * 0.15, 11 + $k));
            array_push($out, ...$gap);
            $k++;
        }
        return $out;
    }

    /** @return list<float> deterministic white noise */
    public static function noise(float $seconds, float $amp = 0.3, int $seed = 7): array
    {
        $n = (int) round($seconds * Pcm::SAMPLE_RATE);
        $s = $seed;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;
            $out[] = (($s / 1073741824.0) - 1.0) * $amp;
        }
        return $out;
    }

    /**
     * A RIFF/WAVE file. $format: 'pcm16', 'pcm24', 'float32', 'extensible16'.
     *
     * @param list<list<float>> $channels one list per channel, equal length
     */
    public static function wav(array $channels, int $sampleRate, string $format = 'pcm16', bool $extraChunk = false): string
    {
        $nch = count($channels);
        $n = count($channels[0]);
        [$tag, $bits] = match ($format) {
            'pcm16', 'extensible16' => [1, 16],
            'pcm24' => [1, 24],
            'float32' => [3, 32],
            default => throw new \InvalidArgumentException($format),
        };
        $data = '';
        for ($i = 0; $i < $n; $i++) {
            for ($c = 0; $c < $nch; $c++) {
                $v = max(-1.0, min(1.0, $channels[$c][$i]));
                $data .= match ($bits) {
                    16 => pack('v', min(32767, (int) round($v * 32768)) & 0xFFFF),
                    24 => substr(pack('V', min(8388607, (int) round($v * 8388608)) & 0xFFFFFF), 0, 3),
                    default => pack('g', $v),
                };
            }
        }
        $block = $nch * $bits / 8;
        if ($format === 'extensible16') {
            $fmt = pack('vvVVvv', 0xFFFE, $nch, $sampleRate, $sampleRate * $block, $block, $bits)
                . pack('vvV', 22, $bits, 0) . pack('v', 1) . "\x00\x00\x00\x00\x10\x00\x80\x00\x00\xAA\x00\x38\x9B\x71";
        } else {
            $fmt = pack('vvVVvv', $tag, $nch, $sampleRate, $sampleRate * $block, $block, $bits);
        }
        $body = 'WAVE' . 'fmt ' . pack('V', strlen($fmt)) . $fmt;
        if ($extraChunk) {
            $body .= 'LIST' . pack('V', 5) . "INFOx\x00"; // odd size + pad byte
        }
        $body .= 'data' . pack('V', strlen($data)) . $data;
        return 'RIFF' . pack('V', strlen($body)) . $body;
    }

    /** @param list<float> $x */
    public static function rms(array $x, int $from = 0, ?int $to = null): float
    {
        $to ??= count($x);
        $s = 0.0;
        for ($i = $from; $i < $to; $i++) {
            $s += $x[$i] * $x[$i];
        }
        return sqrt($s / max(1, $to - $from));
    }

    /**
     * Averaged magnitude spectrum (Hann, 4096-point frames, 50 % overlap)
     * over [$from, $to) – bin k is k·SR/4096 Hz.
     *
     * @param list<float> $x
     * @return list<float>
     */
    public static function spectrum(array $x, int $from = 0, ?int $to = null): array
    {
        $size = 4096;
        $to ??= count($x);
        $acc = array_fill(0, $size / 2, 0.0);
        $frames = 0;
        for ($s = $from; $s + $size <= $to; $s += $size / 2) {
            $re = [];
            $im = array_fill(0, $size, 0.0);
            for ($i = 0; $i < $size; $i++) {
                $re[] = $x[$s + $i] * (0.5 - 0.5 * cos(2.0 * M_PI * $i / $size));
            }
            self::fft($re, $im);
            for ($k = 0; $k < $size / 2; $k++) {
                $acc[$k] += sqrt($re[$k] * $re[$k] + $im[$k] * $im[$k]);
            }
            $frames++;
        }
        foreach ($acc as $k => $v) {
            $acc[$k] = $v / max(1, $frames);
        }
        return $acc;
    }

    /**
     * Spectral centroid of the smoothed envelope between $loHz and $hiHz.
     *
     * @param list<float> $x
     */
    public static function centroid(array $x, float $loHz = 200.0, float $hiHz = 5000.0, int $from = 0, ?int $to = null): float
    {
        $spec = self::spectrum($x, $from, $to);
        $binHz = Pcm::SAMPLE_RATE / 4096;
        $num = 0.0;
        $den = 0.0;
        foreach ($spec as $k => $m) {
            $f = $k * $binHz;
            if ($f < $loHz || $f > $hiHz) {
                continue;
            }
            $p = $m * $m;
            $num += $f * $p;
            $den += $p;
        }
        return $den > 0.0 ? $num / $den : 0.0;
    }

    /**
     * Magnitude of the DFT at $hz over [$from, $to).
     *
     * @param list<float> $x
     */
    public static function at(array $x, float $hz, int $from, int $to): float
    {
        $re = 0.0;
        $im = 0.0;
        for ($i = $from; $i < $to; $i++) {
            $re += $x[$i] * cos(2.0 * M_PI * $hz * $i / Pcm::SAMPLE_RATE);
            $im += $x[$i] * sin(2.0 * M_PI * $hz * $i / Pcm::SAMPLE_RATE);
        }
        return sqrt($re * $re + $im * $im) / max(1, $to - $from);
    }

    /**
     * In-place radix-2 FFT.
     *
     * @param array<int, float> $re
     * @param array<int, float> $im
     * @param-out array<int, float> $re
     * @param-out array<int, float> $im
     */
    public static function fft(array &$re, array &$im): void
    {
        $n = count($re);
        for ($i = 1, $j = 0; $i < $n; $i++) {
            $bit = $n >> 1;
            for (; ($j & $bit) !== 0; $bit >>= 1) {
                $j ^= $bit;
            }
            $j ^= $bit;
            if ($i < $j) {
                [$re[$i], $re[$j]] = [$re[$j], $re[$i]];
                [$im[$i], $im[$j]] = [$im[$j], $im[$i]];
            }
        }
        for ($len = 2; $len <= $n; $len <<= 1) {
            $ang = -2.0 * M_PI / $len;
            $wr = cos($ang);
            $wi = sin($ang);
            for ($i = 0; $i < $n; $i += $len) {
                $cr = 1.0;
                $ci = 0.0;
                for ($k = 0; $k < $len / 2; $k++) {
                    $a = $i + $k;
                    $b = $a + $len / 2;
                    $tr = $re[$b] * $cr - $im[$b] * $ci;
                    $ti = $re[$b] * $ci + $im[$b] * $cr;
                    $re[$b] = $re[$a] - $tr;
                    $im[$b] = $im[$a] - $ti;
                    $re[$a] += $tr;
                    $im[$a] += $ti;
                    $t = $cr * $wr - $ci * $wi;
                    $ci = $cr * $wi + $ci * $wr;
                    $cr = $t;
                }
            }
        }
    }
}
