<?php

declare(strict_types=1);

namespace PHPolygon\Build;

use Closure;

/**
 * Progress lines of a pre-bake (a build hook that renders audio, encodes textures, ...),
 * readable in a build log that runs through pipes and `sed`:
 *
 *     voice: 2753 to render, 12661 up to date, 12 processes      plan()
 *     voice: 736/2753 (26 %) – 29 min, ~1 h 19 min left          update() / advance()
 *     voice: done – 2753/2753 in 1 h 52 min (3 failed)           finish()
 *
 * One line per message, flushed at once, no ANSI escapes and no carriage-return
 * animation. Lines go to STDERR by default, so a script's STDOUT stays as it was.
 *
 * Throttled, not per file: a progress line comes once a new {@see $stepPercent} step is
 * reached AND at least {@see $minInterval} seconds have passed since the last line. When
 * nothing moves for long (one big part, a slow run), a line still comes after
 * {@see $heartbeat} seconds - the log never stays silent for minutes.
 *
 * Pure bookkeeping with an injected clock and output, testable without waiting.
 */
final class PrebakeProgress
{
    /** Default variable for {@see strict()}. */
    public const string STRICT_ENV = 'PHPOLYGON_PREBAKE_STRICT';

    /** @var Closure(string): void */
    private readonly Closure $out;
    /** @var Closure(): float */
    private readonly Closure $clock;
    private readonly float $start;
    private float $lastLine;
    private int $lastStep = 0;
    private int $done = 0;

    /**
     * @param string $label prefix of every line, e.g. `voice` or `textures encode`
     * @param int $total number of parts (clips, pieces, images)
     * @param (Closure(string): void)|null $out gets every line without its line end; default STDERR
     * @param (Closure(): float)|null $clock seconds; default microtime(true)
     * @param float|null $start when the run began (a script's own start, so its planning
     *                          counts too); default now
     */
    public function __construct(
        public readonly string $label,
        public readonly int $total,
        ?Closure $out = null,
        ?Closure $clock = null,
        public readonly float $minInterval = 10.0,
        public readonly int $stepPercent = 5,
        public readonly float $heartbeat = 60.0,
        ?float $start = null,
    ) {
        $this->out = $out ?? self::stderr();
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->start = $start ?? ($this->clock)();
        $this->lastLine = $this->start;
    }

    /** One free line with the label, e.g. a note between the steps. */
    public function say(string $text): void
    {
        ($this->out)("{$this->label}: $text");
    }

    /** The plan at the start - the same as {@see say()}, named for readability. */
    public function plan(string $text): void
    {
        $this->say($text);
    }

    /** $by more parts are settled (done or failed). */
    public function advance(int $by = 1): void
    {
        $this->update($this->done + $by);
    }

    /** Only check the clock: keeps the heartbeat going while nothing moves. */
    public function tick(): void
    {
        $this->update($this->done);
    }

    /** $done parts are settled. Writes a line when one is due. */
    public function update(int $done): void
    {
        $this->done = max(0, min($this->total, $done));
        if ($this->total <= 0 || $this->done >= $this->total) {
            return; // finish() writes the last line
        }
        $now = ($this->clock)();
        $since = $now - $this->lastLine;
        $step = $this->stepPercent > 0 ? intdiv($this->done * 100, $this->total * $this->stepPercent) : 0;
        if (($step > $this->lastStep && $since >= $this->minInterval) || $since >= $this->heartbeat) {
            $this->lastStep = max($this->lastStep, $step);
            $this->lastLine = $now;
            ($this->out)($this->line($now));
        }
    }

    public function done(): int
    {
        return $this->done;
    }

    public function elapsed(): float
    {
        return ($this->clock)() - $this->start;
    }

    /** The closing line with the duration; $detail goes in brackets after it. */
    public function finish(string $detail = ''): void
    {
        $what = $this->total > 0 ? "{$this->done}/{$this->total}" : 'nothing to do';
        ($this->out)(sprintf(
            '%s: done – %s in %s%s',
            $this->label,
            $what,
            self::duration($this->elapsed()),
            $detail !== '' ? " ($detail)" : '',
        ));
    }

    /** The progress line at time $now. */
    public function line(float $now): string
    {
        $elapsed = $now - $this->start;
        $percent = $this->total > 0 ? intdiv($this->done * 100, $this->total) : 100;
        $text = sprintf('%s: %d/%d (%d %%) – %s', $this->label, $this->done, $this->total, $percent, self::duration($elapsed));
        if ($this->done > 0 && $this->done < $this->total) {
            $text .= ', ~' . self::duration($elapsed / $this->done * ($this->total - $this->done)) . ' left';
        }

        return $text;
    }

    /** `4.2 s`, `42 s`, `4 min 10 s`, `29 min`, `1 h 52 min`. */
    public static function duration(float $seconds): string
    {
        $seconds = max(0.0, $seconds);
        if ($seconds < 10.0) {
            return sprintf('%.1f s', $seconds);
        }
        $s = (int) round($seconds);
        if ($s < 60) {
            return "$s s";
        }
        if ($s < 600) {
            return $s % 60 === 0 ? sprintf('%d min', intdiv($s, 60)) : sprintf('%d min %d s', intdiv($s, 60), $s % 60);
        }
        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return "$minutes min";
        }

        return sprintf('%d h %d min', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Should a pre-bake whose parts failed fail the build? Only when the variable $env
     * (default {@see STRICT_ENV}) is `1`, `true`, `yes` or `on`; default: no.
     */
    public static function strict(string $env = self::STRICT_ENV): bool
    {
        return self::truthy(getenv($env));
    }

    /** `1`, `true`, `yes`, `on` (any case, trimmed) - the values {@see strict()} accepts. */
    public static function truthy(string|false|null $value): bool
    {
        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return Closure(string): void writes a line to STDERR and flushes it */
    public static function stderr(): Closure
    {
        return static function (string $line): void {
            fwrite(STDERR, $line . "\n");
            fflush(STDERR);
        };
    }
}
