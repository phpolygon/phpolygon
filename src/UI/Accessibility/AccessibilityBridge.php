<?php

declare(strict_types=1);

namespace PHPolygon\UI\Accessibility;

use PHPolygon\Math\Rect;
use PHPolygon\UI\Widget\Dropdown;
use PHPolygon\UI\Widget\TabView;
use PHPolygon\UI\Widget\Widget;
use PHPolygon\UI\Widget\WidgetTree;

/**
 * Keeps a screen reader's view of a window in step with a {@see WidgetTree}.
 *
 * Call {@see sync()} once per frame after layout. It maps the tree to nodes
 * ({@see WidgetAccessibilityMapper}), sends only the nodes that changed since the
 * last update plus the focused node, and applies actions the assistive
 * technology asked for (click, focus, set_value, increment/decrement,
 * expand/collapse). Where the platform has no accessibility support the sink
 * says so and every method is a silent no-op.
 *
 * Actions arrive on the game thread from the window's event polling; they are
 * queued and applied in the next sync() against that frame's tree, so a host
 * that rebuilds its widgets every frame still gets them applied to the live
 * ones (matched by node id).
 */
final class AccessibilityBridge
{
    /** Node ids of the two live regions {@see announce()} writes to. */
    public const LIVE_POLITE = 'announce:polite';
    public const LIVE_ASSERTIVE = 'announce:assertive';

    private const ZERO_WIDTH_SPACE = "\u{200B}";

    private readonly bool $available;
    private readonly WidgetAccessibilityMapper $mapper;

    /** @var array<string, array<string, mixed>> node id => the node as last sent */
    private array $sent = [];
    private ?string $sentFocus = null;
    private bool $started = false;

    /** @var list<array{0: string, 1: string, 2: mixed}> */
    private array $pendingActions = [];

    /** @var array{polite: string, assertive: string} the text of each live region as last announced */
    private array $announced = ['polite' => '', 'assertive' => ''];

    /** @var array{polite: bool, assertive: bool} flipped on a repeat, so an identical message is still a change */
    private array $announceFlip = ['polite' => false, 'assertive' => false];

    private ?string $lastError = null;
    private bool $failed = false;

    private readonly ?\Closure $boundsTransform;

    /**
     * @param (callable(Rect): Rect)|null $boundsTransform widget bounds (design space) to logical
     *        window coordinates, for a game that scales or letterboxes its UI. Null: as they are.
     */
    public function __construct(
        private readonly AccessibilitySink $sink,
        private readonly string $windowLabel = '',
        ?callable $boundsTransform = null,
    ) {
        $this->boundsTransform = $boundsTransform === null ? null : \Closure::fromCallable($boundsTransform);
        $this->mapper = new WidgetAccessibilityMapper();
        $this->available = $sink->isAvailable();
        if ($this->available) {
            $sink->setActionHandler(fn (string $id, string $action, mixed $value) => $this->handleAction($id, $action, $value));
        }
    }

    /** Whether updates reach a platform accessibility layer. */
    public function isAvailable(): bool
    {
        return $this->available && !$this->failed;
    }

    /**
     * The message of the contract error that switched the bridge off, if one did.
     * A node the platform rejects is a bug in the mapping; the bridge stops
     * instead of failing every frame.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Forget what was sent, so the next sync() sends the whole tree again (after
     * the window was recreated, say).
     */
    public function invalidate(): void
    {
        $this->sent = [];
        $this->sentFocus = null;
        $this->started = false;
    }

    /**
     * Queue an action requested by assistive technology. Registered with the
     * sink automatically; public so a host with its own event pump can feed it.
     */
    public function handleAction(string $id, string $action, mixed $value = null): void
    {
        if ($this->available) {
            $this->pendingActions[] = [$id, $action, $value];
        }
    }

    /**
     * Bring the platform's view up to date with $tree. True when the platform
     * has the current state (including "nothing changed"); false when the
     * bridge is unavailable or switched off.
     */
    public function sync(WidgetTree $tree): bool
    {
        if (!$this->isAvailable()) {
            $this->pendingActions = [];

            return false;
        }

        $snapshot = $this->mapper->map($tree, $this->windowLabel, $this->boundsTransform);
        if ($this->pendingActions !== []) {
            $applied = $this->applyActions($tree, $snapshot);
            if ($applied) {
                $snapshot = $this->mapper->map($tree, $this->windowLabel, $this->boundsTransform);
            }
        }

        return $this->send($snapshot);
    }

    private function send(AccessibilitySnapshot $snapshot): bool
    {
        $nodes = $snapshot->nodes;
        // The live regions hang off the window for the life of the bridge.
        $nodes[$snapshot->rootId]['children'][] = self::LIVE_POLITE;
        $nodes[$snapshot->rootId]['children'][] = self::LIVE_ASSERTIVE;
        $nodes[self::LIVE_POLITE] = $this->liveNode('polite');
        $nodes[self::LIVE_ASSERTIVE] = $this->liveNode('assertive');

        $changed = [];
        foreach ($nodes as $id => $node) {
            if (!isset($this->sent[$id]) || $this->sent[$id] !== $node) {
                $changed[] = $node;
            }
        }
        $focus = $snapshot->focusId ?? $snapshot->rootId;

        $update = [];
        if (!$this->started) {
            $update['root'] = $snapshot->rootId;
        }
        if ($changed !== []) {
            $update['nodes'] = $changed;
        }
        if ($focus !== $this->sentFocus) {
            $update['focus'] = $focus;
        }
        if ($update === []) {
            return true;
        }

        try {
            $ok = $this->sink->update($update);
        } catch (\ValueError | \TypeError $e) {
            $this->failed = true;
            $this->lastError = $e->getMessage();

            return false;
        }
        if (!$ok) {
            $this->failed = true;

            return false;
        }

        $this->started = true;
        $this->sent = $nodes;
        $this->sentFocus = $focus;

        return true;
    }

    /**
     * Have a screen reader speak a status message ("Game saved", "Level 3
     * reached") without moving focus.
     *
     * php-vio has no dedicated live-region call, so this is realised with its
     * own node roles: two permanent children of the window, a 'status' node for
     * polite messages and an 'alert' node for assertive ones, whose label is the
     * message. Changing the label is what assistive technology notices. An
     * identical repeat is made a change with a trailing zero-width space.
     *
     * Limitation: vio exposes no 'live' property, so whether the platform
     * speaks a changed status/alert label on its own depends on the platform's
     * handling of those roles; this has not been checked against a real screen
     * reader. An empty text clears the region. Before the first sync() the text
     * is held and goes out with the first tree. No-op where accessibility is
     * unavailable.
     */
    public function announce(string $text, bool $polite = true): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $kind = $polite ? 'polite' : 'assertive';
        $repeat = $text !== '' && $text === rtrim($this->announced[$kind], self::ZERO_WIDTH_SPACE);
        $this->announceFlip[$kind] = $repeat && !$this->announceFlip[$kind];
        $this->announced[$kind] = $text . ($this->announceFlip[$kind] ? self::ZERO_WIDTH_SPACE : '');

        if (!$this->started) {
            return; // goes out with the first tree
        }
        $node = $this->liveNode($kind);
        try {
            $ok = $this->sink->update(['nodes' => [$node]]);
        } catch (\ValueError | \TypeError $e) {
            $this->failed = true;
            $this->lastError = $e->getMessage();

            return;
        }
        if ($ok) {
            $this->sent[$node['id']] = $node;
        } else {
            $this->failed = true;
        }
    }

    /** @return array<string, mixed> */
    private function liveNode(string $kind): array
    {
        $node = [
            'id' => $kind === 'polite' ? self::LIVE_POLITE : self::LIVE_ASSERTIVE,
            'role' => $kind === 'polite' ? 'status' : 'alert',
        ];
        if ($this->announced[$kind] !== '') {
            $node['label'] = $this->announced[$kind];
        }

        return $node;
    }

    /** @return bool whether any action changed the tree */
    private function applyActions(WidgetTree $tree, AccessibilitySnapshot $snapshot): bool
    {
        $queue = $this->pendingActions;
        $this->pendingActions = [];
        $changed = false;

        foreach ($queue as [$id, $action, $value]) {
            $target = $snapshot->targets[$id] ?? null;
            if ($target === null) {
                continue; // the node is gone: nothing to act on
            }
            [$widget, $part, $index] = $target;
            if (!$widget->enabled) {
                continue;
            }

            if ($part === 'tab' && $widget instanceof TabView) {
                if ($action === 'click') {
                    $widget->selectTab($index);
                    $tree->setFocus($widget, true);
                    $changed = true;
                } elseif ($action === 'focus') {
                    $tree->setFocus($widget, true);
                    $changed = true;
                }
                continue;
            }

            if ($part === 'option' && $widget instanceof Dropdown) {
                if ($action === 'click' && isset($widget->options[$index])) {
                    $widget->selectedIndex = $index;
                    $widget->open = false;
                    $widget->highlightedIndex = -1;
                    $widget->emit('change', $index);
                    $changed = true;
                }
                continue;
            }

            $changed = match ($action) {
                'click' => $tree->activate($widget),
                'focus' => $this->focus($tree, $widget),
                'blur' => $this->blur($tree, $widget),
                'set_value' => is_string($value) || is_int($value) || is_float($value)
                    ? $tree->setWidgetValue($widget, $value)
                    : false,
                'increment' => $tree->adjust($widget, 1),
                'decrement' => $tree->adjust($widget, -1),
                'expand' => $widget instanceof Dropdown && !$widget->open && $tree->activate($widget),
                'collapse' => $widget instanceof Dropdown && $widget->open && $tree->activate($widget),
                default => false,
            } || $changed;
        }

        return $changed;
    }

    private function focus(WidgetTree $tree, Widget $widget): bool
    {
        if (!$widget->isFocusable()) {
            return false;
        }
        $tree->setFocus($widget, true);

        return true;
    }

    private function blur(WidgetTree $tree, Widget $widget): bool
    {
        if ($tree->getFocusedWidget() !== $widget) {
            return false;
        }
        $tree->setFocus(null);

        return true;
    }
}
