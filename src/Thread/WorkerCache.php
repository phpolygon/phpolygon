<?php

declare(strict_types=1);

namespace PHPolygon\Thread;

/**
 * Per-thread memo for {@see AsyncJobPool} handlers: whatever a worker computed
 * once (a parsed table, a prototype mesh, a noise permutation) and would
 * otherwise recompute for every job it runs.
 *
 * `parallel` threads share nothing, and a class static is per thread, so every
 * worker (and the main thread, for the inline pool) sees its own instance: no
 * locking, no copies. Closure `static` variables do NOT work for this - each
 * `Runtime::run()` starts a fresh closure - which is why this lives in a class.
 *
 * A handler must stay a pure function of its payload: the cache may only
 * change how fast it gets there, never what it returns. A job can land on any
 * worker, and a recycled worker starts with an empty cache.
 */
final class WorkerCache
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private array $values = [];

    private int $hits = 0;

    private int $misses = 0;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * The value stored under $key, built by $factory on first use in this thread.
     *
     * @template T
     * @param \Closure(): T $factory
     * @return T
     */
    public function remember(string $key, \Closure $factory): mixed
    {
        if (array_key_exists($key, $this->values)) {
            $this->hits++;

            return $this->values[$key];
        }
        $this->misses++;

        return $this->values[$key] = $factory();
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }

    public function clear(): void
    {
        $this->values = [];
        $this->hits = 0;
        $this->misses = 0;
    }

    public function size(): int
    {
        return count($this->values);
    }

    /** @return array{hits: int, misses: int} */
    public function stats(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses];
    }
}
