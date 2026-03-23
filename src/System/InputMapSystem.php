<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Input\InputMap;
use PHPolygon\Runtime\Input;

class InputMapSystem extends AbstractSystem
{
    public function __construct(
        private readonly InputMap $inputMap,
        private readonly Input $input,
    ) {}

    public function update(World $world, float $dt): void
    {
        $this->inputMap->poll($this->input);
    }

    public function getInputMap(): InputMap
    {
        return $this->inputMap;
    }
}
