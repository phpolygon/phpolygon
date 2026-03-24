<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class RenameEntityCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->activeDocument;
        if ($doc === null) {
            throw new RuntimeException("No active scene document");
        }

        $oldName = is_string($this->args['oldName'] ?? null) ? $this->args['oldName'] : null;
        $newName = is_string($this->args['newName'] ?? null) ? $this->args['newName'] : null;

        if ($oldName === null || $newName === null) {
            throw new RuntimeException("Missing 'oldName' or 'newName' argument");
        }

        $doc->renameEntity($oldName, $newName);

        return ['oldName' => $oldName, 'newName' => $newName];
    }
}
