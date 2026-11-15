<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Accessibility;

use PHPolygon\UI\Accessibility\AccessibilitySink;

/**
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

    public function __construct(private readonly bool $available = true) {}

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function update(array $update): bool
    {
        if (!$this->available) {
            return false;
        }
        $seen = [];
        foreach ($update['nodes'] ?? [] as $node) {
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
            foreach ($node['actions'] ?? [] as $action) {
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
            foreach ($node['children'] ?? [] as $child) {
                if (!is_string($child)) {
                    throw new \TypeError("node \"$id\" \"children\" must be a list of ids");
                }
            }
        }

        $merged = $this->tree;
        foreach ($update['nodes'] ?? [] as $node) {
            $merged[$node['id']] = $node;
        }
        if ($this->root === null && !isset($update['nodes'][0])) {
            throw new \ValueError('the first update needs the nodes');
        }
        $root = $update['root'] ?? $this->root ?? ($update['nodes'][0]['id'] ?? null);
        if ($root === null || !isset($merged[$root])) {
            throw new \ValueError('root is not a node of the tree');
        }
        foreach ($merged as $id => $node) {
            foreach ($node['children'] ?? [] as $child) {
                if (!isset($merged[$child])) {
                    throw new \ValueError("node \"$id\" has the child \"$child\", which is not a node");
                }
            }
        }
        if (isset($update['focus']) && !isset($merged[$update['focus']])) {
            throw new \ValueError('focus is not a node of the tree');
        }

        $this->tree = $merged;
        $this->root = $root;
        $this->focus = $update['focus'] ?? $this->focus;
        $this->updates[] = $update;

        return true;
    }

    public function setActionHandler(callable $handler): void
    {
        $this->handler = $handler;
    }

    /** Pretend assistive technology asked for an action. */
    public function request(string $id, string $action, mixed $value = null): void
    {
        ($this->handler)($id, $action, $value);
    }

    /** @return list<string> ids of the nodes in update number $index */
    public function sentIds(int $index): array
    {
        return array_map(static fn (array $n): string => $n['id'], $this->updates[$index]['nodes'] ?? []);
    }
}
