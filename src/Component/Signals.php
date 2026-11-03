<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Named channels that drive the signal motions ({@see SignalTrack}, {@see SignalSpin},
 * {@see SignalSwitch}) of a machine, door or display. The game writes them, the motions
 * read them; nothing here knows what a channel means.
 *
 * Four kinds, each in its own namespace of names:
 * - **bool** (`set()` / `get()`): a switch or a sensor - run, halt, lamp on.
 * - **number** (`setNumber()` / `number()`): a level - speed factor, gauge value.
 * - **pulse** (`pulse()` / `takePulse()`): a counted event with a value - "send the
 *   next item, number 3". Pulses wait in a queue until a motion takes them; at most
 *   {@see $maxPendingPulses} wait, a pulse beyond that is dropped and reported.
 *   Exactly one motion should consume a pulse channel.
 * - **sequence** (`play()`): a list of values turned into pulses at a fixed interval,
 *   once or looping - a recorded run played back. Advanced by
 *   {@see \PHPolygon\System\SignalMotionSystem} with the system's time scale.
 *
 * Bools and numbers are saved with the scene; pulses and sequences are runtime state.
 */
#[Serializable]
#[Category('Motion')]
class Signals extends AbstractComponent
{
    /** @var array<string, bool> */
    #[Property]
    public array $bools = [];

    /** @var array<string, float> */
    #[Property]
    public array $numbers = [];

    /** How many pulses may wait per channel; further pulses are dropped. */
    #[Property]
    public int $maxPendingPulses = 16;

    /** @var array<string, list<float>> */
    #[Hidden]
    public array $pulses = [];

    /** @var array<string, int> pulses dropped per channel because the queue was full */
    #[Hidden]
    public array $dropped = [];

    /**
     * @var array<string, array{values: list<float>, interval: float, loop: bool, index: int, timer: float}>
     */
    #[Hidden]
    public array $sequences = [];

    /**
     * @param array<string, bool> $bools
     * @param array<string, float> $numbers
     */
    public function __construct(array $bools = [], array $numbers = [], int $maxPendingPulses = 16)
    {
        $this->bools = $bools;
        $this->numbers = $numbers;
        $this->maxPendingPulses = max(1, $maxPendingPulses);
    }

    public function set(string $channel, bool $on): void
    {
        $this->bools[$channel] = $on;
    }

    public function get(string $channel, bool $default = false): bool
    {
        return $this->bools[$channel] ?? $default;
    }

    public function setNumber(string $channel, float $value): void
    {
        $this->numbers[$channel] = $value;
    }

    public function number(string $channel, float $default = 0.0): float
    {
        return $this->numbers[$channel] ?? $default;
    }

    /** Queue one pulse. False when the queue was full and the pulse was dropped. */
    public function pulse(string $channel, float $value = 1.0): bool
    {
        $queue = $this->pulses[$channel] ?? [];
        if (count($queue) >= $this->maxPendingPulses) {
            $this->dropped[$channel] = ($this->dropped[$channel] ?? 0) + 1;
            return false;
        }
        $queue[] = $value;
        $this->pulses[$channel] = $queue;
        return true;
    }

    public function pendingPulses(string $channel): int
    {
        return count($this->pulses[$channel] ?? []);
    }

    /** Value of the oldest waiting pulse without taking it, null when none waits. */
    public function peekPulse(string $channel): ?float
    {
        return $this->pulses[$channel][0] ?? null;
    }

    /** Take the oldest waiting pulse; null when none waits. */
    public function takePulse(string $channel): ?float
    {
        if (($this->pulses[$channel] ?? []) === []) {
            return null;
        }
        return array_shift($this->pulses[$channel]);
    }

    public function clearPulses(string $channel): void
    {
        unset($this->pulses[$channel], $this->dropped[$channel]);
    }

    public function droppedPulses(string $channel): int
    {
        return $this->dropped[$channel] ?? 0;
    }

    /**
     * Play $values as pulses on $channel: the first after $delay seconds, then one every
     * $interval seconds; with $loop it starts over one interval after the last. Replaces
     * a sequence already playing on the channel (its waiting pulses stay).
     *
     * @param list<float> $values
     */
    public function play(string $channel, array $values, float $interval, bool $loop = false, float $delay = 0.0): void
    {
        if ($values === []) {
            unset($this->sequences[$channel]);
            return;
        }
        $this->sequences[$channel] = [
            'values' => array_map('floatval', $values),
            'interval' => max(1e-3, $interval),
            'loop' => $loop,
            'index' => 0,
            'timer' => max(0.0, $delay),
        ];
    }

    public function stop(string $channel): void
    {
        unset($this->sequences[$channel]);
    }

    public function isPlaying(string $channel): bool
    {
        return isset($this->sequences[$channel]);
    }

    /** Advance every sequence by $dt seconds, emitting their due pulses. */
    public function advance(float $dt): void
    {
        foreach ($this->sequences as $channel => $seq) {
            $seq['timer'] -= $dt;
            while ($seq['timer'] <= 0.0) {
                $this->pulse($channel, $seq['values'][$seq['index']]);
                $seq['index']++;
                if ($seq['index'] >= count($seq['values'])) {
                    if (!$seq['loop']) {
                        unset($this->sequences[$channel]);
                        continue 2;
                    }
                    $seq['index'] = 0;
                }
                $seq['timer'] += $seq['interval'];
            }
            $this->sequences[$channel] = $seq;
        }
    }
}
