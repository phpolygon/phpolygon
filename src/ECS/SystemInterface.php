<?php

declare(strict_types=1);

namespace PHPolygon\ECS;

interface SystemInterface
{
    public function register(World $world): void;

    public function unregister(World $world): void;

    public function update(World $world, float $dt): void;

    public function render(World $world): void;
}
