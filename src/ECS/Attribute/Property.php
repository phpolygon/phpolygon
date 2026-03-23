<?php

declare(strict_types=1);

namespace PHPolygon\ECS\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Property
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $type = null,
        public readonly ?string $editorHint = null,
        public readonly ?string $description = null,
    ) {}
}
