<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class GetEntityHierarchyCommand implements CommandInterface
{
    public function __construct(private readonly array $args = []) {}

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
            $node = [
                'name' => $entity['name'],
                'components' => array_map(
                    fn(array $c) => $c['_class'] ?? 'Unknown',
                    $entity['components'] ?? [],
                ),
            ];
            if (!empty($entity['children'])) {
                $node['children'] = $this->buildTree($entity['children']);
            }
            $result[] = $node;
        }
        return $result;
    }
}
