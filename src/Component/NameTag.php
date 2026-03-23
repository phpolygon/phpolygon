<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

#[Serializable]
class NameTag extends AbstractComponent
{
    #[Property]
    public string $name;

    public function __construct(string $name = '')
    {
        $this->name = $name;
    }
}
