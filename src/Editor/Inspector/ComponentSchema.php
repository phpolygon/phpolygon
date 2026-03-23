<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Inspector;

class ComponentSchema
{
    /**
     * @param list<PropertySchema> $properties
     */
    public function __construct(
        public readonly string $className,
        public readonly string $shortName,
        public readonly ?string $category,
        public readonly array $properties,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'class' => $this->className,
            'shortName' => $this->shortName,
            'category' => $this->category,
            'properties' => array_map(fn(PropertySchema $p) => $p->toArray(), $this->properties),
        ];
    }
}
