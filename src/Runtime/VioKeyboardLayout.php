<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * The current keyboard layout through php-vio's vio_key_name() (php-vio
 * >= 2.33): which letter or digit key types which character. Re-read every
 * {@see REFRESH_SECONDS}, so switching the layout while the game runs is
 * picked up. A character no key types - or no php-vio support at all - falls
 * back to the US position.
 */
final class VioKeyboardLayout implements KeyboardLayoutInterface
{
    public const float REFRESH_SECONDS = 1.0;

    /** @var \Closure(int): ?string */
    private \Closure $keyName;

    /** @var \Closure(): float */
    private \Closure $clock;

    /** @var array<string, int> */
    private array $map = [];

    private float $readAt = -INF;

    private UsKeyboardLayout $us;

    /**
     * @param (\Closure(int): ?string)|null $keyName key code -> character (default vio_key_name)
     * @param (\Closure(): float)|null      $clock   seconds (default microtime)
     */
    public function __construct(?\Closure $keyName = null, ?\Closure $clock = null)
    {
        $this->keyName = $keyName ?? (function_exists('vio_key_name')
            ? static fn (int $key): ?string => vio_key_name($key)
            : static fn (int $key): ?string => null);
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->us = new UsKeyboardLayout();
    }

    public function keyFor(string $char): int
    {
        $now = ($this->clock)();
        if ($now - $this->readAt >= self::REFRESH_SECONDS) {
            $this->read();
            $this->readAt = $now;
        }
        return $this->map[mb_strtolower($char)] ?? $this->us->keyFor($char);
    }

    private function read(): void
    {
        $this->map = [];
        $keys = array_merge(range(65, 90), range(48, 57));
        foreach ($keys as $key) {
            $name = ($this->keyName)($key);
            if ($name !== null && $name !== '' && !isset($this->map[$name])) {
                $this->map[$name] = $key;
            }
        }
    }
}
