<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

/**
 * When each stem of a layered piece sounds and how loud: starts with a
 * fade-in, fade-outs, crossfades and hard cuts, on one clock (seconds).
 *
 * Pure arithmetic – no audio, no clock of its own. {@see StemPlayer} plays a
 * schedule live; an offline mix asks {@see gainAt()} for the same numbers, so
 * the game and a rendered video share one arrangement. Commands arrive from
 * outside as the piece's marks are reached; as long as every command is given
 * no later than its time, the gain at t depends only on what was known at t.
 *
 * A stem's gain at t is `gain × fade-in(t) × Π fade-out(t)`: every fade-out
 * (its own end, a crossfade, a cut) multiplies, so a cut also shortens a
 * stem that is already fading. Once any fade-out has reached zero the stem is
 * over for good.
 */
final class StemSchedule
{
    /** Default length of a hard cut (s): short enough to read as a cut, long enough not to click. */
    public const float CUT_SECONDS = 0.06;

    /** @var array<string, array{start: float, fadeIn: float, gain: float, outs: list<array{at: float, seconds: float}>}> */
    private array $stems = [];

    /**
     * Start $stem at $at, rising linearly to $gain over $fadeIn seconds.
     * A stem starts once; later starts are ignored (returns false).
     */
    public function start(string $stem, float $at, float $fadeIn = 0.0, float $gain = 1.0): bool
    {
        if (isset($this->stems[$stem])) {
            return false;
        }
        $this->stems[$stem] = ['start' => $at, 'fadeIn' => max(0.0, $fadeIn), 'gain' => max(0.0, $gain), 'outs' => []];
        return true;
    }

    /** Fade $stem out linearly from $at over $seconds (0: stop at $at). Ignored for unknown stems. */
    public function fadeOut(string $stem, float $at, float $seconds): void
    {
        if (!isset($this->stems[$stem])) {
            return;
        }
        $this->stems[$stem]['outs'][] = ['at' => $at, 'seconds' => max(0.0, $seconds)];
    }

    /**
     * Fade $from out and $to in at the same moment. $fadeIn defaults to the
     * fade-out's length (an equal crossfade).
     */
    public function crossfade(string $from, string $to, float $at, float $seconds, float $gain = 1.0, ?float $fadeIn = null): void
    {
        $this->fadeOut($from, $at, $seconds);
        $this->start($to, $at, $fadeIn ?? $seconds, $gain);
    }

    /**
     * Cut every stem that started before $at (or only $only) within
     * $seconds. Stems scheduled to start at or after $at are not affected.
     *
     * @param list<string>|null $only
     */
    public function cut(float $at, float $seconds = self::CUT_SECONDS, ?array $only = null): void
    {
        foreach ($this->stems as $stem => $s) {
            if ($s['start'] >= $at || ($only !== null && !in_array((string) $stem, $only, true))) {
                continue;
            }
            $this->fadeOut((string) $stem, $at, $seconds);
        }
    }

    /** Change a stem's full gain (fades keep their shape). */
    public function setGain(string $stem, float $gain): void
    {
        if (isset($this->stems[$stem])) {
            $this->stems[$stem]['gain'] = max(0.0, $gain);
        }
    }

    /** Gain of $stem at $t (0 before its start, after its end or when unknown). */
    public function gainAt(string $stem, float $t): float
    {
        $s = $this->stems[$stem] ?? null;
        if ($s === null || $t < $s['start']) {
            return 0.0;
        }
        $g = $s['gain'];
        if ($s['fadeIn'] > 0.0) {
            $g *= min(1.0, ($t - $s['start']) / $s['fadeIn']);
        }
        foreach ($s['outs'] as $out) {
            if ($t < $out['at']) {
                continue;
            }
            $g *= $out['seconds'] > 0.0 ? max(0.0, 1.0 - ($t - $out['at']) / $out['seconds']) : 0.0;
        }
        return $g;
    }

    /** When $stem starts, null when it has not been scheduled. */
    public function startOf(string $stem): ?float
    {
        return isset($this->stems[$stem]) ? $this->stems[$stem]['start'] : null;
    }

    /** Has $stem faded out for good at $t? */
    public function isOver(string $stem, float $t): bool
    {
        foreach ($this->stems[$stem]['outs'] ?? [] as $out) {
            if ($t >= $out['at'] + $out['seconds']) {
                return true;
            }
        }
        return false;
    }

    /** When $stem is silent for good (its earliest complete fade-out), null while open-ended. */
    public function endOf(string $stem): ?float
    {
        $end = null;
        foreach ($this->stems[$stem]['outs'] ?? [] as $out) {
            $e = $out['at'] + $out['seconds'];
            $end = $end === null ? $e : min($end, $e);
        }
        return $end;
    }

    public function has(string $stem): bool
    {
        return isset($this->stems[$stem]);
    }

    /** @return list<string> scheduled stems in the order they were started */
    public function stems(): array
    {
        return array_map(strval(...), array_keys($this->stems));
    }

    /** Forget every command. */
    public function clear(): void
    {
        $this->stems = [];
    }
}
