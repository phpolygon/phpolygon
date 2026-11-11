<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Counts the programs of a {@see ShaderWarmPlan} off as they are compiled and
 * reports each one: `$onStep(int $done, int $total, string $program)`.
 *
 * The total is fixed by the plan and every planned program is reported exactly
 * once, with $done running 1..$total without a gap, whatever the compile does:
 * - a program compiled out of the plan is not reported,
 * - a program reported twice (a retry) counts once,
 * - a planned program that was skipped or failed is reported as finished when the
 *   next planned program after it compiles, or by {@see finish()} at the end.
 *
 * Only a callback that throws ends the reports early ({@see failure()}).
 */
final class ShaderWarmProgress
{
    /** @var list<string> */
    private readonly array $programs;

    /** @var array<string, int> program id => position in the plan */
    private readonly array $position;

    /** Programs reported so far; the plan is reported strictly in order, so also the next position. */
    private int $done = 0;

    /**
     * @param list<string> $programs the plan, in compile order
     * @param \Closure(int, int, string): void $onStep
     */
    public function __construct(array $programs, private readonly \Closure $onStep)
    {
        $this->programs = array_values(array_unique($programs));
        $this->position = array_flip($this->programs);
    }

    public function total(): int
    {
        return count($this->programs);
    }

    public function done(): int
    {
        return $this->done;
    }

    /** $program is compiled: report it, and before it every planned program that was passed over. */
    public function compiled(string $program): void
    {
        $position = $this->position[$program] ?? null;
        if ($position === null) {
            return;
        }
        $this->reportThrough($position);
    }

    /** The compile sequence is over: report what is left of the plan. */
    public function finish(): void
    {
        $this->reportThrough(count($this->programs) - 1);
    }

    /** "Compiling shaders 17 / 52 (33 %)". 100 % only when every program is done. */
    public static function label(string $text, int $done, int $total): string
    {
        return sprintf('%s %d / %d (%d %%)', $text, $done, $total, self::percent($done, $total));
    }

    public static function percent(int $done, int $total): int
    {
        if ($total <= 0 || $done >= $total) {
            return 100;
        }
        return min(99, max(0, (int) round($done * 100 / $total)));
    }

    /** Where a bar that runs from $from to $to stands after $done of $total programs. */
    public static function fraction(int $done, int $total, float $from = 0.0, float $to = 1.0): float
    {
        $share = $total <= 0 ? 1.0 : max(0.0, min(1.0, $done / $total));
        return $from + ($to - $from) * $share;
    }

    /**
     * What the callback threw, if it did. It is reported from inside the compile
     * sequence, where an exception would read as "this optional program does not
     * compile": it is kept here instead and the callback is not called again.
     */
    public function failure(): ?\Throwable
    {
        return $this->failure;
    }

    private ?\Throwable $failure = null;

    private function reportThrough(int $position): void
    {
        $total = count($this->programs);
        while ($this->done <= $position) {
            $program = $this->programs[$this->done];
            $this->done++;
            if ($this->failure !== null) {
                continue;
            }
            try {
                ($this->onStep)($this->done, $total, $program);
            } catch (\Throwable $e) {
                $this->failure = $e;
            }
        }
    }
}
