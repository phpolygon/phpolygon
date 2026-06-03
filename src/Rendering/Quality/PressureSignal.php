<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Discrete pressure level reported by a ThermalSourceInterface.
 *
 * Levels are ordered: Unknown == Nominal (0) < Fair (1) < Serious (2) < Critical (3).
 * The ThermalMonitor aggregates multiple sources by taking the max() so the
 * most-stressed signal wins.
 */
enum PressureSignal: string
{
    case Unknown  = 'unknown';
    case Nominal  = 'nominal';
    case Fair     = 'fair';
    case Serious  = 'serious';
    case Critical = 'critical';

    public function level(): int
    {
        return match ($this) {
            self::Unknown, self::Nominal => 0,
            self::Fair                   => 1,
            self::Serious                => 2,
            self::Critical               => 3,
        };
    }

    public static function max(self $a, self $b): self
    {
        return $a->level() >= $b->level() ? $a : $b;
    }
}
