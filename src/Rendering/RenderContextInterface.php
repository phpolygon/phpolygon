<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

interface RenderContextInterface
{
    public function beginFrame(): void;

    public function endFrame(): void;

    public function clear(Color $color): void;

    public function setViewport(int $x, int $y, int $width, int $height): void;

    public function getWidth(): int;

    public function getHeight(): int;
}
