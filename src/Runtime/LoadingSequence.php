<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * The course of a loading screen without the screen: which lines it shows, how many
 * frames each takes, where the bar stands and when it may go.
 *
 * A line stands for real work spread over frames, so the screen keeps moving and a
 * cold first frame is paid behind it. Call {@see next()} once per frame on screen
 * and do what it names: the frame in between is part of the work. Stretching only
 * happens at the end: work done before `$minSeconds` leaves the finished screen
 * standing, so it does not just flash; long work is never held up or cut off.
 *
 * What a step does and how a line reads belong to the game: steps and labels are
 * opaque strings here, a label is whatever the game draws or translates. For the
 * splash during `onInit` there is {@see \PHPolygon\Engine::setSplashTasks()}; this
 * is for a screen of the game's own.
 *
 * ```php
 * $loading = new LoadingSequence(
 *     [
 *         ['step' => 'restore', 'label' => 'loading.restore', 'ticks' => 1],
 *         ['step' => 'grass', 'label' => 'loading.surroundings', 'ticks' => 4],
 *     ],
 *     minSeconds: 0.8,
 * );
 *
 * // once per frame shown
 * if (($work = $loading->next()) !== null) {
 *     match ($work['step']) { ... };      // $work['tick'] counts the frames of a line
 * } elseif ($loading->finished()) {
 *     // leave the screen
 * }
 * ```
 *
 * @phpstan-type LoadingLine array{step: string, label: string, ticks: int}
 */
final class LoadingSequence
{
    /** @var list<LoadingLine> */
    private array $lines;

    /** Line the work stands at, index into {@see $lines}. */
    private int $line = 0;

    /** Frames done of the current line. */
    private int $tick = 0;

    private readonly float $startedAt;

    /** @var \Closure(): float seconds */
    private readonly \Closure $clock;

    /**
     * @param list<LoadingLine> $lines the work, in order; a line takes at least one tick
     * @param float $minSeconds the shortest time on screen
     * @param list<string> $doneLabels lines of work that ran on this screen before:
     *     they stay above as finished, so there is no second screen
     * @param (\Closure(): float)|null $clock seconds, for tests; null = hrtime
     */
    public function __construct(
        array $lines,
        public readonly float $minSeconds = 0.0,
        private readonly array $doneLabels = [],
        ?\Closure $clock = null,
    ) {
        foreach ($lines as $line) {
            if ($line['ticks'] < 1) {
                throw new \InvalidArgumentException("Loading line '{$line['step']}' needs at least one tick.");
            }
        }
        if ($minSeconds < 0.0) {
            throw new \InvalidArgumentException('The minimum time on screen cannot be negative.');
        }
        $this->lines = $lines;
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
        $this->startedAt = ($this->clock)();
    }

    /**
     * Change a line whose size is known only once the work before it is done. 0
     * drops it. A line that has begun stays as it is, and so does the bar: fewer
     * lines ahead only move it forward.
     *
     * @return bool whether a line changed
     */
    public function setTicks(string $step, int $ticks): bool
    {
        if ($ticks < 0) {
            throw new \InvalidArgumentException('A loading line cannot have negative ticks.');
        }
        $first = $this->line + ($this->tick > 0 ? 1 : 0);
        for ($i = $first, $n = count($this->lines); $i < $n; $i++) {
            if ($this->lines[$i]['step'] !== $step) {
                continue;
            }
            if ($ticks > 0) {
                $this->lines[$i]['ticks'] = $ticks;
            } else {
                array_splice($this->lines, $i, 1);
            }
            return true;
        }
        return false;
    }

    /**
     * The next piece of work, null when there is none left. Once per frame shown.
     *
     * @return array{step: string, tick: int}|null `tick` counts from 0 within the line
     */
    public function next(): ?array
    {
        $line = $this->lines[$this->line] ?? null;
        if ($line === null) {
            return null;
        }
        $tick = $this->tick;
        if (++$this->tick >= $line['ticks']) {
            $this->line++;
            $this->tick = 0;
        }
        return ['step' => $line['step'], 'tick' => $tick];
    }

    public function workDone(): bool
    {
        return $this->line >= count($this->lines);
    }

    /** Work done and the minimum time over: the screen may go. */
    public function finished(): bool
    {
        return $this->workDone() && $this->elapsed() >= $this->minSeconds;
    }

    /** Seconds since the sequence began. */
    public function elapsed(): float
    {
        return ($this->clock)() - $this->startedAt;
    }

    /**
     * Every line of the screen, the ones from before first.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        return [...$this->doneLabels, ...array_column($this->lines, 'label')];
    }

    /** The running line, 1-based in {@see labels()}; one past the last when all is done. */
    public function currentLine(): int
    {
        return count($this->doneLabels) + $this->line + 1;
    }

    /** The bar, 0..1: every line weighs the same, the running one by its ticks. */
    public function fraction(): float
    {
        $total = count($this->doneLabels) + count($this->lines);
        if ($total === 0) {
            return 1.0;
        }
        $running = isset($this->lines[$this->line]) ? $this->tick / $this->lines[$this->line]['ticks'] : 0.0;
        return (count($this->doneLabels) + $this->line + $running) / $total;
    }
}
