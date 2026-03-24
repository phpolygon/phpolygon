<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class GetEntityHierarchyCommand implements CommandInterface
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

        return ['entities' => $this->buildTree($doc->getEntities())];
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @return list<array<string, mixed>>
     */
    private function buildTree(array $entities): array
    {
        $result = [];
        foreach ($entities as $entity) {
            /** @var list<array<string, mixed>> $components */
            $components = $entity['components'] ?? [];
            $node = [
                'name' => $entity['name'],
                'components' => array_map(
                    fn(array $c) => $c['_class'] ?? 'Unknown',
                    $components,
                ),
            ];
            if (!empty($entity['children'])) {
                /** @var list<array<string, mixed>> $children */
                $children = $entity['children'];
                $node['children'] = $this->buildTree($children);
            }
            $result[] = $node;
        }
        return $result;
    }
}
