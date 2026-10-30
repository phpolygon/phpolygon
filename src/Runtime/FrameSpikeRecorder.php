<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * Always-on hitch recorder for shipped games: no environment variable, no
 * profiler, one log line per hitch and one summary when the session ends.
 *
 * A frame here is the whole interval between two loop starts: update ticks,
 * render, present, events and the FPS cap. It is a spike when it reaches
 * max(floorMs, medianFactor x median of the recent frames). The median is only
 * computed for frames at or above the floor, so a normal frame never pays for it.
 *
 * Per frame the recorder writes into preallocated storage (ring, histogram,
 * sections). The one allocation is the short-lived array of gc_status(), the
 * only source of the collector's time. A frame without a spike writes nothing.
 *
 * Session percentiles come from a fixed histogram over every frame (0.25 ms
 * steps to 50 ms, 5 ms steps to 1 s), the ring only feeds the median.
 *
 * What the engine reports by itself: sections, update ticks, GC, memory delta,
 * and the notes {@see NOTE_SHADER}, {@see NOTE_PIPELINE}, {@see NOTE_AUDIO},
 * {@see NOTE_GRASS}. A game adds its own notes through {@see note()} and its
 * state through {@see addContext()}. Context callbacks only run for a written
 * line. Neither a callback nor the sink can break the game loop: their
 * exceptions are swallowed.
 *
 * On php-vio `present` is the 2D flush plus vio_end(), which holds the submit,
 * the wait for the GPU and the vsync wait in one call. `wait` is the engine's FPS
 * cap. `other` is whatever falls outside the marked sections.
 */
final class FrameSpikeRecorder
{
    public const NOTE_SHADER = 'shader';
    public const NOTE_PIPELINE = 'pipeline';
    public const NOTE_AUDIO = 'audio';
    public const NOTE_GRASS = 'grass';

    public const OTHER = 0;
    public const UPDATE = 1;
    public const RENDER = 2;
    public const PRESENT = 3;
    public const WAIT = 4;

    private const SECTION_NAMES = ['other', 'update', 'render', 'present', 'wait'];

    private const FINE_LIMIT_NS = 50_000_000;
    private const FINE_STEP_NS = 250_000;
    private const COARSE_STEP_NS = 5_000_000;
    private const FINE_BUCKETS = 200;
    private const BUCKETS = 390;

    /** Frames between two median computations while frames stay above the floor. */
    private const MEDIAN_MAX_AGE = 30;

    private const CONTEXT_VALUE_MAX = 64;

    private static ?self $active = null;

    /** @var (\Closure(string): void)|null */
    private ?\Closure $sink;

    /** @var list<\Closure(): array<string, scalar|null>> */
    private array $contexts = [];

    private int $floorNs;
    private int $minLineIntervalNs;

    /** @var array<int, int> */
    private array $ring;
    private int $ringPos = 0;
    private int $ringCount = 0;

    /** @var array<int, int> */
    private array $histogram;

    /** @var array<int, int> */
    private array $sectionNs = [0, 0, 0, 0, 0];
    private int $section = self::OTHER;
    private int $sectionStart = 0;
    private int $ticks = 0;

    private int $frameStart = 0;
    private int $suspended = 0;
    private bool $skipFrame = false;
    private bool $finished = false;

    private int $lastGcRuns = 0;
    private int $lastGcCollected = 0;
    private float $lastGcSeconds = 0.0;
    private int $lastMemory = 0;

    /** @var array<string, int> notes of the running frame */
    private array $noteCount = [];
    /** @var array<string, int> */
    private array $noteNs = [];
    /** @var array<string, int> notes of the session */
    private array $totalNoteCount = [];
    /** @var array<string, int> */
    private array $totalNoteNs = [];

    private int $frames = 0;
    private int $totalNs = 0;
    private int $maxNs = 0;
    private int $sessionStart = 0;
    private int $spikes = 0;
    private int $linesWritten = 0;
    private int $suppressed = 0;
    private int $suppressedSinceLine = 0;
    private int $lastLineAt = 0;
    private bool $anyLine = false;
    /** @var array<string, int> */
    private array $causes = [];

    private int $median = 0;
    private int $medianFrame = -1;

    /**
     * @param (callable(string): void)|null $sink receives every finished line; left null, the engine sets game.log
     * @param float $floorMs a frame below this is never a spike
     * @param float $medianFactor a spike also reaches this many medians
     * @param int $ringSize frames behind the median
     * @param int $warmupFrames frames that must be known before a spike can be reported
     * @param float $minLineIntervalSeconds at most one line per interval, the others only count
     * @param int $maxLines lines per session, the summary is not one of them
     */
    public function __construct(
        ?callable $sink = null,
        float $floorMs = 33.0,
        private readonly float $medianFactor = 2.5,
        private readonly int $ringSize = 240,
        private readonly int $warmupFrames = 30,
        float $minLineIntervalSeconds = 1.0,
        private readonly int $maxLines = 1000,
    ) {
        if ($ringSize < 1) {
            throw new \InvalidArgumentException('ringSize must be at least 1');
        }
        if ($warmupFrames > $ringSize) {
            // The ring never holds more than ringSize frames: no spike would ever be judged.
            throw new \InvalidArgumentException('warmupFrames must not exceed ringSize');
        }
        $this->sink = $sink === null ? null : $sink(...);
        $this->floorNs = (int) ($floorMs * 1_000_000.0);
        $this->minLineIntervalNs = (int) ($minLineIntervalSeconds * 1_000_000_000.0);
        $this->ring = array_fill(0, $ringSize, 0);
        $this->histogram = array_fill(0, self::BUCKETS, 0);
    }

    /** The recorder {@see note()} reports to. Null detaches it. */
    public static function activate(?self $recorder): void
    {
        self::$active = $recorder;
    }

    /**
     * Report something that happened in this frame, from anywhere: `shader`,
     * `save`, `region_change`. A name with time can become the line's cause.
     * Costs one null check when no recorder is active.
     */
    public static function note(string $name, int $count = 1, int $elapsedNs = 0): void
    {
        $recorder = self::$active;
        if ($recorder === null) {
            return;
        }
        $recorder->noteCount[$name] = ($recorder->noteCount[$name] ?? 0) + $count;
        if ($elapsedNs > 0) {
            $recorder->noteNs[$name] = ($recorder->noteNs[$name] ?? 0) + $elapsedNs;
        }
    }

    /** @param (callable(string): void)|null $sink */
    public function setSink(?callable $sink): void
    {
        $this->sink = $sink === null ? null : $sink(...);
    }

    public function hasSink(): bool
    {
        return $this->sink !== null;
    }

    /**
     * Add state to every written line, e.g. `fn () => ['region' => $r, 'dialog' => $open]`.
     *
     * @param callable(): array<string, scalar|null> $context
     */
    public function addContext(callable $context): void
    {
        $this->contexts[] = $context(...);
    }

    /**
     * Stop judging frames, e.g. around a load screen whose long frames are
     * expected. Calls nest. The next frame after {@see resume()} starts fresh.
     */
    public function suspend(): void
    {
        $this->suspended++;
    }

    public function resume(): void
    {
        if ($this->suspended > 0 && --$this->suspended === 0) {
            $this->frameStart = 0;
        }
    }

    /** Leave the running frame out of everything (minimised window). */
    public function skipFrame(): void
    {
        $this->skipFrame = true;
    }

    /** Switch the running section. Time since the last switch goes to the previous one. */
    public function enter(int $section): void
    {
        $now = (int) hrtime(true);
        $this->sectionNs[$this->section] += $now - $this->sectionStart;
        $this->section = $section;
        $this->sectionStart = $now;
    }

    /** {@see enter()} for one update tick, which also counts it. */
    public function beginUpdate(): void
    {
        $now = (int) hrtime(true);
        $this->sectionNs[$this->section] += $now - $this->sectionStart;
        $this->section = self::UPDATE;
        $this->sectionStart = $now;
        $this->ticks++;
    }

    /**
     * Frame boundary, called at the start of every loop iteration with the same
     * clock as hrtime(true). Closes the previous frame and judges it.
     */
    public function frame(int $now): void
    {
        $start = $this->frameStart;
        $this->frameStart = $now;

        $this->sectionNs[$this->section] += $now - $this->sectionStart;
        $this->sectionStart = $now;

        $gc = gc_status();
        $memory = memory_get_usage();

        if ($start === 0 || $this->suspended > 0 || $this->skipFrame) {
            $this->skipFrame = false;
            if ($this->sessionStart === 0) {
                $this->sessionStart = $now;
            }
            $this->rememberGc($gc, $memory);
            $this->resetFrame();
            return;
        }

        $ns = $now - $start;
        $this->frames++;
        $this->totalNs += $ns;
        if ($ns > $this->maxNs) {
            $this->maxNs = $ns;
        }
        $bucket = $ns < self::FINE_LIMIT_NS
            ? intdiv($ns, self::FINE_STEP_NS)
            : self::FINE_BUCKETS + intdiv($ns - self::FINE_LIMIT_NS, self::COARSE_STEP_NS);
        $this->histogram[$bucket < self::BUCKETS ? $bucket : self::BUCKETS - 1]++;

        if ($ns >= $this->floorNs && $this->ringCount >= $this->warmupFrames) {
            $this->judge($ns, $now, $gc, $memory);
        }

        $this->ring[$this->ringPos] = $ns;
        if (++$this->ringPos === $this->ringSize) {
            $this->ringPos = 0;
        }
        if ($this->ringCount < $this->ringSize) {
            $this->ringCount++;
        }

        $this->rememberGc($gc, $memory);
        if ($this->noteCount !== []) {
            foreach ($this->noteCount as $name => $count) {
                $this->totalNoteCount[$name] = ($this->totalNoteCount[$name] ?? 0) + $count;
            }
            foreach ($this->noteNs as $name => $elapsed) {
                $this->totalNoteNs[$name] = ($this->totalNoteNs[$name] ?? 0) + $elapsed;
            }
        }
        $this->resetFrame();
    }

    /**
     * Write the session summary once and detach. Safe at any time, also from a
     * shutdown function: an empty session writes nothing.
     */
    public function finish(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        if (self::$active === $this) {
            self::$active = null;
        }
        if ($this->frames > 0) {
            $this->emit($this->summaryLine());
        }
    }

    public function summaryLine(): string
    {
        $causes = [];
        foreach ($this->causes as $cause => $count) {
            $causes[] = self::token((string) $cause) . ':' . $count;
        }
        $line = sprintf(
            '[frame-session] frames=%d played=%.1fs p50=%.2fms p95=%.2fms p99=%.2fms max=%.2fms spikes=%d written=%d suppressed=%d causes=%s',
            $this->frames,
            $this->totalNs / 1_000_000_000.0,
            $this->percentileMs(50.0),
            $this->percentileMs(95.0),
            $this->percentileMs(99.0),
            $this->maxNs / 1_000_000.0,
            $this->spikes,
            $this->linesWritten,
            $this->suppressed,
            $causes === [] ? '-' : implode(',', $causes),
        );
        return $line . self::formatNotes($this->totalNoteCount, $this->totalNoteNs);
    }

    /**
     * Session percentile, upper edge of its histogram bucket, never above the
     * slowest frame. 0.0 before the first frame.
     */
    public function percentileMs(float $percent): float
    {
        if ($this->frames === 0) {
            return 0.0;
        }
        $rank = (int) ceil($this->frames * $percent / 100.0);
        $seen = 0;
        foreach ($this->histogram as $bucket => $count) {
            $seen += $count;
            if ($seen >= $rank) {
                $upper = $bucket < self::FINE_BUCKETS
                    ? ($bucket + 1) * self::FINE_STEP_NS
                    : self::FINE_LIMIT_NS + ($bucket - self::FINE_BUCKETS + 1) * self::COARSE_STEP_NS;
                return min($upper, $this->maxNs) / 1_000_000.0;
            }
        }
        return $this->maxNs / 1_000_000.0;
    }

    public function frameCount(): int
    {
        return $this->frames;
    }

    public function spikeCount(): int
    {
        return $this->spikes;
    }

    /** @param array<string, mixed> $gc gc_status() of this frame boundary */
    private function judge(int $ns, int $now, array $gc, int $memory): void
    {
        if ($this->medianFrame < 0 || $this->frames - $this->medianFrame >= self::MEDIAN_MAX_AGE) {
            $this->median = $this->computeMedian();
            $this->medianFrame = $this->frames;
        }
        $limit = (int) max($this->floorNs, $this->medianFactor * $this->median);
        if ($ns < $limit) {
            return;
        }

        $gcNs = (int) ((self::gcSeconds($gc) - $this->lastGcSeconds) * 1_000_000_000.0);
        $cause = $this->cause($ns, $gcNs);
        $this->spikes++;
        $this->causes[$cause] = ($this->causes[$cause] ?? 0) + 1;

        if ($this->linesWritten >= $this->maxLines
            || ($this->anyLine && $now - $this->lastLineAt < $this->minLineIntervalNs)) {
            $this->suppressed++;
            $this->suppressedSinceLine++;
            return;
        }

        $this->anyLine = true;
        $this->lastLineAt = $now;
        $this->linesWritten++;
        $this->emit($this->spikeLine($ns, $limit, $now, $cause, $gcNs, $gc, $memory));
        $this->suppressedSinceLine = 0;
    }

    private function computeMedian(): int
    {
        $values = $this->ringCount === $this->ringSize ? $this->ring : array_slice($this->ring, 0, $this->ringCount);
        sort($values);
        return $values[intdiv(count($values), 2)] ?? 0;
    }

    /**
     * GC or a timed note when it explains at least half of what the frame ran
     * over its median, else the largest section.
     */
    private function cause(int $ns, int $gcNs): string
    {
        $name = 'gc';
        $largest = $gcNs;
        foreach ($this->noteNs as $note => $elapsed) {
            if ($elapsed > $largest) {
                // PHP turns a numeric name into an int key.
                $name = (string) $note;
                $largest = $elapsed;
            }
        }
        if ($largest * 2 >= $ns - $this->median) {
            return $name;
        }

        $section = 0;
        foreach ($this->sectionNs as $index => $elapsed) {
            if ($elapsed > $this->sectionNs[$section]) {
                $section = $index;
            }
        }
        return self::SECTION_NAMES[$section];
    }

    /** @param array<string, mixed> $gc */
    private function spikeLine(int $ns, int $limit, int $now, string $cause, int $gcNs, array $gc, int $memory): string
    {
        $line = sprintf(
            '[frame-spike] #%d t=%.1fs frame=%.2fms limit=%.2fms median=%.2fms cause=%s update=%.2fms ticks=%d render=%.2fms present=%.2fms wait=%.2fms other=%.2fms gc=%d/%.2fms collected=%d mem=%+.2fMB',
            $this->spikes,
            ($now - $this->sessionStart) / 1_000_000_000.0,
            $ns / 1_000_000.0,
            $limit / 1_000_000.0,
            $this->median / 1_000_000.0,
            self::token($cause),
            $this->sectionNs[self::UPDATE] / 1_000_000.0,
            $this->ticks,
            $this->sectionNs[self::RENDER] / 1_000_000.0,
            $this->sectionNs[self::PRESENT] / 1_000_000.0,
            $this->sectionNs[self::WAIT] / 1_000_000.0,
            $this->sectionNs[self::OTHER] / 1_000_000.0,
            self::gcInt($gc, 'runs') - $this->lastGcRuns,
            $gcNs / 1_000_000.0,
            self::gcInt($gc, 'collected') - $this->lastGcCollected,
            ($memory - $this->lastMemory) / 1_048_576.0,
        );
        $line .= self::formatNotes($this->noteCount, $this->noteNs);
        if ($this->suppressedSinceLine > 0) {
            $line .= ' suppressed=' . $this->suppressedSinceLine;
        }
        return $line . $this->contextText();
    }

    private function contextText(): string
    {
        $text = '';
        foreach ($this->contexts as $context) {
            try {
                foreach ($context() as $key => $value) {
                    $text .= ' ' . self::token((string) $key) . '=' . self::contextValue($value);
                }
            } catch (\Throwable) {
                $text .= ' context=failed';
            }
        }
        return $text;
    }

    private static function contextValue(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => '-',
            is_bool($value) => $value ? 'yes' : 'no',
            default => self::token(substr((string) $value, 0, self::CONTEXT_VALUE_MAX)),
        };
    }

    /** Keep a line splittable on spaces and `=`, whatever a game names things. */
    private static function token(string $text): string
    {
        return strtr($text, " \t\r\n=", '_____');
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, int> $times
     */
    private static function formatNotes(array $counts, array $times): string
    {
        $text = '';
        foreach ($counts as $name => $count) {
            $text .= ' ' . self::token((string) $name) . '=' . $count;
            if (isset($times[$name])) {
                $text .= sprintf('/%.2fms', $times[$name] / 1_000_000.0);
            }
        }
        return $text;
    }

    private function emit(string $line): void
    {
        if ($this->sink === null) {
            return;
        }
        try {
            ($this->sink)($line);
        } catch (\Throwable) {
            // A log that cannot be written must not take the game down.
        }
    }

    /** @param array<string, mixed> $gc */
    private function rememberGc(array $gc, int $memory): void
    {
        $this->lastGcRuns = self::gcInt($gc, 'runs');
        $this->lastGcCollected = self::gcInt($gc, 'collected');
        $this->lastGcSeconds = self::gcSeconds($gc);
        $this->lastMemory = $memory;
    }

    /** @param array<string, mixed> $gc */
    private static function gcInt(array $gc, string $key): int
    {
        $value = $gc[$key] ?? 0;
        return is_int($value) ? $value : 0;
    }

    /**
     * Collector time, PHP >= 8.3. Older runtimes report it as 0.
     *
     * @param array<string, mixed> $gc
     */
    private static function gcSeconds(array $gc): float
    {
        $value = $gc['collector_time'] ?? 0.0;
        return is_float($value) ? $value : 0.0;
    }

    private function resetFrame(): void
    {
        $this->sectionNs[0] = 0;
        $this->sectionNs[1] = 0;
        $this->sectionNs[2] = 0;
        $this->sectionNs[3] = 0;
        $this->sectionNs[4] = 0;
        $this->section = self::OTHER;
        $this->ticks = 0;
        if ($this->noteCount !== []) {
            $this->noteCount = [];
            $this->noteNs = [];
        }
    }
}
