<?php

declare(strict_types=1);

namespace PHPolygon\Testing;

use PHPolygon\UI\Accessibility\AccessibilitySink;

/**
 * Test double a game can use for its own accessibility tests (it lives in src/,
 * unlike the engine's tests, so it is vendored). Public surface: updates, tree,
 * root, focus, handler, refuse, request(), sentIds().
 *
 * Headless stand-in for vio_a11y_update(): records every update and enforces the
 * same contract php-vio does (role and action names from src/vio_a11y.c, unique
 * ids per update, string labels, 4 non-negative bounds, children and focus must
 * be nodes of the accumulated tree), so a mapper bug fails here and not in a
 * window.
 */
class RecordingAccessibilitySink implements AccessibilitySink
{
    public const ROLES = [
        'unknown', 'window', 'dialog', 'alert_dialog', 'button', 'check_box', 'radio_button', 'switch', 'text_input',
        'multiline_text_input', 'search_input', 'password_input', 'label', 'image', 'link', 'list', 'list_item', 'list_box',
        'list_box_option', 'menu', 'menu_bar', 'menu_item', 'menu_item_check_box', 'menu_item_radio', 'tab', 'tab_list',
        'tab_panel', 'slider', 'spin_button', 'progress_indicator', 'combo_box', 'group', 'heading', 'paragraph', 'scroll_view',
        'alert', 'status', 'toolbar', 'tooltip', 'tree', 'tree_item', 'table', 'row', 'cell', 'column_header', 'row_header',
        'generic', 'pane', 'document', 'canvas', 'splitter',
    ];

    public const ACTIONS = [
        'click', 'focus', 'blur', 'expand', 'collapse', 'increment', 'decrement', 'set_value', 'scroll_into_view', 'show_context_menu',
    ];

    /** @var list<array<string, mixed>> */
    public array $updates = [];

    /** @var array<string, array<string, mixed>> the tree as vio would hold it: id => node, merged per update */
    public array $tree = [];

    public ?string $root = null;
    public ?string $focus = null;

    /** @var (callable(string, string, mixed): void)|null */
    public $handler = null;

    /** While true, update() answers false without recording (a platform that rejects the update). */
    public bool $refuse = false;

    public function __construct(private readonly bool $available = true) {}

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function update(array $update): bool
    {
        if (!$this->available || $this->refuse) {
            return false;
        }
        $rawNodes = $update['nodes'] ?? [];
        if (!is_array($rawNodes)) {
            throw new \TypeError('"nodes" must be a list of nodes');
        }

        $seen = [];
        $nodes = [];
        foreach ($rawNodes as $node) {
            if (!is_array($node)) {
                throw new \TypeError('a node must be an array');
            }
            $nodes[] = $this->validated($node, $seen);
        }

        $merged = $this->tree;
        foreach ($nodes as $node) {
            $merged[$this->idOf($node)] = $node;
        }
        if ($this->root === null && !isset($nodes[0])) {
            throw new \ValueError('the first update needs the nodes');
        }
        $root = $update['root'] ?? $this->root ?? (isset($nodes[0]) ? $this->idOf($nodes[0]) : null);
        if (!is_string($root) || !isset($merged[$root])) {
            throw new \ValueError('root is not a node of the tree');
        }
        foreach ($merged as $id => $node) {
            foreach ($this->childrenOf($node) as $child) {
                if (!isset($merged[$child])) {
                    throw new \ValueError("node \"$id\" has the child \"$child\", which is not a node");
                }
            }
        }
        $focus = $update['focus'] ?? null;
        if ($focus !== null && (!is_string($focus) || !isset($merged[$focus]))) {
            throw new \ValueError('focus is not a node of the tree');
        }

        $this->tree = $merged;
        $this->root = $root;
        $this->focus = $focus ?? $this->focus;
        $this->updates[] = $update;

        return true;
    }

    /**
     * Check one node against vio's contract and return it.
     *
     * @param array<mixed> $node
     * @param array<string, true> $seen ids already used by this update
     * @return array<string, mixed>
     */
    private function validated(array $node, array &$seen): array
    {
        $id = $node['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \ValueError('node needs a non-empty string "id"');
        }
        if (isset($seen[$id])) {
            throw new \ValueError("node id \"$id\" appears twice");
        }
        $seen[$id] = true;
        if (!in_array($node['role'] ?? null, self::ROLES, true)) {
            throw new \ValueError("node \"$id\" needs a known \"role\"");
        }
        foreach (['label', 'description'] as $stringKey) {
            if (isset($node[$stringKey]) && !is_string($node[$stringKey])) {
                throw new \TypeError("node option \"$stringKey\" must be a string");
            }
        }
        if (isset($node['value']) && !is_string($node['value']) && !is_int($node['value']) && !is_float($node['value'])) {
            throw new \TypeError("node \"$id\" \"value\" must be a string or a number");
        }
        $actions = $node['actions'] ?? [];
        foreach (is_array($actions) ? $actions : [] as $action) {
            if (!in_array($action, self::ACTIONS, true)) {
                throw new \ValueError("node \"$id\" has an unknown action");
            }
        }
        if (isset($node['bounds'])) {
            $b = $node['bounds'];
            if (!is_array($b) || count($b) !== 4) {
                throw new \ValueError("node \"$id\" \"bounds\" must be [x, y, width, height]");
            }
            if ($b[2] < 0 || $b[3] < 0) {
                throw new \ValueError("node \"$id\" \"bounds\" must not have a negative size");
            }
        }
        if (isset($node['range']) && (!is_array($node['range']) || count($node['range']) !== 2)) {
            throw new \ValueError("node \"$id\" \"range\" must be [min, max]");
        }
        foreach ($this->childrenOf($node) as $_) {
            // childrenOf() throws on a non-string child
        }

        /** @var array<string, mixed> $node */
        return $node;
    }

    /**
     * @param array<mixed> $node
     * @return list<string>
     */
    private function childrenOf(array $node): array
    {
        $children = $node['children'] ?? [];
        if (!is_array($children)) {
            throw new \TypeError('"children" must be a list of ids');
        }
        $ids = [];
        foreach ($children as $child) {
            if (!is_string($child)) {
                throw new \TypeError('"children" must be a list of ids');
            }
            $ids[] = $child;
        }

        return $ids;
    }

    /** @param array<string, mixed> $node */
    private function idOf(array $node): string
    {
        $id = $node['id'] ?? '';

        return is_string($id) ? $id : '';
    }

    public function setActionHandler(callable $handler): void
    {
        $this->handler = $handler;
    }

    /** Pretend assistive technology asked for an action. */
    public function request(string $id, string $action, mixed $value = null): void
    {
        if ($this->handler !== null) {
            ($this->handler)($id, $action, $value);
        }
    }

    /** @return list<string> ids of the nodes in update number $index */
    public function sentIds(int $index): array
    {
        $nodes = $this->updates[$index]['nodes'] ?? [];
        $ids = [];
        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (is_array($node) && is_string($node['id'] ?? null)) {
                $ids[] = $node['id'];
            }
        }

        return $ids;
    }
}
