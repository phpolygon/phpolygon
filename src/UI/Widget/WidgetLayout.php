<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use RuntimeException;

/**
 * Loads and saves editor-authored UI layout files (`*.ui.json`) as widget
 * trees. This is the runtime entry point a game uses to consume a layout the
 * editor produced:
 *
 *   $root = WidgetLayout::loadFile($path);
 *   $tree = new WidgetTree($root, $renderer, $input, $w, $h);
 *
 * The file wraps the serialized root: `{ "_format": 1, "name": ..., "root": {...} }`.
 * A bare widget node (no wrapper) is also accepted.
 */
final class WidgetLayout
{
    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): Widget
    {
        $root = isset($data['root']) && is_array($data['root']) ? $data['root'] : $data;
        if (! isset($root['_widget'])) {
            throw new RuntimeException('UI layout has no root widget');
        }

        /** @var array<string, mixed> $root */
        return (new WidgetSerializer)->fromArray($root);
    }

    public static function loadFile(string $path): Widget
    {
        if (! is_file($path)) {
            throw new RuntimeException("UI layout file not found: {$path}");
        }
        $raw = file_get_contents($path);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            throw new RuntimeException("Invalid UI layout JSON: {$path}");
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    public static function saveFile(string $name, Widget $root, string $path): void
    {
        $data = [
            '_format' => 1,
            'name' => $name,
            'root' => (new WidgetSerializer)->toArray($root),
        ];
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
