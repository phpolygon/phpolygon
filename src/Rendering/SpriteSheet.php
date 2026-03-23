<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Rect;

class SpriteSheet
{
    /** @var array<string, Rect> Named UV regions */
    private array $regions = [];

    public function __construct(
        public readonly string $textureId,
        public readonly int $spriteWidth,
        public readonly int $spriteHeight,
    ) {}

    public function defineRegion(string $name, float $x, float $y, float $w, float $h): void
    {
        $this->regions[$name] = new Rect($x, $y, $w, $h);
    }

    public function getRegion(string $name): ?Rect
    {
        return $this->regions[$name] ?? null;
    }

    /**
     * Auto-generate grid-based regions.
     * Names follow pattern: "row_col" (e.g., "0_0", "0_1", "1_0")
     */
    public function generateGrid(int $textureWidth, int $textureHeight): void
    {
        $cols = intdiv($textureWidth, $this->spriteWidth);
        $rows = intdiv($textureHeight, $this->spriteHeight);

        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                $name = "{$row}_{$col}";
                $this->regions[$name] = new Rect(
                    (float)($col * $this->spriteWidth),
                    (float)($row * $this->spriteHeight),
                    (float)$this->spriteWidth,
                    (float)$this->spriteHeight,
                );
            }
        }
    }

    /** @return array<string, Rect> */
    public function getRegions(): array
    {
        return $this->regions;
    }
}
