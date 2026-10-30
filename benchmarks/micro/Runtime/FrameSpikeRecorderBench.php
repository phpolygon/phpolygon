<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\Runtime;

use PHPolygon\Runtime\FrameSpikeRecorder;

/**
 * Cost of the always-on recorder per frame: the calls one engine frame makes
 * (boundary, one tick, render, present, back to other), against the same call
 * sites with no recorder. The budget is 0.1 ms per frame.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/Runtime --report=aggregate
 */
final class FrameSpikeRecorderBench
{
    private ?FrameSpikeRecorder $on = null;
    private ?FrameSpikeRecorder $off = null;
    private int $now = 1;

    public function setUp(): void
    {
        $this->on = new FrameSpikeRecorder(static function (string $line): void {});
        $this->now = (int) hrtime(true);
        for ($i = 0; $i < 300; $i++) {
            $this->frame($this->on);
        }
    }

    private function frame(?FrameSpikeRecorder $recorder): void
    {
        // Same clock source as the loop, so no frame ever reads as a spike.
        $recorder?->frame($this->now);
        $this->now += 16_000_000;
        $recorder?->beginUpdate();
        $recorder?->enter(FrameSpikeRecorder::OTHER);
        $recorder?->enter(FrameSpikeRecorder::RENDER);
        $recorder?->enter(FrameSpikeRecorder::PRESENT);
        $recorder?->enter(FrameSpikeRecorder::OTHER);
    }

    /** @BeforeMethods("setUp") */
    public function benchFrameOff(): void
    {
        $this->frame($this->off);
    }

    /** @BeforeMethods("setUp") */
    public function benchFrameOn(): void
    {
        $this->frame($this->on);
    }

    /** @BeforeMethods("setUp") */
    public function benchFrameOnWithNote(): void
    {
        FrameSpikeRecorder::activate($this->on);
        FrameSpikeRecorder::note(FrameSpikeRecorder::NOTE_GRASS, 2, 1000);
        $this->frame($this->on);
    }
}
