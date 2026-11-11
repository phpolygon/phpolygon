<?php

declare(strict_types=1);

namespace PHPolygon\Log;

/**
 * Logs gamepad button edges and pads coming and going.
 *
 * The engine has no gamepad layer of its own that could report edges, so
 * this one polls: the buttons of every known pad once per frame, and which
 * pads exist at most once per {@see $scanSeconds} - asking the system for
 * absent pads is the expensive part. Nothing is written while a button is
 * held. Axes and triggers are not logged.
 *
 * Opt-in ({@see \PHPolygon\EngineConfig::$logGamepads}).
 */
final class GamepadEdgeLog
{
    /** @var array<int, array<int, bool>> last button states per pad id */
    private array $buttons = [];
    private float $nextScanAt = 0.0;

    /**
     * @param \Closure(): list<int> $pads ids of the connected pads
     * @param \Closure(int): array<int, bool> $buttonsOf button states of one pad
     * @param (\Closure(int): ?string)|null $nameOf name of a pad, for the connect line
     */
    public function __construct(
        private readonly \Closure $pads,
        private readonly \Closure $buttonsOf,
        private readonly ?\Closure $nameOf = null,
        private readonly float $scanSeconds = 1.0,
    ) {}

    /** The reader over php-vio's process-wide gamepad functions, or null without them. */
    public static function vio(): ?self
    {
        if (!function_exists('vio_gamepads') || !function_exists('vio_gamepad_buttons')) {
            return null;
        }

        return new self(
            static fn (): array => array_values(vio_gamepads()),
            static fn (int $id): array => vio_gamepad_buttons($id),
            static fn (int $id): ?string => function_exists('vio_gamepad_name') ? vio_gamepad_name($id) : null,
        );
    }

    public function poll(?float $now = null): void
    {
        try {
            $now ??= microtime(true);
            if ($now >= $this->nextScanAt) {
                $this->nextScanAt = $now + $this->scanSeconds;
                $this->scan();
            }
            foreach ($this->buttons as $id => $previous) {
                $current = ($this->buttonsOf)($id);
                foreach ($current as $button => $down) {
                    if ($down !== ($previous[$button] ?? false)) {
                        Log::input($down ? 'pad down' : 'pad up', ['pad' => $id, 'button' => $button]);
                    }
                }
                $this->buttons[$id] = $current;
            }
        } catch (\Throwable) {
            // A pad pulled mid-read: the next scan sorts it out.
        }
    }

    private function scan(): void
    {
        $present = ($this->pads)();
        foreach ($present as $id) {
            if (!isset($this->buttons[$id])) {
                $this->buttons[$id] = [];
                Log::input('pad connected', ['pad' => $id, 'name' => $this->nameOf !== null ? ($this->nameOf)($id) : null]);
            }
        }
        foreach (array_keys($this->buttons) as $id) {
            if (!in_array($id, $present, true)) {
                unset($this->buttons[$id]);
                Log::input('pad disconnected', ['pad' => $id]);
            }
        }
    }
}
