<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Biquad;
use PHPolygon\Audio\Dsp\Fft;
use PHPolygon\Audio\Dsp\Pcm;

/**
 * Removes the breath a speech synthesiser leaves behind the last word.
 *
 * The last voiced speech marks the end of the words ({@see PitchDetector}),
 * what follows is split into sounds at the quiet gaps between them
 * ({@see TailEvent}). The recording is cut in the gap before the earliest
 * sound that is a breath, with only breaths and quiet sounds behind it.
 * Whatever hangs on the voice without a gap stays, so a final fricative after
 * a vowel is out of reach.
 *
 * The voice end is a lower bound only: a synthesiser devoices the last
 * syllable of a sentence often enough that a whole word (or a consonant behind
 * its closure: t, k, ts, ks) can follow it as an "unvoiced" sound behind a
 * short gap. Such sounds are loud, or dark, or start at once, so a breath has
 * to be none of these: it fades in, lasts longer than a release and stays far
 * under the speech. A sound close to the speech level goes only with a long
 * pause and a slow rise. The limits ({@see isBreath()}) were measured on
 * recordings of 30 languages against a transcription before and after.
 *
 * All levels are relative to the speech level of the recording and all times
 * are in ms, so neither the level nor the sample rate matters. When in doubt
 * nothing is cut.
 *
 * Mono at {@see $sampleRate}. Deterministic.
 */
final class TailBreathTrimmer
{
    /** Length of the fade over the end of the kept recording. */
    public const float FADE_MS = 12.0;

    /** Frame hop and window of the level curve. */
    private const float HOP_MS = 5.0;
    private const float WIN_MS = 20.0;
    /** Only this much of the end is searched for the last voiced speech. */
    private const float SEARCH_SECONDS = 3.0;
    /** Rumble under this does not count as sound: it is 24 dB/oct down. */
    private const float HIGH_PASS_HZ = 120.0;
    /** Voiced frames quieter than this under the speech do not end the words. */
    private const float VOICE_END_DB = -25.0;
    /** A sound rises this far over the speech-relative floor, and over the quietest tail frame. */
    private const float EVENT_DB = -45.0;
    private const float EVENT_OVER_FLOOR_DB = 10.0;
    private const float FLOOR_DB = -70.0;
    private const float SILENCE_DB = -90.0;
    /** Dips shorter than this do not split a sound. */
    private const float MERGE_MS = 20.0;

    /** A sound under this is not a breath at all. */
    private const float MIN_DURATION_MS = 150.0;
    private const float MIN_DEPTH_DB = 8.0;
    /** A sentence can end far quieter than it ran: a short sound must also lie under its last voiced stretch. */
    private const float UNDER_VOICE_DB = -12.0;
    private const float VOICE_WINDOW_MS = 300.0;
    /** Clicks and pulse trains: steady noise stays near 3. */
    private const float MAX_KURTOSIS = 6.0;
    /** Faster than this it bursts: a release or a devoiced syllable. */
    private const float SLOW_ATTACK_MS = 30.0;
    /** A dark sound may be a syllable, a hiss may be the s of ts or ks. */
    private const float DARK_SHARE = 0.7;
    private const float HISS_SHARE = 0.5;

    /** Behind a breath, only sounds under this may follow and go with it. */
    private const float FOLLOWER_PEAK_DB = -24.0;
    private const float FOLLOWER_MAX_MS = 500.0;

    private readonly int $hop;
    private readonly int $win;
    private readonly PitchDetector $detector;

    public function __construct(public readonly int $sampleRate = Pcm::SAMPLE_RATE)
    {
        $this->detector = new PitchDetector(sampleRate: $sampleRate);
        $this->hop = (int) round($sampleRate * self::HOP_MS / 1000.0);
        $this->win = (int) round($sampleRate * self::WIN_MS / 1000.0);
    }

    /**
     * @param list<float> $pcm mono at {@see $sampleRate}
     * @return list<float> $pcm itself when nothing is cut, else the kept start with a fade at its end
     */
    public function trim(array $pcm): array
    {
        $cut = $this->analyse($pcm)->cut;
        if ($cut === null) {
            return $pcm;
        }
        $kept = array_slice($pcm, 0, $cut);
        $fade = min($cut, (int) round($this->sampleRate * self::FADE_MS / 1000.0));
        for ($i = 0; $i < $fade; $i++) {
            $kept[$cut - 1 - $i] *= 0.5 - 0.5 * cos(M_PI * ($i + 0.5) / $fade);
        }
        return array_values($kept);
    }

    /** @param list<float> $pcm mono at {@see $sampleRate} */
    public function analyse(array $pcm): TailAnalysis
    {
        $n = count($pcm);
        if ($n < 8 * $this->win) {
            return new TailAnalysis($n, -INF, null, [], null);
        }
        // Rumble and thumps would fill the gaps: keep them out of every measure.
        $filtered = Biquad::highPass(self::HIGH_PASS_HZ, sampleRate: $this->sampleRate)
            ->process(Biquad::highPass(self::HIGH_PASS_HZ, sampleRate: $this->sampleRate)->process($pcm));
        $db = $this->levels($filtered);
        $speech = self::speechLevel($db);
        if ($speech === -INF) {
            return new TailAnalysis($n, -INF, null, [], null);
        }
        $voiceEnd = $this->voiceEnd($pcm, $db, $speech);
        if ($voiceEnd === null) {
            return new TailAnalysis($n, $speech, null, [], null);
        }
        $first = (int) ceil(max(0.0, $voiceEnd - $this->win / 2.0) / $this->hop);
        $events = $this->events($filtered, $db, $first, $speech);
        $voiceDb = $this->voiceLevel($db, $first) - $speech;
        return new TailAnalysis($n, $speech, $voiceEnd, $events, $this->cut($events, $voiceDb));
    }

    /**
     * Loudest frame of the stretch before the voice end, in dBFS.
     *
     * @param list<float> $db
     */
    private function voiceLevel(array $db, int $first): float
    {
        $frames = (int) round(self::VOICE_WINDOW_MS / self::HOP_MS);
        $end = min($first, count($db));
        $level = -INF;
        for ($i = max(0, $end - $frames); $i < $end; $i++) {
            $level = max($level, $db[$i]);
        }
        return $level;
    }

    /**
     * @param list<float> $x
     * @return list<float> level in dBFS per hop, window {@see WIN_MS}
     */
    private function levels(array $x): array
    {
        $sum = [0.0];
        $acc = 0.0;
        foreach ($x as $v) {
            $acc += $v * $v;
            $sum[] = $acc;
        }
        $db = [];
        for ($s = 0, $end = count($x) - $this->win; $s <= $end; $s += $this->hop) {
            $e = ($sum[$s + $this->win] - $sum[$s]) / $this->win;
            $db[] = $e > 1e-16 ? 10.0 * log10($e) : -160.0;
        }
        return $db;
    }

    /**
     * The upper part of the frames within 40 dB of the loudest: what a vowel reaches, not its peak.
     *
     * @param list<float> $db
     */
    private static function speechLevel(array $db): float
    {
        $max = $db === [] ? -INF : max($db);
        if ($max <= -100.0) {
            return -INF;
        }
        $active = array_values(array_filter($db, static fn (float $v): bool => $v > $max - 40.0));
        sort($active);
        return $active[(int) floor(0.8 * (count($active) - 1))];
    }

    /**
     * @param list<float> $pcm
     * @param list<float> $db
     */
    private function voiceEnd(array $pcm, array $db, float $speech): ?int
    {
        $n = count($pcm);
        $from = max(0, $n - (int) ($this->sampleRate * self::SEARCH_SECONDS));
        $track = $this->detector->track(array_slice($pcm, $from));
        $last = count($db) - 1;
        for ($i = count($track->f0) - 1; $i >= 0; $i--) {
            if ($track->f0[$i] <= 0.0) {
                continue;
            }
            $sample = $from + $i * $track->hop;
            $frame = (int) min($last, max(0.0, ($sample - $this->win / 2.0) / $this->hop));
            if ($db[$frame] - $speech >= self::VOICE_END_DB) {
                return min($n, $sample + $track->hop);
            }
        }
        return null;
    }

    /**
     * @param list<float> $x high-passed recording
     * @param list<float> $db
     * @return list<TailEvent>
     */
    private function events(array $x, array $db, int $first, float $speech): array
    {
        $tail = array_slice($db, $first);
        if ($tail === []) {
            return [];
        }
        // Rounding noise is not a level: everything down there counts as the same silence.
        foreach ($tail as $i => $v) {
            $tail[$i] = max($v, $speech + self::SILENCE_DB);
        }
        $floor = max(min($tail), $speech + self::FLOOR_DB);
        $threshold = max($speech + self::EVENT_DB, $floor + self::EVENT_OVER_FLOOR_DB);
        $runs = [];
        $start = -1;
        foreach ($tail as $i => $v) {
            if ($v > $threshold && $start < 0) {
                $start = $i;
            } elseif ($v <= $threshold && $start >= 0) {
                $runs[] = [$start, $i];
                $start = -1;
            }
        }
        if ($start >= 0) {
            $runs[] = [$start, count($tail)];
        }
        /** @var list<array{int, int}> $sounds */
        $sounds = [];
        foreach ($runs as $run) {
            $last = count($sounds) - 1;
            if ($last >= 0 && ($run[0] - $sounds[$last][1]) * self::HOP_MS < self::MERGE_MS) {
                $sounds[$last][1] = $run[1];
            } else {
                $sounds[] = $run;
            }
        }
        $events = [];
        $previousEnd = 0;
        foreach ($sounds as [$a, $b]) {
            $valley = $previousEnd;
            for ($i = $previousEnd; $i < $a; $i++) {
                if ($tail[$i] < $tail[$valley]) {
                    $valley = $i;
                }
            }
            $events[] = $this->event($x, $tail, $first, $a, $b, $valley, $previousEnd, $speech);
            $previousEnd = $b;
        }
        return $events;
    }

    /**
     * @param list<float> $x
     * @param list<float> $tail
     */
    private function event(array $x, array $tail, int $first, int $a, int $b, int $valley, int $previousEnd, float $speech): TailEvent
    {
        $peak = -INF;
        $peakAt = $a;
        for ($i = $a; $i < $b; $i++) {
            if ($tail[$i] > $peak) {
                $peak = $tail[$i];
                $peakAt = $i;
            }
        }
        $rise = $valley;
        for ($i = $peakAt; $i > $valley; $i--) {
            if ($tail[$i] < $peak - 20.0) {
                $rise = $i;
                break;
            }
        }
        $top = $peakAt;
        for ($i = $rise; $i <= $peakAt; $i++) {
            if ($tail[$i] >= $peak - 3.0) {
                $top = $i;
                break;
            }
        }
        $s0 = ($first + $a) * $this->hop;
        $s1 = min(count($x), ($first + $b - 1) * $this->hop + $this->win);
        [$low, $high] = $this->spectrum($x, $s0, $s1);
        return new TailEvent(
            startSample: $s0,
            endSample: $s1,
            valleySample: ($first + $valley) * $this->hop + intdiv($this->win, 2),
            durationMs: ($s1 - $s0) * 1000.0 / $this->sampleRate,
            gapMs: ($a - $previousEnd) * self::HOP_MS,
            valleyDb: $tail[$valley] - $speech,
            peakDb: $peak - $speech,
            attackMs: ($top - $rise) * self::HOP_MS,
            kurtosis: $this->kurtosis($x, $s0, $s1),
            lowShare: $low,
            highShare: $high,
        );
    }

    /** @param list<float> $x */
    private function kurtosis(array $x, int $from, int $to): float
    {
        $frame = (int) ($this->sampleRate * 0.01);
        $sum = 0.0;
        $count = 0;
        for ($s = $from; $s + $frame <= $to; $s += $frame) {
            $m2 = 0.0;
            $m4 = 0.0;
            for ($i = $s, $e = $s + $frame; $i < $e; $i++) {
                $q = $x[$i] * $x[$i];
                $m2 += $q;
                $m4 += $q * $q;
            }
            if ($m2 > 0.0) {
                $sum += $m4 * $frame / ($m2 * $m2);
                $count++;
            }
        }
        return $count > 0 ? $sum / $count : 0.0;
    }

    /**
     * Shares of the energy between 0.3 and 12 kHz (or Nyquist) below 1 kHz and above 4.5 kHz.
     *
     * @param list<float> $x
     * @return array{float, float}
     */
    private function spectrum(array $x, int $from, int $to): array
    {
        $size = 512;
        while ($size * 40 < $this->sampleRate) {
            $size *= 2;
        }
        $span = $to - $from - $size;
        $steps = $span > 0 ? min(24, 1 + intdiv($span, intdiv($size, 2))) : 1;
        $bin = $this->sampleRate / $size;
        $lo = (int) ceil(300.0 / $bin);
        $mid = (int) floor(1000.0 / $bin);
        $hiStart = (int) ceil(4500.0 / $bin);
        $top = (int) min(floor(12000.0 / $bin), intdiv($size, 2));
        $low = $high = $total = 0.0;
        for ($t = 0; $t < $steps; $t++) {
            $offset = $from + ($steps > 1 ? (int) ($span * $t / ($steps - 1)) : 0);
            $re = [];
            for ($i = 0; $i < $size; $i++) {
                $re[] = ($x[$offset + $i] ?? 0.0) * (0.5 - 0.5 * cos(2.0 * M_PI * $i / ($size - 1)));
            }
            $im = array_fill(0, $size, 0.0);
            Fft::transform($re, $im);
            for ($i = $lo; $i <= $top; $i++) {
                $p = $re[$i] * $re[$i] + $im[$i] * $im[$i];
                $total += $p;
                if ($i <= $mid) {
                    $low += $p;
                } elseif ($i >= $hiStart) {
                    $high += $p;
                }
            }
        }
        return $total > 0.0 ? [$low / $total, $high / $total] : [0.0, 0.0];
    }

    /** @param list<TailEvent> $events */
    private function cut(array $events, float $voiceDb): ?int
    {
        $cut = null;
        for ($i = count($events) - 1; $i >= 0; $i--) {
            $event = $events[$i];
            if (self::isBreath($event, $voiceDb)) {
                $cut = $event->valleySample;
            } elseif ($event->durationMs >= self::FOLLOWER_MAX_MS || $event->peakDb > self::FOLLOWER_PEAK_DB
                || $event->peakDb - $voiceDb > self::UNDER_VOICE_DB) {
                break;
            }
        }
        return $cut;
    }

    /**
     * Word ends measured behind a gap, against which these limits stand:
     * "takk" 335 ms at −21 dB, rise 10 ms; the s of ks and ts up to 290 ms behind
     * a gap of 20–45 ms, −25 to −1 dB; a devoiced "-buch" 520 ms at −14 dB, dark;
     * a mumbled last word at −25 dB behind a sentence that ended 17 dB down.
     *
     * @param float $voiceDb level of the last voiced stretch
     */
    private static function isBreath(TailEvent $e, float $voiceDb): bool
    {
        if ($e->durationMs < self::MIN_DURATION_MS || $e->kurtosis > self::MAX_KURTOSIS
            || $e->peakDb - $e->valleyDb < self::MIN_DEPTH_DB) {
            return false;
        }
        $dark = $e->lowShare > self::DARK_SHARE;
        $quiet = $e->peakDb - $voiceDb <= self::UNDER_VOICE_DB;
        if ($e->attackMs < self::SLOW_ATTACK_MS) {
            return $quiet && !$dark && $e->durationMs >= 400.0 && $e->gapMs >= 100.0 && $e->peakDb <= -24.0;
        }
        if ($dark) {
            return $quiet && $e->gapMs >= 150.0 && $e->peakDb <= -24.0;
        }
        if ($e->highShare > self::HISS_SHARE && $e->gapMs < 100.0 && $e->durationMs < 300.0) {
            return false;
        }
        if ($quiet && (($e->gapMs >= 100.0 && $e->peakDb <= -20.0)
            || ($e->gapMs >= 40.0 && $e->peakDb <= -22.0)
            || ($e->gapMs >= 20.0 && $e->peakDb <= -24.0 && $e->durationMs >= 250.0 && $e->attackMs >= 60.0))) {
            return true;
        }
        // Near the speech level only what no syllable does: a long, slow swell.
        return ($e->gapMs >= 40.0 && $e->peakDb <= -10.0 && $e->durationMs >= 450.0 && $e->attackMs >= 150.0)
            || ($e->gapMs >= 150.0 && $e->peakDb <= -6.0 && $e->durationMs >= 300.0 && $e->attackMs >= 100.0);
    }
}
