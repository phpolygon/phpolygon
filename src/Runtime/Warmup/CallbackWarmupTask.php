<?php

declare(strict_types=1);

namespace PHPolygon\Runtime\Warmup;

/**
 * A {@see WarmupTask} made of a closure. The closure may return a generator (work in
 * slices) or anything else (done in one go).
 *
 * ```php
 * $engine->addWarmupTask(new CallbackWarmupTask(
 *     'paths',
 *     static function (): \Generator {
 *         foreach ($regions as $r) { bake($r); yield; }
 *     },
 *     labelKey: 'loading.warm.paths',
 *     weight: 3.0,
 * ));
 * ```
 */
final class CallbackWarmupTask implements WarmupTask
{
    /** @var \Closure(): mixed */
    private readonly \Closure $work;

    /**
     * @param callable(): mixed $work
     * @param list<string> $dependsOn
     */
    public function __construct(
        private readonly string $id,
        callable $work,
        private readonly ?string $labelKey = null,
        private readonly float $weight = 1.0,
        private readonly array $dependsOn = [],
    ) {
        $this->work = \Closure::fromCallable($work);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function labelKey(): ?string
    {
        return $this->labelKey;
    }

    public function weight(): float
    {
        return $this->weight;
    }

    public function dependsOn(): array
    {
        return $this->dependsOn;
    }

    public function run(): ?\Generator
    {
        $result = ($this->work)();
        return $result instanceof \Generator ? $result : null;
    }
}
