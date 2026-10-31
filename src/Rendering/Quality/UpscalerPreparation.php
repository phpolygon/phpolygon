<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * A provider's first-use work on php-vio's worker thread (vio_upscaler_prepare):
 * started once, then looked at every {@see POLL_SECONDS}, not per frame.
 *
 * States are php-vio's: `idle` (nothing started; after {@see start()} it means the
 * plugin cannot prepare away from the render thread), `preparing`, `ready`, `failed`.
 */
final class UpscalerPreparation
{
    public const POLL_SECONDS = 0.3;

    /** A blocking caller gives up on a worker that never ends. */
    private const WAIT_SECONDS = 30.0;

    private string $state = 'idle';
    private bool $started = false;
    private float $nextPoll = 0.0;
    private readonly \Closure $clock;

    /**
     * @param \Closure(): bool                $prepare Starts the work, returns at once
     * @param \Closure(): array<string, mixed> $info    vio_upscaler_info() that starts nothing
     * @param (\Closure(): float)|null        $clock   Seconds
     */
    public function __construct(
        private readonly \Closure $prepare,
        private readonly \Closure $info,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
    }

    public function started(): bool
    {
        return $this->started;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function isRunning(): bool
    {
        return $this->state === 'preparing';
    }

    public function start(): string
    {
        if (!$this->started) {
            $this->started = true;
            ($this->prepare)();
            $this->look();
        }
        return $this->state;
    }

    /** The state, asking php-vio only when the interval is over and the work still runs. */
    public function poll(): string
    {
        if ($this->state === 'preparing' && ($this->clock)() >= $this->nextPoll) {
            $this->look();
        }
        return $this->state;
    }

    /** Block until the work ended. For callers that promised a complete answer. */
    public function wait(): string
    {
        $end = ($this->clock)() + self::WAIT_SECONDS;
        while ($this->state === 'preparing' && ($this->clock)() < $end) {
            usleep(5000);
            $this->look();
        }
        return $this->state;
    }

    private function look(): void
    {
        $state = ($this->info)()['state'] ?? 'idle';
        $this->state = is_string($state) ? $state : 'idle';
        $this->nextPoll = ($this->clock)() + self::POLL_SECONDS;
    }
}
