<?php

declare(strict_types=1);

namespace PHPolygon\UI\Accessibility;

use PHPolygon\Math\Rect;
use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\Checkbox;
use PHPolygon\UI\Widget\Dropdown;
use PHPolygon\UI\Widget\Image;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Panel;
use PHPolygon\UI\Widget\ProgressBar;
use PHPolygon\UI\Widget\Slider;
use PHPolygon\UI\Widget\TabView;
use PHPolygon\UI\Widget\TextArea;
use PHPolygon\UI\Widget\TextInput;
use PHPolygon\UI\Widget\Toggle;
use PHPolygon\UI\Widget\Widget;
use PHPolygon\UI\Widget\WidgetTree;

/**
 * Turns a {@see WidgetTree} into the node list vio_a11y_update() takes.
 *
 * Ids are the widgets' focus keys ({@see WidgetTree::focusKeyOf()}): the
 * explicit Widget::$accessibleId, otherwise the child-index path, so they stay
 * the same from frame to frame and across a host that rebuilds its tree. Plain
 * layout containers produce no node of their own; their children attach to the
 * nearest ancestor that does. A container with an accessibleLabel (and a Panel)
 * becomes a labelled 'group'. Hidden widgets are left out, disabled ones are
 * kept with 'disabled'.
 *
 * Roles and actions use the names of php-vio's accessibility contract.
 */
final class WidgetAccessibilityMapper
{
    public const ROOT_ID = 'root';

    /** Roles of php-vio's accessibility contract that a widget's accessibleRole may name. */
    public const ROLES = [
        'unknown', 'window', 'dialog', 'alert_dialog', 'button', 'check_box', 'radio_button', 'switch', 'text_input',
        'multiline_text_input', 'search_input', 'password_input', 'label', 'image', 'link', 'list', 'list_item', 'list_box',
        'list_box_option', 'menu', 'menu_bar', 'menu_item', 'menu_item_check_box', 'menu_item_radio', 'tab', 'tab_list',
        'tab_panel', 'slider', 'spin_button', 'progress_indicator', 'combo_box', 'group', 'heading', 'paragraph', 'scroll_view',
        'alert', 'status', 'toolbar', 'tooltip', 'tree', 'tree_item', 'table', 'row', 'cell', 'column_header', 'row_header',
        'generic', 'pane', 'document', 'canvas', 'splitter',
    ];

    /** Role of a container => role its rows take when they are plain layout boxes. */
    private const ITEM_ROLES = [
        'list' => 'list_item',
        'list_box' => 'list_box_option',
        'menu' => 'menu_item',
        'menu_bar' => 'menu_item',
        'tree' => 'tree_item',
        'table' => 'row',
        'row' => 'cell',
        'toolbar' => 'group',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $nodes = [];

    /** @var array<string, array{0: Widget, 1: string, 2: int}> */
    private array $targets = [];

    /** @var array<string, true> */
    private array $usedIds = [];

    private WidgetTree $tree;

    /** @var (callable(Rect): Rect)|null */
    private $transform = null;

    /**
     * @param (callable(Rect): Rect)|null $boundsTransform maps a widget's design-space
     *        bounds to logical window coordinates (letterboxing, UI scale, a sub-region
     *        viewport). Null: the bounds are sent as they are.
     */
    public function map(WidgetTree $tree, string $windowLabel = '', ?callable $boundsTransform = null): AccessibilitySnapshot
    {
        $this->tree = $tree;
        $this->transform = $boundsTransform;
        $this->nodes = [];
        $this->targets = [];
        $this->usedIds = [self::ROOT_ID => true];

        $top = $this->walk($tree->getRoot());

        $root = ['id' => self::ROOT_ID, 'role' => 'window'];
        if ($windowLabel !== '') {
            $root['label'] = $windowLabel;
        }
        $root['children'] = $top;
        $nodes = [self::ROOT_ID => $root] + $this->nodes;

        return new AccessibilitySnapshot(self::ROOT_ID, $nodes, $this->targets, $this->focusNodeId($tree));
    }

    /** The node the tree's focused widget stands for, or null when nothing mapped has focus. */
    private function focusNodeId(WidgetTree $tree): ?string
    {
        $focusId = null;
        $focused = $tree->getFocusedWidget();
        if ($focused !== null) {
            // A tab view's focus sits on its selected tab; any other widget on its own node.
            $wantPart = $focused instanceof TabView ? 'tab' : 'widget';
            $wantIndex = $focused instanceof TabView ? $focused->selectedIndex : 0;
            foreach ($this->targets as $id => [$widget, $part, $index]) {
                if ($widget === $focused && $part === $wantPart && $index === $wantIndex) {
                    $focusId = $id;
                    break;
                }
            }
        }

        return $focusId;
    }

    /**
     * @return list<string> ids of the nodes this widget contributes to its parent
     */
    private function walk(Widget $widget, string $itemRole = ''): array
    {
        if (!$widget->visible) {
            return [];
        }

        $id = $this->idFor($widget);

        if ($widget instanceof TabView) {
            return [$this->tabView($widget, $id)];
        }

        $own = $this->ownNode($widget, $id);
        // Rows of a list, menu, tree or table become items of it even when the row is a plain layout box.
        $childItemRole = $own !== null && is_string($own['role'] ?? null) ? (self::ITEM_ROLES[$own['role']] ?? '') : '';
        $childIds = [];
        // A dropdown's options are not widgets; the node mapping adds them itself.
        if (!$widget instanceof Dropdown) {
            foreach ($widget->getFocusChildren() as $child) {
                array_push($childIds, ...$this->walk($child, $childItemRole));
            }
        }

        if ($own === null && $itemRole !== '' && $childIds !== []) {
            $own = $this->base($id, $itemRole, '', $widget);
        }
        if ($own === null) {
            return $childIds;
        }
        if ($widget instanceof Dropdown) {
            $childIds = $this->dropdownOptions($widget, $id, $own);
        }
        if ($childIds !== []) {
            $own['children'] = $childIds;
        }
        $this->nodes[$id] = $own;
        $this->targets[$id] = [$widget, 'widget', 0];

        return [$id];
    }

    /**
     * The node of a widget that has one, or null for a plain container or an
     * element with nothing to say (an empty label, an unlabelled image).
     *
     * @return array<string, mixed>|null
     */
    private function ownNode(Widget $widget, string $id): ?array
    {
        $node = $this->typedNode($widget, $id);
        $role = in_array($widget->accessibleRole, self::ROLES, true) ? $widget->accessibleRole : '';
        if ($role === '') {
            return $node;
        }
        if ($node === null) {
            return $this->base($id, $role, $widget->accessibleLabel, $widget);
        }
        $node['role'] = $role;

        return $node;
    }

    /**
     * The node the widget's type gives it, before an accessibleRole override.
     *
     * @return array<string, mixed>|null
     */
    private function typedNode(Widget $widget, string $id): ?array
    {
        $tooltipHead = $this->firstLine($widget->tooltip);
        $explicit = $widget->accessibleLabel;

        if ($widget instanceof Button) {
            $node = $this->base($id, 'button', $this->first($explicit, $widget->label, $tooltipHead), $widget);
            $node['actions'] = ['click', 'focus'];
        } elseif ($widget instanceof Checkbox) {
            $node = $this->base($id, 'check_box', $this->first($explicit, $widget->label, $tooltipHead), $widget);
            $node['checked'] = $widget->checked;
            $node['actions'] = ['click', 'focus'];
        } elseif ($widget instanceof Toggle) {
            $node = $this->base($id, 'switch', $this->first($explicit, $widget->label, $tooltipHead), $widget);
            $node['checked'] = $widget->on;
            $node['actions'] = ['click', 'focus'];
        } elseif ($widget instanceof Slider) {
            $node = $this->base($id, 'slider', $this->first($explicit, $widget->label, $tooltipHead), $widget);
            $node['value'] = $widget->valueText !== '' ? $widget->valueText : $widget->value;
            $node['range'] = [$widget->min, $widget->max];
            $node['actions'] = ['increment', 'decrement', 'set_value', 'focus'];
        } elseif ($widget instanceof Dropdown) {
            $node = $this->base($id, 'combo_box', $this->first($explicit, $widget->label, $tooltipHead), $widget);
            $selected = $widget->getSelectedValue();
            if ($selected !== null) {
                $node['value'] = $selected;
            }
            $node['actions'] = ['click', 'focus', $widget->open ? 'collapse' : 'expand'];
        } elseif ($widget instanceof TextInput) {
            $name = $this->first($explicit, $widget->label, $widget->placeholder, $tooltipHead);
            $node = $this->base($id, $widget instanceof TextArea ? 'multiline_text_input' : 'text_input', $name, $widget);
            $node['value'] = $widget->text;
            $node['actions'] = ['focus', 'set_value'];
        } elseif ($widget instanceof ProgressBar) {
            $node = $this->base($id, 'progress_indicator', $this->first($explicit, $widget->label, $tooltipHead), $widget);
            $node['value'] = $widget->value;
            $node['range'] = [0.0, 1.0];
        } elseif ($widget instanceof Label) {
            $text = $this->first($explicit, $widget->text);
            if ($text === '') {
                return null;
            }
            $node = $this->base($id, 'label', $text, $widget);
        } elseif ($widget instanceof Image) {
            $name = $this->first($explicit, $tooltipHead);
            if ($name === '') {
                return null;
            }
            $node = $this->base($id, 'image', $name, $widget);
        } elseif ($widget instanceof Panel) {
            $node = $this->base($id, 'group', $this->first($explicit, $widget->title), $widget);
        } elseif ($explicit !== '') {
            $node = $this->base($id, 'group', $explicit, $widget);
        } else {
            return null;
        }

        return $node;
    }

    /**
     * The fields every node carries, in a fixed order so equal nodes compare equal.
     *
     * The description is the explicit one, else the tooltip; when the tooltip's
     * first line already became the name, only the rest of it is the description.
     *
     * @return array<string, mixed>
     */
    private function base(string $id, string $role, string $label, Widget $widget): array
    {
        $node = ['id' => $id, 'role' => $role];
        if ($label !== '') {
            $node['label'] = $label;
        }
        $description = $widget->accessibleDescription;
        if ($description === '' && $widget->tooltip !== '') {
            $head = $this->firstLine($widget->tooltip);
            $description = $label === $head ? trim(substr($widget->tooltip, strlen($head))) : $widget->tooltip;
        }
        if ($description !== '') {
            $node['description'] = $description;
        }
        $node['bounds'] = $this->bounds($widget->getBounds());
        if (!$widget->enabled) {
            $node['disabled'] = true;
        }

        return $node;
    }

    /** The first argument that is not an empty string ('0' counts), or ''. */
    private function first(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * A tab view: a group holding a tab list (one 'tab' node per tab, the shown
     * one checked) followed by the shown tab's content.
     */
    private function tabView(TabView $view, string $id): string
    {
        $listId = $this->unique($id . '/tabs');
        $tabIds = [];
        foreach ($view->getTabTitles() as $index => $title) {
            $tabId = $this->unique($id . '/tab/' . $index);
            $this->nodes[$tabId] = [
                'id' => $tabId,
                'role' => 'tab',
                'label' => $title,
                'bounds' => $this->bounds($view->getTabRect($index)),
                'checked' => $index === $view->selectedIndex,
                'actions' => ['click'],
            ];
            $this->targets[$tabId] = [$view, 'tab', $index];
            $tabIds[] = $tabId;
        }
        $this->nodes[$listId] = ['id' => $listId, 'role' => 'tab_list', 'children' => $tabIds];

        $content = [];
        foreach ($view->getFocusChildren() as $child) {
            array_push($content, ...$this->walk($child));
        }

        $group = $this->base($id, 'group', $view->accessibleLabel, $view);
        $group['children'] = [$listId, ...$content];
        $this->nodes[$id] = $group;
        $this->targets[$id] = [$view, 'widget', 0];

        return $id;
    }

    /**
     * An open dropdown lists its options as child nodes; a closed one has none.
     *
     * @param array<string, mixed> $own
     * @return list<string>
     */
    private function dropdownOptions(Dropdown $dropdown, string $id, array $own): array
    {
        if (!$dropdown->open) {
            return [];
        }
        $ids = [];
        foreach ($dropdown->options as $index => $option) {
            $optionId = $this->unique($id . '/option/' . $index);
            $this->nodes[$optionId] = [
                'id' => $optionId,
                'role' => 'list_box_option',
                'label' => $option,
                'bounds' => $this->bounds($dropdown->getOptionRect($index)),
                'checked' => $index === $dropdown->selectedIndex,
                'actions' => ['click'],
            ];
            $this->targets[$optionId] = [$dropdown, 'option', $index];
            $ids[] = $optionId;
        }

        return $ids;
    }

    private function idFor(Widget $widget): string
    {
        $key = $this->tree->focusKeyOf($widget);
        if ($key === '') {
            // Not reachable from the root through child links (it should be): still unique.
            $key = 'obj:' . spl_object_id($widget);
        }

        return $this->unique($key);
    }

    /** vio rejects a repeated id: a second use of one (an explicit accessibleId twice) gets a suffix. */
    private function unique(string $id): string
    {
        $candidate = $id;
        for ($n = 2; isset($this->usedIds[$candidate]); $n++) {
            $candidate = $id . '#' . $n;
        }
        $this->usedIds[$candidate] = true;

        return $candidate;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function bounds(Rect $rect): array
    {
        if ($this->transform !== null) {
            $rect = ($this->transform)($rect);
        }

        return [
            round($rect->x, 1),
            round($rect->y, 1),
            max(0.0, round($rect->width, 1)),
            max(0.0, round($rect->height, 1)),
        ];
    }

    private function firstLine(string $text): string
    {
        $newline = strpos($text, "\n");

        return $newline === false ? $text : substr($text, 0, $newline);
    }
}
