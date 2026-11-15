<?php

declare(strict_types=1);

namespace PHPolygon\UI\Accessibility;

use PHPolygon\UI\Widget\Widget;

/**
 * One mapped state of a widget tree: the accessibility nodes, which widget (and
 * which part of it) each node stands for, and where focus is.
 */
final class AccessibilitySnapshot
{
    /**
     * @param array<string, array<string, mixed>> $nodes   node id => node, the window root first
     * @param array<string, array{0: Widget, 1: string, 2: int}> $targets node id => [widget, part, index];
     *        part is 'widget', 'tab' or 'option', index the tab/option number (0 for 'widget')
     */
    public function __construct(
        public readonly string $rootId,
        public readonly array $nodes,
        public readonly array $targets,
        public readonly ?string $focusId,
    ) {}
}
