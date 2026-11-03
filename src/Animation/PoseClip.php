<?php

declare(strict_types=1);

namespace PHPolygon\Animation;

/**
 * A figure animation as a function of time: either key poses interpolated between
 * ({@see keyframes()}) or a closure ({@see procedural()}, for motion that is easier
 * written as maths, like a speed-driven gait). Clips are data of the game - an idle
 * shrug, a walk cycle, a work loop - registered in {@see PoseLibrary} and played by
 * {@see \PHPolygon\Component\PosePlayer}.
 */
final class PoseClip
{
    /**
     * @param list<array{0: float, 1: Pose}> $keys sorted by time
     * @param (\Closure(float): Pose)|null $sampler
     */
    private function __construct(
        public readonly float $duration,
        public readonly bool $loop,
        private readonly array $keys,
        private readonly bool $smooth,
        private readonly ?\Closure $sampler,
    ) {
    }

    /**
     * Key poses at times in seconds. The clip lasts until the last key; a looping clip
     * starts over there, so repeat the first pose as the last key for a seamless cycle.
     * $smooth eases every segment (smoothstep) - key poses then read as held beats;
     * off = linear (even motion through the keys).
     *
     * @param list<array{0: float|int, 1: Pose}> $keys
     */
    public static function keyframes(array $keys, bool $loop = true, bool $smooth = true): self
    {
        if ($keys === []) {
            throw new \InvalidArgumentException('A clip needs at least one key pose');
        }
        $sorted = [];
        foreach ($keys as [$time, $pose]) {
            $sorted[] = [max(0.0, (float) $time), $pose];
        }
        usort($sorted, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $duration = $sorted[count($sorted) - 1][0];
        return new self($duration, $loop && $duration > 0.0, $sorted, $smooth, null);
    }

    /**
     * A clip computed by $sampler from the time in seconds (0..duration).
     *
     * @param \Closure(float): Pose $sampler
     */
    public static function procedural(float $duration, bool $loop, \Closure $sampler): self
    {
        return new self(max(0.0, $duration), $loop && $duration > 0.0, [], false, $sampler);
    }

    /** The pose at $time seconds (wrapped for a looping clip, clamped otherwise). */
    public function sample(float $time): Pose
    {
        if ($this->sampler !== null) {
            return ($this->sampler)($this->localTime($time));
        }
        return Pose::fromRaw($this->sampleRaw($time));
    }

    /**
     * {@see sample()} as a raw pose (the per-frame path).
     *
     * @return array{0: array<string, array{0: float, 1: float, 2: float, 3: float}>, 1: array{0: float, 1: float, 2: float}|null}
     */
    public function sampleRaw(float $time): array
    {
        $t = $this->localTime($time);
        if ($this->sampler !== null) {
            return ($this->sampler)($t)->raw();
        }
        $keys = $this->keys;
        $count = count($keys);
        if ($count === 1 || $t <= $keys[0][0]) {
            return $keys[0][1]->raw();
        }
        for ($i = 1; $i < $count; $i++) {
            [$t1, $pose1] = $keys[$i];
            if ($t <= $t1) {
                [$t0, $pose0] = $keys[$i - 1];
                $u = $t1 > $t0 ? ($t - $t0) / ($t1 - $t0) : 1.0;
                if ($this->smooth) {
                    $u = $u * $u * (3.0 - 2.0 * $u);
                }
                return Pose::blendRaw($pose0->raw(), $pose1->raw(), $u);
            }
        }
        return $keys[array_key_last($keys)][1]->raw();
    }

    /** $time folded into 0..duration. */
    public function localTime(float $time): float
    {
        if ($this->duration <= 0.0) {
            return 0.0;
        }
        if ($this->loop) {
            $t = fmod($time, $this->duration);
            return $t < 0.0 ? $t + $this->duration : $t;
        }
        return max(0.0, min($this->duration, $time));
    }
}
