<?php

declare(strict_types=1);

namespace PHPolygon\Scene\Transpiler;

use RuntimeException;

/**
 * Editor / on-disk format for a single reusable prefab: one root entity node
 * plus its descendant hierarchy.
 *
 * It shares the entity/component/children shape of {@see JsonSceneFormat}, but
 * is rooted at a single node — a scene is a list of entities, a prefab is one
 * subtree that can be stamped into a scene many times. Transform3D's runtime
 * `parentEntityId` / `childEntityIds` are deliberately NOT part of the format:
 * hierarchy is structural (the `children` nesting), so a prefab can be
 * instantiated repeatedly with fresh entity ids.
 *
 *   {
 *     "_version": 1,
 *     "name": "street_lantern",
 *     "root": {
 *       "name": "Lantern",
 *       "components": [ { "_class": "...\\Transform3D", "position": [0,0,0], ... } ],
 *       "children": [ { "name": "Lantern_Bulb", "components": [ ... ] } ]
 *     }
 *   }
 */
class PrefabDocument
{
    public const VERSION = 1;

    /**
     * @param array<string, mixed> $data
     * @throws RuntimeException
     */
    public static function validate(array $data): void
    {
        $version = $data['_version'] ?? null;
        if ($version !== null && (!is_int($version) || $version > self::VERSION)) {
            $versionStr = is_int($version) ? (string) $version : '0';
            throw new RuntimeException(
                "Prefab format version {$versionStr} is not supported (max: " . self::VERSION . ")"
            );
        }

        if (!isset($data['name']) || !is_string($data['name'])) {
            throw new RuntimeException("Prefab JSON must have a 'name' string field");
        }

        if (!isset($data['root']) || !is_array($data['root'])) {
            throw new RuntimeException("Prefab JSON must have a 'root' node object");
        }

        /** @var array<string, mixed> $root */
        $root = $data['root'];
        self::validateNode($root, 'root');
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function validateNode(array $node, string $path): void
    {
        if (!isset($node['name']) || !is_string($node['name'])) {
            throw new RuntimeException("Prefab node at {$path} must have a 'name' string field");
        }

        if (!isset($node['components']) || !is_array($node['components'])) {
            throw new RuntimeException("Prefab node at {$path} must have a 'components' array field");
        }

        foreach ($node['components'] as $j => $component) {
            if (!is_array($component) || !isset($component['_class']) || !is_string($component['_class'])) {
                throw new RuntimeException(
                    "Component at {$path}.components[{$j}] must have a '_class' string field"
                );
            }
        }

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $j => $child) {
                if (!is_array($child)) {
                    throw new RuntimeException("Child at {$path}.children[{$j}] must be an object");
                }
                /** @var array<string, mixed> $child */
                self::validateNode($child, "{$path}.children[{$j}]");
            }
        }
    }
}
