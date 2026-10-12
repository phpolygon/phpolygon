<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Fft;
use PHPolygon\Audio\Dsp\Resampler;

/**
 * The measurable fingerprint of a voice – what {@see VoiceMatcher} steers a
 * recording toward. Small and serialisable, so a build can keep a JSON file
 * per voice instead of the reference audio. Independent of the sample rate.
 *
 *  - medianF0, f0P10, f0P90: pitch and melodic range over the voiced frames
 *    ({@see PitchDetector})
 *  - formants: F1–F3 in Hz (trimmed log-mean over the voiced frames) from 10th-order LPC
 *    (11.025 kHz, pre-emphasis, polynomial roots with bandwidth < 400 Hz)
 *  - ltasDb: long-term average spectrum of the voiced frames in 1/6-octave
 *    bands from 80 Hz ({@see bandCentres()}), relative to its 200 Hz–5 kHz mean
 */
final readonly class VoiceReference
{
    public const int VERSION = 1;
    private const int LPC_ORDER = 10;
    private const int LPC_DECIMATE = 4;
    private const int LPC_WINDOW = 256;
    private const int LTAS_FFT = 4096;
    /** Weights of F1–F3 when two voices are compared: F1 follows the vowel more than the speaker. */
    private const array FORMANT_WEIGHTS = [1.0, 2.0, 2.0];

    /**
     * @param list<float> $formants F1, F2, F3 in Hz
     * @param list<float> $ltasDb one value per {@see bandCentres()} entry
     */
    public function __construct(
        public float $medianF0,
        public float $f0P10,
        public float $f0P90,
        public array $formants,
        public array $ltasDb,
    ) {
        if (count($formants) !== 3 || count($ltasDb) !== count(self::bandCentres())) {
            throw new \InvalidArgumentException('voice reference needs 3 formants and one LTAS value per band');
        }
        if ($medianF0 <= 0.0 || $f0P10 <= 0.0 || $f0P90 < $f0P10) {
            throw new \InvalidArgumentException("implausible pitch statistics $f0P10 / $medianF0 / $f0P90 Hz");
        }
    }

    /** @return list<float> 1/6-octave band centres from 80 Hz up to 10 kHz */
    public static function bandCentres(): array
    {
        $out = [];
        for ($k = 0; ($c = 80.0 * 2.0 ** ($k / 6.0)) <= 10000.0; $k++) {
            $out[] = $c;
        }
        return $out;
    }

    /**
     * @param list<float> $pcm mono at the detector's sample rate (default 44.1 kHz)
     * @throws \InvalidArgumentException when nothing in it is voiced
     */
    public static function analyse(array $pcm, ?PitchDetector $detector = null): self
    {
        $detector ??= new PitchDetector();
        $track = $detector->track($pcm);
        $voiced = array_values(array_filter($track->f0, static fn (float $f): bool => $f > 0.0));
        if ($voiced === []) {
            throw new \InvalidArgumentException('no voiced speech to analyse');
        }
        sort($voiced);

        return new self(
            self::percentile($voiced, 0.5),
            self::percentile($voiced, 0.1),
            self::percentile($voiced, 0.9),
            self::formantsOf($pcm, $track, $detector->sampleRate),
            self::ltasOf($pcm, $track, $detector->sampleRate),
        );
    }

    /**
     * Formant scale factor from this voice to $other (weighted geometric mean
     * of F_k(other) / F_k(this)): > 1 means $other has the smaller vocal tract.
     */
    public function formantRatioTo(self $other): float
    {
        $sum = 0.0;
        $wsum = 0.0;
        foreach (self::FORMANT_WEIGHTS as $k => $w) {
            $sum += $w * log($other->formants[$k] / $this->formants[$k]);
            $wsum += $w;
        }
        return exp($sum / $wsum);
    }

    /** RMS difference of the two spectra in dB over the bands from 150 Hz to 8 kHz. */
    public function ltasDeviation(self $other): float
    {
        $sum = 0.0;
        $count = 0;
        foreach (self::bandCentres() as $k => $c) {
            if ($c < 150.0 || $c > 8000.0) {
                continue;
            }
            $d = $this->ltasDb[$k] - $other->ltasDb[$k];
            $sum += $d * $d;
            $count++;
        }
        return sqrt($sum / max(1, $count));
    }

    /** @return array{version: int, medianF0: float, f0P10: float, f0P90: float, formants: list<float>, bandsHz: list<float>, ltasDb: list<float>} */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'medianF0' => $this->medianF0,
            'f0P10' => $this->f0P10,
            'f0P90' => $this->f0P90,
            'formants' => $this->formants,
            'bandsHz' => self::bandCentres(),
            'ltasDb' => $this->ltasDb,
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('unsupported voice reference version');
        }
        $num = static function (mixed $v, string $name): float {
            if (!is_int($v) && !is_float($v)) {
                throw new \InvalidArgumentException("voice reference field $name is not a number");
            }
            return (float) $v;
        };
        $list = static function (mixed $v, string $name) use ($num): array {
            if (!is_array($v) || !array_is_list($v)) {
                throw new \InvalidArgumentException("voice reference field $name is not a list");
            }
            return array_map(static fn (mixed $x): float => $num($x, $name), $v);
        };
        return new self(
            $num($data['medianF0'] ?? null, 'medianF0'),
            $num($data['f0P10'] ?? null, 'f0P10'),
            $num($data['f0P90'] ?? null, 'f0P90'),
            $list($data['formants'] ?? null, 'formants'),
            $list($data['ltasDb'] ?? null, 'ltasDb'),
        );
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('voice reference is not a JSON object');
        }
        return self::fromArray($data);
    }

    /** @param list<float> $sorted */
    private static function percentile(array $sorted, float $q): float
    {
        $pos = $q * (count($sorted) - 1);
        $i = (int) floor($pos);
        $j = min(count($sorted) - 1, $i + 1);
        return $sorted[$i] + ($sorted[$j] - $sorted[$i]) * ($pos - $i);
    }

    /**
     * Median F1–F3 over the voiced frames.
     *
     * @param list<float> $pcm
     * @return list<float>
     */
    private static function formantsOf(array $pcm, PitchTrack $track, int $sampleRate): array
    {
        $x = Resampler::stretch($pcm, 1.0 / self::LPC_DECIMATE, 8);
        $sr = $sampleRate / self::LPC_DECIMATE;
        $n = count($x);
        $win = self::LPC_WINDOW;
        $hann = [];
        for ($i = 0; $i < $win; $i++) {
            $hann[] = 0.5 - 0.5 * cos(2.0 * M_PI * $i / ($win - 1));
        }
        $found = [[], [], []];
        $frames = count($track->f0);
        for ($fi = 0; $fi < $frames; $fi++) {
            if ($track->f0[$fi] <= 0.0) {
                continue;
            }
            $start = intdiv($fi * $track->hop, self::LPC_DECIMATE) - intdiv($win, 2);
            if ($start < 1 || $start + $win > $n) {
                continue;
            }
            $frame = [];
            for ($i = 0; $i < $win; $i++) {
                $frame[] = ($x[$start + $i] - 0.97 * $x[$start + $i - 1]) * $hann[$i];
            }
            $a = self::lpc($frame);
            if ($a === null) {
                continue;
            }
            $peaks = self::resonances($a, $sr);
            if (count($peaks) >= 3) {
                for ($k = 0; $k < 3; $k++) {
                    $found[$k][] = $peaks[$k];
                }
            }
        }
        if ($found[0] === []) {
            throw new \InvalidArgumentException('no frame with three formants found');
        }
        $out = [];
        // Speech mixes vowels, so each formant has several clusters: a median
        // flips between them, a trimmed log-mean moves smoothly with the voice.
        foreach ($found as $list) {
            sort($list);
            $cut = intdiv(count($list), 10);
            $kept = array_slice($list, $cut, count($list) - 2 * $cut);
            $out[] = exp(array_sum(array_map('log', $kept)) / count($kept));
        }
        return $out;
    }

    /**
     * Formant candidates of an LPC polynomial: roots in the upper half plane
     * (Durand–Kerner), kept when 90 Hz < f < Nyquist − 50 Hz and the bandwidth
     * is under 400 Hz, sorted by frequency.
     *
     * @param list<float> $a
     * @return list<float>
     */
    private static function resonances(array $a, float $sr): array
    {
        $p = count($a) - 1;
        $zr = [];
        $zi = [];
        // Standard start: powers of a complex number that is neither real nor a root of unity.
        $br = 0.4;
        $bi = 0.9;
        $cr = 1.0;
        $ci = 0.0;
        for ($i = 0; $i < $p; $i++) {
            $zr[] = $cr;
            $zi[] = $ci;
            [$cr, $ci] = [$cr * $br - $ci * $bi, $cr * $bi + $ci * $br];
        }
        for ($iter = 0; $iter < 200; $iter++) {
            $moved = 0.0;
            for ($i = 0; $i < $p; $i++) {
                // p(z) = z^p + a1 z^(p−1) + … + ap (Horner)
                $vr = 1.0;
                $vi = 0.0;
                for ($k = 1; $k <= $p; $k++) {
                    [$vr, $vi] = [$vr * $zr[$i] - $vi * $zi[$i] + $a[$k], $vr * $zi[$i] + $vi * $zr[$i]];
                }
                $dr = 1.0;
                $di = 0.0;
                for ($j = 0; $j < $p; $j++) {
                    if ($j === $i) {
                        continue;
                    }
                    $er = $zr[$i] - $zr[$j];
                    $ei = $zi[$i] - $zi[$j];
                    [$dr, $di] = [$dr * $er - $di * $ei, $dr * $ei + $di * $er];
                }
                $den = $dr * $dr + $di * $di;
                if ($den < 1e-300) {
                    continue;
                }
                $qr = ($vr * $dr + $vi * $di) / $den;
                $qi = ($vi * $dr - $vr * $di) / $den;
                $zr[$i] -= $qr;
                $zi[$i] -= $qi;
                $moved = max($moved, abs($qr) + abs($qi));
            }
            if ($moved < 1e-10) {
                break;
            }
        }
        $out = [];
        for ($i = 0; $i < $p; $i++) {
            if ($zi[$i] <= 0.0) {
                continue;
            }
            $f = atan2($zi[$i], $zr[$i]) * $sr / (2.0 * M_PI);
            $r = sqrt($zr[$i] * $zr[$i] + $zi[$i] * $zi[$i]);
            $bw = $r > 0.0 ? -log($r) * $sr / M_PI : INF;
            if ($f > 90.0 && $f < $sr / 2.0 - 50.0 && $bw < 400.0) {
                $out[] = $f;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Autocorrelation LPC (Levinson–Durbin): a[0] = 1, A(z) = Σ a_k z^−k.
     *
     * @param list<float> $frame
     * @return list<float>|null null for a silent frame
     */
    private static function lpc(array $frame): ?array
    {
        $p = self::LPC_ORDER;
        $n = count($frame);
        $r = [];
        for ($lag = 0; $lag <= $p; $lag++) {
            $s = 0.0;
            for ($i = $lag; $i < $n; $i++) {
                $s += $frame[$i] * $frame[$i - $lag];
            }
            $r[] = $s;
        }
        if ($r[0] <= 1e-12) {
            return null;
        }
        $r[0] *= 1.0 + 1e-9;
        $a = array_fill(0, $p + 1, 0.0);
        $a[0] = 1.0;
        $err = $r[0];
        for ($i = 1; $i <= $p; $i++) {
            $acc = $r[$i];
            for ($j = 1; $j < $i; $j++) {
                $acc += $a[$j] * $r[$i - $j];
            }
            $k = -$acc / $err;
            $prev = $a;
            for ($j = 1; $j < $i; $j++) {
                $a[$j] = $prev[$j] + $k * $prev[$i - $j];
            }
            $a[$i] = $k;
            $err *= 1.0 - $k * $k;
            if ($err <= 0.0) {
                return null;
            }
        }
        return $a;
    }

    /**
     * Voiced-frame LTAS in 1/6-octave bands, relative to the 200 Hz–5 kHz mean.
     *
     * @param list<float> $pcm
     * @return list<float>
     */
    private static function ltasOf(array $pcm, PitchTrack $track, int $sampleRate): array
    {
        $size = self::LTAS_FFT;
        $n = count($pcm);
        $hann = [];
        for ($i = 0; $i < $size; $i++) {
            $hann[] = 0.5 - 0.5 * cos(2.0 * M_PI * $i / $size);
        }
        $power = array_fill(0, intdiv($size, 2) + 1, 0.0);
        // Hop of half a frame; a frame counts when its centre is voiced.
        for ($s = 0; $s + $size <= $n; $s += intdiv($size, 2)) {
            if (!$track->isVoiced($s + intdiv($size, 2))) {
                continue;
            }
            $re = [];
            for ($i = 0; $i < $size; $i++) {
                $re[] = $pcm[$s + $i] * $hann[$i];
            }
            $im = array_fill(0, $size, 0.0);
            Fft::transform($re, $im);
            for ($k = 0, $half = intdiv($size, 2); $k <= $half; $k++) {
                $power[$k] += $re[$k] * $re[$k] + $im[$k] * $im[$k];
            }
        }
        if ($n < $size && $n > 0) {
            // Too short for one full frame: zero-pad the whole clip.
            $re = array_pad(array_map(static fn (float $v, float $w): float => $v * $w, $pcm, array_slice($hann, 0, $n)), $size, 0.0);
            $im = array_fill(0, $size, 0.0);
            Fft::transform($re, $im);
            for ($k = 0, $half = intdiv($size, 2); $k <= $half; $k++) {
                $power[$k] += $re[$k] * $re[$k] + $im[$k] * $im[$k];
            }
        }

        $binHz = $sampleRate / $size;
        $db = [];
        foreach (self::bandCentres() as $c) {
            $lo = $c * 2.0 ** (-1.0 / 12.0);
            $hi = $c * 2.0 ** (1.0 / 12.0);
            $sum = 0.0;
            $cnt = 0;
            for ($k = (int) ceil($lo / $binHz); $k * $binHz < $hi; $k++) {
                $sum += $power[$k];
                $cnt++;
            }
            if ($cnt === 0) {
                $pos = $c / $binHz;
                $k = (int) floor($pos);
                $sum = $power[$k] + ($power[$k + 1] - $power[$k]) * ($pos - $k);
                $cnt = 1;
            }
            $db[] = 10.0 * log10($sum / $cnt + 1e-20);
        }
        $ref = 0.0;
        $refCount = 0;
        foreach (self::bandCentres() as $k => $c) {
            if ($c >= 200.0 && $c <= 5000.0) {
                $ref += $db[$k];
                $refCount++;
            }
        }
        $ref /= max(1, $refCount);
        return array_map(static fn (float $v): float => $v - $ref, $db);
    }
}
