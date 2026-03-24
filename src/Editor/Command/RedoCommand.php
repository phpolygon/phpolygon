<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class RedoCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    /** @param array<string, mixed> $args */
    public function __construct(array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->activeDocument;
        if ($doc === null) {
            throw new RuntimeException("No active scene document");
        }

        $doc->redo();

        return ['redone' => true, 'canUndo' => $doc->canUndo(), 'canRedo' => $doc->canRedo()];
    }
}
