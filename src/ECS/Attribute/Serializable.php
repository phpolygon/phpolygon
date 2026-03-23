<?php

declare(strict_types=1);

namespace PHPolygon\ECS\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Serializable
{
    public function __construct(
        public readonly ?string $name = null,
    ) {}
}
