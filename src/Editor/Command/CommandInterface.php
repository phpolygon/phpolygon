<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;

interface CommandInterface
{
    /** @return array<string, mixed> */
    public function execute(EditorContext $context): array;
}
