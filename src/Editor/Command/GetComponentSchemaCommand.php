<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class GetComponentSchemaCommand implements CommandInterface
{
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $class = $this->args['class'] ?? null;
        if ($class === null) {
            throw new RuntimeException("Missing 'class' argument");
        }

        return $context->components->get($class)->toArray();
    }
}
