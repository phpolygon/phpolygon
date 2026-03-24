<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class GetComponentSchemaCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        if (!isset($this->args['class'])) {
            throw new RuntimeException("Missing 'class' argument");
        }
        $class = is_string($this->args['class']) ? $this->args['class'] : '';

        return $context->components->get($class)->toArray();
    }
}
