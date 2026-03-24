<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;

interface CommandInterface
{
    /**
     * @param array<string, mixed> $args
     */
    public function __construct(array $args = []);

    /** @return array<string, mixed> */
    public function execute(EditorContext $context): array;
}
