<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\UI\UIStyle;

/**
 * Root manager for the retained-mode widget system.
 *
 * Owns the root widget, drives the measure → layout → draw pipeline,
 * and dispatches input events through the widget tree.
 */
class WidgetTree
{
    // GLFW key codes read by keyboard navigation.
    private const KEY_SPACE = 32;
    private const KEY_ESCAPE = 256;
    private const KEY_ENTER = 257;
    private const KEY_TAB = 258;
    private const KEY_RIGHT = 262;
    private const KEY_LEFT = 263;
    private const KEY_DOWN = 264;
    private const KEY_UP = 265;
    private const KEY_HOME = 268;
    private const KEY_END = 269;
    private const KEY_KP_ENTER = 335;
    private const KEY_LEFT_SHIFT = 340;
    private const KEY_RIGHT_SHIFT = 344;

    private Widget $root;
    private Renderer2DInterface $renderer;
    private InputInterface $input;
    private UIStyle $style;

    private ?Widget $hoveredWidget = null;

    /** The hoverable-card Stack (if any) currently under the pointer. */
    private ?Stack $hoveredStack = null;
    private ?Widget $focusedWidget = null;
    private ?Widget $pressedWidget = null;
    private ?WidgetBinder $binder = null;

    /** Focus that survives this tree being rebuilt; see {@see FocusState}. */
    private FocusState $focusState;
    private FocusStyle $focusStyle;

    /** Tab / arrow / Enter / Esc navigation. Off by default: those are common game keys. */
    private bool $keyboardNavigation = false;

    /** With navigation on, the arrow keys also move focus between plain controls. */
    private bool $arrowsMoveFocus = false;

    /** Traversal and wrap-around stay inside this subtree (a modal dialog), when set. */
    private ?Widget $focusScope = null;

    /** @var list<callable(?Widget, ?Widget): void> */
    private array $focusListeners = [];

    private float $viewportWidth;
    private float $viewportHeight;

    /**
     * Top-left origin of the layout viewport in design space. Non-zero lets a
     * host render a tree into a sub-region of the screen (e.g. a right-hand
     * content pane beside a navigation rail) instead of filling from (0,0).
     * Hit-testing is unaffected: input is mapped into the same absolute design
     * space, so offsetting the root bounds is sufficient.
     */
    private float $viewportX;
    private float $viewportY;

    public function __construct(
        Widget $root,
        Renderer2DInterface $renderer,
        InputInterface $input,
        float $viewportWidth,
        float $viewportHeight,
        ?UIStyle $style = null,
        float $viewportX = 0.0,
        float $viewportY = 0.0,
        ?FocusState $focusState = null,
    ) {
        $this->root = $root;
        $this->renderer = $renderer;
        $this->input = $input;
        $this->viewportWidth = $viewportWidth;
        $this->viewportHeight = $viewportHeight;
        $this->style = $style ?? UIStyle::dark();
        $this->viewportX = $viewportX;
        $this->viewportY = $viewportY;
        $this->focusState = $focusState ?? new FocusState;
        $this->focusStyle = new FocusStyle;
    }

    /**
     * Load an editor-authored widget tree from a serialized `*.ui.json`.
     */
    public static function fromFile(
        string $path,
        Renderer2DInterface $renderer,
        InputInterface $input,
        float $viewportWidth,
        float $viewportHeight,
        ?UIStyle $style = null,
    ): self {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            throw new \RuntimeException("Invalid widget layout: {$path}");
        }
        /** @var array<string, mixed> $data */
        $root = (new WidgetSerializer)->fromArray($data);

        return new self($root, $renderer, $input, $viewportWidth, $viewportHeight, $style);
    }

    /**
     * Bind (or re-bind) this tree to a {@see WidgetContext} — the seam to game
     * logic. Resolves value bindings, wires two-way inputs and action bindings,
     * and expands repeaters. Call after the bound data may have changed.
     */
    public function bind(WidgetContext $context): void
    {
        $this->binder ??= new WidgetBinder;
        $this->binder->bind($this->root, $context);
    }

    public function getRoot(): Widget
    {
        return $this->root;
    }

    public function setRoot(Widget $root): void
    {
        $this->root = $root;
        $this->hoveredWidget = null;
        $this->focusedWidget = null;
        $this->pressedWidget = null;
    }

    public function getStyle(): UIStyle
    {
        return $this->style;
    }

    public function setStyle(UIStyle $style): void
    {
        $this->style = $style;
    }

    public function getFocusedWidget(): ?Widget
    {
        return $this->focusedWidget;
    }

    /**
     * Give focus to $widget (null clears it). $viaKeyboard marks focus that came
     * from keys, which is what makes the focus ring show; a click never does.
     */
    public function setFocus(?Widget $widget, bool $viaKeyboard = false): void
    {
        $previous = $this->focusedWidget;
        if ($previous instanceof TextInput) {
            $previous->focused = false;
        }
        $this->focusedWidget = $widget;
        if ($widget instanceof TextInput) {
            $widget->focused = true;
            $widget->cursorPos = mb_strlen($widget->text);
        }
        $this->focusState->widget = $widget;
        $this->focusState->key = $widget !== null ? $this->focusKeyOf($widget) : '';
        $this->focusState->visible = $widget !== null && $viaKeyboard;

        if ($previous !== $widget) {
            foreach ($this->focusListeners as $listener) {
                $listener($widget, $previous);
            }
        }
    }

    // ── Keyboard navigation ──────────────────────────────────────

    /**
     * Turn keyboard navigation on or off. On: Tab / Shift+Tab walk the focusable
     * widgets, Enter or Space activates the focused one, the arrow keys work
     * inside sliders, tab bars and open dropdowns, Esc closes a dropdown or
     * leaves focus, and the focus ring is drawn. A text field being edited keeps
     * every key except Tab and Esc. Off (the default) changes nothing: the tree
     * reads no keys except for the focused text field.
     */
    public function setKeyboardNavigation(bool $enabled): void
    {
        $this->keyboardNavigation = $enabled;
    }

    public function isKeyboardNavigation(): bool
    {
        return $this->keyboardNavigation;
    }

    /** Let the arrow keys also move focus between buttons, checkboxes and toggles. */
    public function setArrowsMoveFocus(bool $enabled): void
    {
        $this->arrowsMoveFocus = $enabled;
    }

    /** Limit traversal to a subtree (null: the whole tree), e.g. while a dialog is open. */
    public function setFocusScope(?Widget $scope): void
    {
        $this->focusScope = $scope;
    }

    public function getFocusStyle(): FocusStyle
    {
        return $this->focusStyle;
    }

    public function setFocusStyle(FocusStyle $style): void
    {
        $this->focusStyle = $style;
    }

    public function getFocusState(): FocusState
    {
        return $this->focusState;
    }

    /** True when the focus ring is being drawn: navigation on and focus set by keys. */
    public function isFocusRingVisible(): bool
    {
        return $this->keyboardNavigation && $this->focusState->visible && $this->focusedWidget !== null;
    }

    /**
     * Call $listener(?Widget $focused, ?Widget $previous) whenever focus moves
     * to another widget or is cleared.
     *
     * @param callable(?Widget, ?Widget): void $listener
     */
    public function onFocusChange(callable $listener): void
    {
        $this->focusListeners[] = $listener;
    }

    /**
     * The widgets keyboard focus visits, in order: visible, enabled, focusable
     * widgets in document order, with {@see Widget::$focusOrder} above 0 moved to
     * the front in ascending order.
     *
     * @return list<Widget>
     */
    public function getFocusOrder(): array
    {
        $found = [];
        $this->collectFocusable($this->focusScope ?? $this->root, $found);

        $explicit = [];
        $rest = [];
        foreach ($found as $widget) {
            if ($widget->focusOrder > 0) {
                $explicit[] = $widget;
            } else {
                $rest[] = $widget;
            }
        }
        // usort is stable: equal focusOrder values keep document order.
        usort($explicit, static fn (Widget $a, Widget $b): int => $a->focusOrder <=> $b->focusOrder);

        return [...$explicit, ...$rest];
    }

    /** @param list<Widget> $out */
    private function collectFocusable(Widget $widget, array &$out): void
    {
        if (!$widget->visible) {
            return;
        }
        if ($widget->isFocusable()) {
            $out[] = $widget;
        }
        foreach ($widget->getFocusChildren() as $child) {
            $this->collectFocusable($child, $out);
        }
    }

    /**
     * Identity of a widget that survives the tree being rebuilt: its explicit
     * accessibleId, otherwise its child-index path from the root ('' when the
     * widget is not under this root).
     */
    public function focusKeyOf(Widget $widget): string
    {
        if ($widget->accessibleId !== '') {
            return 'id:' . $widget->accessibleId;
        }
        $path = [];
        $node = $widget;
        while ($node !== $this->root) {
            $parent = $node->getParent();
            if ($parent === null) {
                return '';
            }
            $index = array_search($node, $parent->getChildren(), true);
            if ($index === false) {
                return '';
            }
            $path[] = $index;
            $node = $parent;
        }

        return 'path:' . implode('.', array_reverse($path));
    }

    /** Move focus to the next ($direction > 0) or previous focusable widget, wrapping around. */
    public function moveFocus(int $direction): ?Widget
    {
        $order = $this->getFocusOrder();
        $count = count($order);
        if ($count === 0) {
            return null;
        }
        $current = $this->focusedWidget;
        $index = $current !== null ? array_search($current, $order, true) : false;
        if ($index === false) {
            $next = $direction > 0 ? 0 : $count - 1;
        } else {
            $next = (($index + ($direction > 0 ? 1 : -1)) % $count + $count) % $count;
        }
        if ($current instanceof Dropdown) {
            $current->open = false;
            $current->highlightedIndex = -1;
        }
        $this->setFocus($order[$next], true);
        $this->scrollIntoView($order[$next]);

        return $order[$next];
    }

    /**
     * Do what a click (or Enter) on $widget does: press a button, flip a
     * checkbox or toggle, open or close a dropdown, focus a text field. The same
     * events fire as for the mouse. False when nothing was done (disabled, or a
     * widget with no activation).
     */
    public function activate(Widget $widget): bool
    {
        if (!$widget->enabled || !$widget->visible) {
            return false;
        }
        if ($widget instanceof Button) {
            $widget->emit('press');
            $widget->emit('click');
        } elseif ($widget instanceof Checkbox) {
            $widget->checked = !$widget->checked;
            $widget->emit('change', $widget->checked);
            $widget->emit('press');
        } elseif ($widget instanceof Toggle) {
            $widget->on = !$widget->on;
            $widget->emit('change', $widget->on);
            $widget->emit('press');
        } elseif ($widget instanceof Dropdown) {
            $widget->open = !$widget->open;
            $widget->listScrollY = 0.0;
            $widget->highlightedIndex = -1;
            if ($widget->open) {
                $widget->setHighlight($widget->selectedIndex);
            }
            $widget->emit('press');
        } elseif ($widget instanceof TextInput) {
            $this->setFocus($widget, true);
        } else {
            return false;
        }

        return true;
    }

    /**
     * Step a slider or move a tab bar by $direction (+1 / -1): a slider by its
     * key step, a tab view to the next or previous tab. False for other widgets
     * or when the value is already at the end.
     */
    public function adjust(Widget $widget, int $direction): bool
    {
        if (!$widget->enabled) {
            return false;
        }
        if ($widget instanceof Slider) {
            $step = $widget->keyStep > 0.0 ? $widget->keyStep : ($widget->max - $widget->min) / 20.0;
            $value = max($widget->min, min($widget->max, $widget->value + ($direction > 0 ? $step : -$step)));
            if ($value === $widget->value) {
                return false;
            }
            $widget->value = $value;
            $widget->emit('change', $widget->value);

            return true;
        }
        if ($widget instanceof TabView) {
            $before = $widget->selectedIndex;
            $widget->selectTab($widget->selectedIndex + ($direction > 0 ? 1 : -1));

            return $widget->selectedIndex !== $before;
        }

        return false;
    }

    /**
     * Set a value from outside (an assistive technology's set_value): a text
     * field's text, a slider's value (clamped). False when the widget takes none.
     */
    public function setWidgetValue(Widget $widget, string|int|float $value): bool
    {
        if (!$widget->enabled) {
            return false;
        }
        if ($widget instanceof TextInput) {
            $widget->text = (string) $value;
            $widget->cursorPos = mb_strlen($widget->text);
            $widget->emit('input', $widget->text);

            return true;
        }
        if ($widget instanceof Slider && is_numeric($value)) {
            $widget->value = max($widget->min, min($widget->max, (float) $value));
            $widget->emit('change', $widget->value);

            return true;
        }

        return false;
    }

    /**
     * Scroll the nearest scroll views so $widget is inside them, then lay out
     * again so its bounds (and the focus ring) are where it now is.
     */
    private function scrollIntoView(Widget $widget): void
    {
        $moved = false;
        for ($node = $widget->getParent(); $node !== null; $node = $node->getParent()) {
            if (!$node instanceof ScrollView) {
                continue;
            }
            $view = $node->getBounds();
            $rect = $widget->getBounds();
            if ($rect->y < $view->y) {
                $node->scrollBy($rect->y - $view->y);
                $moved = true;
            } elseif ($rect->bottom() > $view->bottom()) {
                $node->scrollBy($rect->bottom() - $view->bottom());
                $moved = true;
            }
        }
        if ($moved) {
            $this->performLayout();
        }
    }

    /**
     * Put focus back on the widget the shared {@see FocusState} remembers: the
     * same instance while it is still in the tree, otherwise the widget with the
     * same focus key (the host rebuilt its rows). Focus is dropped when neither
     * exists any more, or the widget can no longer take it (hidden, disabled,
     * outside the focus scope).
     */
    private function restoreKeyboardFocus(): void
    {
        $state = $this->focusState;
        if ($state->widget === null) {
            return;
        }
        $order = $this->getFocusOrder();
        if (in_array($state->widget, $order, true)) {
            $this->focusedWidget = $state->widget;

            return;
        }
        foreach ($order as $candidate) {
            if ($state->key !== '' && $this->focusKeyOf($candidate) === $state->key) {
                $this->focusedWidget = $candidate;
                $state->widget = $candidate;
                if ($candidate instanceof TextInput) {
                    $candidate->focused = true;
                }

                return;
            }
        }
        $this->setFocus(null);
        $state->clear();
    }

    /**
     * Keys while navigation is on. Returns true when a key was taken, so the
     * game does not also see it. A focused text field keeps all keys but Tab
     * and Esc.
     */
    private function handleKeyboardNavigation(): bool
    {
        $input = $this->input;
        $focused = $this->focusedWidget;

        if ($input->isKeyPressed(self::KEY_TAB)) {
            $shift = $input->isKeyDown(self::KEY_LEFT_SHIFT) || $input->isKeyDown(self::KEY_RIGHT_SHIFT);
            $this->moveFocus($shift ? -1 : 1);

            return true;
        }

        if ($focused === null) {
            return false;
        }

        if ($input->isKeyPressed(self::KEY_ESCAPE)) {
            if ($focused instanceof Dropdown && $focused->open) {
                $focused->open = false;
                $focused->highlightedIndex = -1;
            } else {
                $this->setFocus(null);
            }

            return true;
        }

        if ($focused instanceof TextInput) {
            return false;
        }

        $activate = $input->isKeyPressed(self::KEY_ENTER) || $input->isKeyPressed(self::KEY_KP_ENTER)
            || $input->isKeyPressed(self::KEY_SPACE);
        $left = $input->isKeyTyped(self::KEY_LEFT);
        $right = $input->isKeyTyped(self::KEY_RIGHT);
        $up = $input->isKeyTyped(self::KEY_UP);
        $down = $input->isKeyTyped(self::KEY_DOWN);
        $home = $input->isKeyPressed(self::KEY_HOME);
        $end = $input->isKeyPressed(self::KEY_END);

        if ($focused instanceof Dropdown) {
            if ($focused->open) {
                if ($up || $down) {
                    $focused->moveHighlight($down ? 1 : -1);
                } elseif ($home) {
                    $focused->setHighlight(0);
                } elseif ($end) {
                    $focused->setHighlight(count($focused->options) - 1);
                } elseif ($activate) {
                    if ($focused->highlightedIndex >= 0 && $focused->highlightedIndex < count($focused->options)) {
                        $focused->selectedIndex = $focused->highlightedIndex;
                        $focused->open = false;
                        $focused->highlightedIndex = -1;
                        $focused->emit('change', $focused->selectedIndex);
                    } else {
                        $focused->open = false;
                    }
                } else {
                    return false;
                }

                return true;
            }
            if ($activate || $up || $down) {
                $this->activate($focused);

                return true;
            }

            return false;
        }

        if ($focused instanceof Slider) {
            if ($left || $down) {
                $this->adjust($focused, -1);
            } elseif ($right || $up) {
                $this->adjust($focused, 1);
            } elseif ($home) {
                $this->setWidgetValue($focused, $focused->min);
            } elseif ($end) {
                $this->setWidgetValue($focused, $focused->max);
            } else {
                return false;
            }

            return true;
        }

        if ($focused instanceof TabView) {
            if ($left) {
                $this->adjust($focused, -1);
            } elseif ($right) {
                $this->adjust($focused, 1);
            } elseif ($home) {
                $focused->selectTab(0);
            } elseif ($end) {
                $focused->selectTab(count($focused->getTabTitles()) - 1);
            } else {
                return false;
            }

            return true;
        }

        if ($activate) {
            $this->activate($focused);

            return true;
        }
        if ($this->arrowsMoveFocus && ($left || $right || $up || $down)) {
            $this->moveFocus($left || $up ? -1 : 1);

            return true;
        }

        return false;
    }

    /** Outline the keyboard-focused widget, clipped to the scroll view it sits in. */
    private function drawFocusRing(): void
    {
        $widget = $this->focusedWidget;
        if ($widget === null || !$widget->visible) {
            return;
        }
        $style = $this->focusStyle;
        $rect = $widget->focusRect();
        $gap = $style->offset + $style->width * 0.5;

        $scissor = null;
        for ($node = $widget->getParent(); $node !== null; $node = $node->getParent()) {
            if ($node instanceof ScrollView) {
                $scissor = $node->getBounds();
                break;
            }
        }
        if ($scissor !== null) {
            $this->renderer->pushScissor($scissor->x, $scissor->y, $scissor->width, $scissor->height);
        }
        $this->renderer->drawRoundedRectOutline(
            $rect->x - $gap,
            $rect->y - $gap,
            $rect->width + $gap * 2.0,
            $rect->height + $gap * 2.0,
            $style->radius,
            $style->color,
            $style->width,
        );
        if ($scissor !== null) {
            $this->renderer->popScissor();
        }
    }

    public function setViewportSize(float $width, float $height): void
    {
        $this->viewportWidth = $width;
        $this->viewportHeight = $height;
    }

    /**
     * Move the layout viewport's top-left origin in design space. Defaults to
     * (0,0); set non-zero to lay the tree out into a sub-region of the screen.
     */
    public function setViewportOrigin(float $x, float $y): void
    {
        $this->viewportX = $x;
        $this->viewportY = $y;
    }

    /**
     * Full update cycle: process input → measure → layout → draw.
     */
    public function update(): void
    {
        // Layout BEFORE input: hit-testing reads each widget's bounds, and a
        // host that rebuilds the tree every frame (the data-bound pattern) has
        // not laid it out yet on this instance — so processing input first would
        // hit-test against zero bounds and never register a click. Draw last so
        // it reflects the hover/pressed state this frame's input produced.
        $this->performLayout();
        $this->processInput();
        $this->draw();
    }

    /**
     * Process mouse and keyboard input through the widget tree.
     */
    public function processInput(): void
    {
        // Clear stale hover/press flags first. A host may rebuild the tree every
        // frame while the widget instances persist (cached root); a fresh tree's
        // hoveredWidget is null, so updateHover would never reset a button that
        // was hovered on an earlier frame — leaving it stuck "hovered" (filled).
        $this->clearInteractionFlags($this->root);

        // Re-adopt text-field focus from the persistent widget state. The same
        // rebuild-every-frame host starts each tree with focusedWidget === null,
        // but a focused TextInput carries ->focused on the cached instance. Without
        // this, keyboard input (below) and the focus-based input suppression only
        // fire on the exact frame the field is clicked — so typing appears dead and
        // game hotkeys leak through while a field is focused.
        if ($this->focusedWidget === null) {
            $this->focusedWidget = $this->findFocusedTextInput($this->root);
        }
        if ($this->keyboardNavigation) {
            $this->restoreKeyboardFocus();
        }

        $mouse = $this->input->getMousePosition();

        // Clamp every open dropdown's floating list to the viewport so a long
        // option set becomes a scrollable window rather than running off the
        // bottom of the screen. Must run before hit-testing the list below, since
        // listBounds() depends on the clamp.
        $this->clampOpenDropdownLists($this->root);

        // An open dropdown's option list floats above the tree in BOTH draw and
        // input: a point inside the expanded list belongs to the dropdown, not
        // whatever sibling (a ScrollView, a card list) happens to sit beneath it.
        // Resolve that first, otherwise widgetAt() would hand the click to the
        // widget underneath and the list could be neither clicked nor scrolled.
        $listDropdown = $this->openDropdownListAt($mouse);

        // Hit test
        $hit = $listDropdown ?? $this->root->widgetAt($mouse);
        $this->updateHover($hit);

        if ($listDropdown !== null) {
            // Release selects the option under the cursor. Press is swallowed so
            // it neither toggles the list shut (the field's job) nor reaches the
            // sibling below. The wheel scrolls the floating list (and is consumed
            // so it doesn't also scroll a ScrollView beneath it).
            if ($this->input->isMouseButtonReleased(0)) {
                $this->selectDropdownOption($listDropdown, $mouse);
            }
            $scrollDelta = $this->input->getScrollY();
            if (abs($scrollDelta) > 0.01) {
                $listDropdown->scrollListBy(-$scrollDelta * 30.0);
            }
            $this->input->suppress();
            return;
        }

        // A press outside every open dropdown (its field or its list) dismisses
        // it — the field being clicked is exempted so it can still toggle.
        if ($this->input->isMouseButtonPressed(0)) {
            $this->closeOpenDropdownsExcept($hit instanceof Dropdown ? $hit : null);
        }

        // Mouse press
        if ($this->input->isMouseButtonPressed(0)) {
            $this->handlePress($hit, $mouse);
        }

        // Mouse release
        if ($this->input->isMouseButtonReleased(0)) {
            $this->handleRelease($hit);
        }

        // Slider dragging
        if ($this->pressedWidget instanceof Slider && $this->input->isMouseButtonDown(0)) {
            $this->pressedWidget->value = $this->pressedWidget->valueFromMouseX($mouse->x);
            $this->pressedWidget->dragging = true;
            $this->pressedWidget->emit('change', $this->pressedWidget->value);
        } elseif ($this->pressedWidget instanceof Slider && !$this->input->isMouseButtonDown(0)) {
            $this->pressedWidget->dragging = false;
            $this->pressedWidget = null;
        }

        // Keyboard navigation (opt-in). Runs before the text field reads its keys
        // so Tab can leave the field; every other key stays with the field.
        $navigationTookKey = $this->keyboardNavigation && $this->handleKeyboardNavigation();

        // Keyboard input for focused text input
        if ($this->focusedWidget instanceof TextInput) {
            $this->handleTextInput($this->focusedWidget);
        }

        // Scroll handling. A text area scrolls its own lines and takes the wheel,
        // so a ScrollView around it does not move at the same time.
        if ($this->hoveredWidget instanceof TextArea) {
            $lines = (int) round(-$this->input->getScrollY());
            if ($lines !== 0) {
                $this->hoveredWidget->scrollLines($lines);
            }
        } elseif ($this->hoveredWidget !== null) {
            $scrollView = $this->findParentScrollView($this->hoveredWidget);
            if ($scrollView !== null) {
                $scrollView->handleScroll($this->input);
            }
        }

        // Suppress game input only when the pointer is over an actual control
        // (or a text field has focus) — not merely over a layout container. A
        // full-screen panel's root/box/labels would otherwise suppress the whole
        // frame's remaining input, killing immediate-mode UI drawn on top of the
        // panel (dialogs, toasts, the tutorial strip) in the same frame.
        $hoverIsControl = $this->hoveredWidget instanceof Button
            || $this->hoveredWidget instanceof Slider
            || $this->hoveredWidget instanceof Checkbox
            || $this->hoveredWidget instanceof Toggle
            || $this->hoveredWidget instanceof Dropdown
            || $this->hoveredWidget instanceof TextInput;
        // With navigation on, a focused button or slider does not swallow the
        // game's keys; only a key the tree actually used, or a field being typed
        // into, does.
        $focusSuppresses = $this->focusedWidget !== null
            && (!$this->keyboardNavigation || $this->focusedWidget instanceof TextInput);
        if ($hoverIsControl || $focusSuppresses || $navigationTookKey) {
            $this->input->suppress();
        }
    }

    /**
     * Measure and layout the widget tree.
     */
    public function performLayout(): void
    {
        $this->root->measure($this->viewportWidth, $this->viewportHeight, $this->style);
        $this->root->setBounds(new Rect($this->viewportX, $this->viewportY, $this->viewportWidth, $this->viewportHeight));
        $this->root->layout($this->style);
    }

    /**
     * Draw the widget tree.
     */
    public function draw(): void
    {
        $this->renderer->setFont($this->style->fontName);
        $this->root->draw($this->renderer, $this->style);
        // Open dropdown lists and tooltips float above the whole tree so they
        // are never covered by a sibling drawn later (e.g. a ScrollView below
        // the filter row). Dropdowns first, tooltip last (truly top-most).
        $this->drawOpenDropdowns();
        if ($this->isFocusRingVisible()) {
            $this->drawFocusRing();
        }
        $this->drawTooltip();
    }

    /**
     * Top-most overlay pass for expanded dropdown lists. A Dropdown draws only
     * its field inline; the open list is drawn here, after the entire tree, so
     * it floats above any following sibling instead of being painted over.
     * Draws every open dropdown (opening one does not force-close others).
     */
    private function drawOpenDropdowns(): void
    {
        $this->renderer->setFont($this->style->fontName);
        $this->forEachOpenDropdown($this->root);
    }

    private function forEachOpenDropdown(Widget $widget): void
    {
        if ($widget instanceof Dropdown && $widget->open) {
            $widget->drawOpenList($this->renderer, $this->style);
        }
        foreach ($widget->getChildren() as $child) {
            $this->forEachOpenDropdown($child);
        }
    }

    /**
     * Render the hovered widget's tooltip (if any) as a word-wrapped box near the
     * cursor, clamped to the viewport. Drawn after the tree — so it floats above
     * everything, including a ScrollView's clipped content — with the first line
     * shown as a heading.
     */
    private function drawTooltip(): void
    {
        $text = $this->hoveredWidget !== null ? $this->hoveredWidget->tooltip : '';
        if ($text === '') {
            return;
        }

        $r = $this->renderer;
        $r->setFont($this->style->fontName);

        $fontSize = 13.0;
        $pad = 8.0;
        $boxW = 270.0;
        $inner = $boxW - $pad * 2.0;

        // Split heading (first line) from the body so the heading can stand out.
        $nl = strpos($text, "\n");
        $heading = $nl === false ? $text : substr($text, 0, $nl);
        $body = $nl === false ? '' : substr($text, $nl + 1);

        $headingH = $fontSize + 6.0;
        $bodyH = $body !== '' ? $r->measureTextBox($body, $inner, $fontSize)->height : 0.0;
        $boxH = $pad * 2.0 + $headingH + $bodyH;

        $mouse = $this->input->getMousePosition();
        $tx = min($mouse->x + 16.0, $this->viewportWidth - $boxW - 8.0);
        $ty = min($mouse->y + 18.0, $this->viewportHeight - $boxH - 8.0);
        $tx = max(8.0, $tx);
        $ty = max(8.0, $ty);

        $r->drawRoundedRect($tx, $ty, $boxW, $boxH, 6.0, new Color(0.04, 0.07, 0.06, 0.98));

        $r->setTextAlign(TextAlign::LEFT | TextAlign::TOP);
        $r->drawText($heading, $tx + $pad, $ty + $pad, $fontSize, $this->style->accentColor);
        if ($body !== '') {
            $r->setTextAlign(TextAlign::LEFT | TextAlign::TOP);
            $r->drawTextBox($body, $tx + $pad, $ty + $pad + $headingH, $inner, $fontSize, $this->style->textColor);
        }
    }

    // ── Internals ────────────────────────────────────────────────

    /** Recursively clear transient hover/press flags before this frame's input. */
    private function clearInteractionFlags(Widget $widget): void
    {
        if ($widget instanceof Button) {
            $widget->hovered = false;
            $widget->pressed = false;
        }
        if ($widget instanceof Stack) {
            $widget->hovered = false;
        }
        foreach ($widget->getChildren() as $child) {
            $this->clearInteractionFlags($child);
        }
    }

    /**
     * Find the focused TextInput in the tree, if any. Focus lives on the widget
     * instance (->focused), which survives a host that rebuilds the tree every
     * frame around a cached root; this re-derives the tree's focusedWidget from
     * that persistent state. Returns the first match in pre-order.
     */
    private function findFocusedTextInput(Widget $widget): ?TextInput
    {
        if ($widget instanceof TextInput && $widget->focused) {
            return $widget;
        }
        foreach ($widget->getChildren() as $child) {
            $found = $this->findFocusedTextInput($child);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    private function updateHover(?Widget $hit): void
    {
        if ($hit !== $this->hoveredWidget) {
            if ($this->hoveredWidget instanceof Button) {
                $this->hoveredWidget->hovered = false;
                $this->hoveredWidget->emit('hoverend');
            }
            $this->hoveredWidget = $hit;
            if ($hit instanceof Button) {
                $hit->hovered = true;
                $hit->emit('hoverstart');
            }
        }

        // Card-hover: tint the nearest ancestor Stack that opted in via hoverColor
        // (e.g. a rich list card whose whole-card click target is a flat overlay).
        $stack = $hit !== null ? $this->hoverableStackAncestor($hit) : null;
        if ($stack !== $this->hoveredStack) {
            if ($this->hoveredStack !== null) {
                $this->hoveredStack->hovered = false;
            }
            $this->hoveredStack = $stack;
            if ($stack !== null) {
                $stack->hovered = true;
            }
        }
    }

    /** The nearest ancestor (or self) Stack that set a hoverColor, or null. */
    private function hoverableStackAncestor(Widget $widget): ?Stack
    {
        for ($node = $widget; $node !== null; $node = $node->getParent()) {
            if ($node instanceof Stack && $node->hoverColor !== null) {
                return $node;
            }
        }

        return null;
    }

    private function handlePress(?Widget $hit, Vec2 $mouse): void
    {
        // Focus management
        if ($hit instanceof TextInput) {
            $this->setFocus($hit);
        } else {
            $this->setFocus(null);
        }

        if ($hit === null || !$hit->enabled) return;

        // Button
        if ($hit instanceof Button) {
            $hit->pressed = true;
            $this->pressedWidget = $hit;
        }

        // Checkbox
        if ($hit instanceof Checkbox) {
            $hit->checked = !$hit->checked;
            $hit->emit('change', $hit->checked);
        }

        // Toggle
        if ($hit instanceof Toggle) {
            $hit->on = !$hit->on;
            $hit->emit('change', $hit->on);
        }

        // Slider
        if ($hit instanceof Slider) {
            $hit->value = $hit->valueFromMouseX($mouse->x);
            $hit->dragging = true;
            $this->pressedWidget = $hit;
            $hit->emit('change', $hit->value);
        }

        // Dropdown
        if ($hit instanceof Dropdown) {
            $hit->open = !$hit->open;
            if ($hit->open) {
                // Reopen at the top of the list, not wherever it was last scrolled.
                $hit->listScrollY = 0.0;
            }
            $hit->highlightedIndex = -1;
        }

        // TabView — clicking a tab in the bar switches the active child.
        if ($hit instanceof TabView) {
            $tab = $hit->tabIndexAt($mouse);
            if ($tab !== null) {
                $hit->selectTab($tab);
            }
        }

        $hit->emit('press');
    }

    private function handleRelease(?Widget $hit): void
    {
        // Release over an enabled Button = click. This intentionally does NOT
        // require the release to land on the same instance a press captured:
        // a retained host may rebuild the tree and re-expand repeater rows every
        // frame (the click's press and release then fall on different Button
        // instances), which would make a press+release pairing never fire.
        // Release-only also matches the immediate-mode UIContext and sidesteps
        // macOS's phantom synthetic PRESS events. The press frame still sets
        // Button::$pressed for visual feedback when the tree does persist.
        if ($this->pressedWidget instanceof Button) {
            $this->pressedWidget->pressed = false;
        }
        $this->pressedWidget = null;

        if ($hit instanceof Button && $hit->enabled) {
            $hit->emit('click');
        }

        // Dropdown option selection
        if ($hit !== null) {
            $dropdown = $this->findParentDropdown($hit);
            if ($dropdown !== null && $dropdown->open) {
                foreach ($dropdown->options as $i => $opt) {
                    $optRect = $dropdown->getOptionRect($i);
                    if ($optRect->contains($this->input->getMousePosition())) {
                        $dropdown->selectedIndex = $i;
                        $dropdown->open = false;
                        $dropdown->emit('change', $i);
                        break;
                    }
                }
            }
        }
    }

    private function handleTextInput(TextInput $ti): void
    {
        $ti->insertChars($this->input->getCharsTyped());

        // GLFW key codes
        if ($ti instanceof TextArea) {
            // Multi-line text is edited and navigated with held keys, so it
            // takes the auto-repeat edges; a single-line field keeps its
            // one-press behaviour.
            if ($this->input->isKeyTyped(257) || $this->input->isKeyTyped(335)) $ti->newline(); // ENTER, KP_ENTER
            if ($this->input->isKeyTyped(259)) $ti->backspace();                // BACKSPACE
            if ($this->input->isKeyTyped(261)) $ti->delete();                   // DELETE
            if ($this->input->isKeyTyped(263)) $ti->moveCursorLeft();           // LEFT
            if ($this->input->isKeyTyped(262)) $ti->moveCursorRight();          // RIGHT
            if ($this->input->isKeyTyped(265)) $ti->moveCursorVertically(-1);   // UP
            if ($this->input->isKeyTyped(264)) $ti->moveCursorVertically(1);    // DOWN
            if ($this->input->isKeyTyped(268)) $ti->moveCursorToLineEdge(false); // HOME
            if ($this->input->isKeyTyped(269)) $ti->moveCursorToLineEdge(true);  // END
        } else {
            if ($this->input->isKeyPressed(259)) $ti->backspace();  // BACKSPACE
            if ($this->input->isKeyPressed(261)) $ti->delete();     // DELETE
            if ($this->input->isKeyPressed(263)) $ti->moveCursorLeft();  // LEFT
            if ($this->input->isKeyPressed(262)) $ti->moveCursorRight(); // RIGHT
        }

        $ti->emit('input', $ti->text);
    }

    /**
     * The scroll view the wheel belongs to: the nearest one above $widget that
     * actually has something to scroll.
     *
     * A view whose content fits is passed over. Nesting one inside another is
     * ordinary - a long list of cards where a card holds a short list of its
     * own - and taking the nearest one unconditionally meant the wheel
     * disappeared into a view that could not move, while the outer list sat
     * still. To the eye that is a dead patch in the middle of the page.
     *
     * A nested view that CAN scroll still keeps the wheel: moving both at once
     * would leave neither controllable.
     */
    private function findParentScrollView(Widget $widget): ?ScrollView
    {
        $current = $widget;
        $fallback = null;
        while ($current !== null) {
            if ($current instanceof ScrollView) {
                if ($current->getMaxScroll() > 0.0) {
                    return $current;
                }
                // Keep the first one as a fallback: with no scrollable view
                // anywhere up the chain, the old behaviour stands and nothing
                // changes for a single view that happens to fit.
                $fallback ??= $current;
            }
            $current = $current->getParent();
        }
        return $fallback;
    }

    private function findParentDropdown(Widget $widget): ?Dropdown
    {
        $current = $widget;
        while ($current !== null) {
            if ($current instanceof Dropdown) {
                return $current;
            }
            $current = $current->getParent();
        }
        return null;
    }

    /**
     * Clamp every open dropdown's floating list to the viewport height so a long
     * option set scrolls within a bounded window instead of overflowing offscreen.
     */
    private function clampOpenDropdownLists(Widget $widget): void
    {
        if ($widget instanceof Dropdown && $widget->open) {
            $widget->clampListToViewport($this->viewportHeight);
        }
        foreach ($widget->getChildren() as $child) {
            $this->clampOpenDropdownLists($child);
        }
    }

    /**
     * The open dropdown whose floating option list contains $mouse, or null.
     * Descends children-first so a deeper dropdown wins over an ancestor.
     */
    private function openDropdownListAt(Vec2 $mouse): ?Dropdown
    {
        return $this->findOpenDropdownList($this->root, $mouse);
    }

    private function findOpenDropdownList(Widget $widget, Vec2 $mouse): ?Dropdown
    {
        foreach ($widget->getChildren() as $child) {
            $found = $this->findOpenDropdownList($child, $mouse);
            if ($found !== null) {
                return $found;
            }
        }
        if ($widget instanceof Dropdown && $widget->open) {
            $list = $widget->listBounds();
            if ($list !== null && $list->contains($mouse)) {
                return $widget;
            }
        }
        return null;
    }

    /** Pick the option under the cursor in an open dropdown's floating list. */
    private function selectDropdownOption(Dropdown $dropdown, Vec2 $mouse): void
    {
        foreach ($dropdown->options as $i => $opt) {
            if ($dropdown->getOptionRect($i)->contains($mouse)) {
                $dropdown->selectedIndex = $i;
                $dropdown->open = false;
                $dropdown->emit('change', $i);
                return;
            }
        }
    }

    /** Close every open dropdown except $keep (the one being clicked). */
    private function closeOpenDropdownsExcept(?Dropdown $keep): void
    {
        $this->closeDropdowns($this->root, $keep);
    }

    private function closeDropdowns(Widget $widget, ?Dropdown $keep): void
    {
        if ($widget instanceof Dropdown && $widget !== $keep) {
            $widget->open = false;
            $widget->highlightedIndex = -1;
        }
        foreach ($widget->getChildren() as $child) {
            $this->closeDropdowns($child, $keep);
        }
    }
}
