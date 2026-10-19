<?php

declare(strict_types=1);

namespace PHPolygon\UI;

use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\Rendering\TextWrap;
use PHPolygon\Runtime\ClipboardInterface;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\Runtime\KeyboardLayoutInterface;
use PHPolygon\Runtime\KeyModifiers;
use PHPolygon\Runtime\MemoryClipboard;
use PHPolygon\Runtime\UsKeyboardLayout;
use PHPolygon\Runtime\VioClipboard;
use PHPolygon\Runtime\VioInput;
use PHPolygon\Runtime\VioKeyboardLayout;
use PHPolygon\UI\TextEdit\IndentRules;
use PHPolygon\UI\TextEdit\TextEditModel;

/**
 * Immediate-mode UI context.
 *
 * Provides widget methods that return interaction results immediately.
 * Renders via the engine's Renderer2D (NanoVG). Input is read from
 * the engine's Input class and suppressed while the UI is hovered.
 */
class UIContext
{
    private Renderer2DInterface $renderer;
    private InputInterface $input;
    private UIStyle $style;

    /** Layout cursor — auto-advances after each widget */
    private float $cursorX = 0.0;
    private float $cursorY = 0.0;

    /** Current layout region width */
    private float $regionWidth = 300.0;

    /** Current flow direction: 'vertical' or 'horizontal' */
    private string $flow = 'vertical';

    /** @var list<array{x: float, y: float, w: float, flow: string, hovered: bool, hoveredWidget: string}> Layout stack for nested begin/end pairs */
    private array $layoutStack = [];

    /** Tracks the active (pressed) widget ID */
    private string $activeWidget = '';

    /** Gap between a wrapButton() key cap and what follows it. */
    private const float KEYCAP_GAP = 12.0;

    /** Area reserved by $leadingSpace in the last wrapButton(). */
    private Rect $lastLeading;

    /**
     * True once the left mouse button has been fully released since the last
     * slider activation. Prevents synthetic GLFW_PRESS events (macOS fullscreen)
     * from activating sliders — synthetic presses never produce a RELEASE event,
     * so this guard is never satisfied by them.
     */
    private bool $mouseReleasedSinceLastSlider = true;

    /** Text input state for the currently focused text field */
    private string $focusedTextField = '';
    private string $textFieldBuffer = '';
    private int $textFieldCursor = 0;

    /** ID of the currently open dropdown (empty = none open) */
    private string $openDropdown = '';

    /** @var array<string, int> Scroll offset per dropdown ID */
    private array $dropdownScrollOffset = [];

    /** @var array<string, float> Vertical scroll offset (pixels) per scroll-region ID */
    private array $scrollRegionOffset = [];

    /**
     * @var list<array{x: float, y: float, w: float, h: float, content: float, offset: float}>
     * Active beginScroll regions (for nested support + scrollbar draw on endScroll).
     */
    private array $scrollStack = [];

    /** Whether any widget was hovered this frame */
    private bool $anyHovered = false;

    /** Id of the leaf widget hovered this frame ('' = none). Tracked alongside
     *  $anyHovered through begin/end region nesting so callers can detect
     *  per-widget hover-enter (e.g. for hover SFX). */
    private string $hoveredWidget = '';

    /** @var list<\Closure> Deferred overlay draws (dropdowns) — rendered last for correct z-order */
    private array $deferredOverlays = [];

    /** Viewport offset for letterboxing — mouse coords are adjusted by this */
    private float $viewportOffsetX = 0.0;
    private float $viewportOffsetY = 0.0;

    /**
     * Design-space translation applied to the content region while it is drawn
     * through a renderer transform (e.g. a panel body shifted right past a
     * navigation rail, via a renderer transform), the draws move but the cursor
     * doesn't — so hit-testing subtracts this to bring the cursor into the same
     * translated space. Does NOT affect drawing (the renderer transform already
     * moves the draws). Zero = no region offset (default).
     */
    private float $contentOffsetX = 0.0;
    private float $contentOffsetY = 0.0;

    /**
     * Virtual/design viewport height, in the same coordinate space widgets are
     * laid out in (0 = unknown). Lets a dropdown flip its option list upward when
     * opening downward would run past the bottom of the screen.
     */
    private float $viewportHeight = 0.0;

    /** Content scale for resolution-independent rendering (e.g. 2.0 = game renders at 2x) */
    private float $contentScale = 1.0;

    /**
     * Right-to-left mirroring: when set to the design width, hover/hit-testing
     * mirrors the cursor's X (designWidth - x) so widget hit-tests line up with a
     * consumer that draws its UI mirrored for an RTL locale. null = LTR (default,
     * no effect). Generic RTL support — the consuming app toggles it per locale.
     */
    public static ?float $rtlMirrorWidth = null;

    /**
     * When false, hover detection short-circuits to false. Used by callers
     * that want to render an underlying scene visually while a modal overlay
     * (e.g. a confirm dialog) absorbs input — set to false before drawing
     * the blocked layer, back to true before the modal itself draws.
     *
     * Affects hover for every widget. Since click triggers require hover,
     * clicks are blocked too. Visual states (button hover color, slider
     * grab) stay in their resting appearance.
     */
    private bool $interactive = true;

    /** Clipboard for copy / cut / paste in text areas. */
    private ClipboardInterface $clipboard;

    /** Maps shortcut letters to keys (Ctrl+Z follows the keycap, not the US position). */
    private KeyboardLayoutInterface $keyboardLayout;

    /** macOS key bindings (Command shortcuts, Option word jumps); null = the platform's. */
    private ?bool $macKeys = null;

    /**
     * Editing state (text, caret, selection, undo) per text area. Kept while
     * focus is elsewhere, so returning to a field with the same text keeps
     * its undo history.
     *
     * @var array<string, TextEditModel>
     */
    private array $editModels = [];

    /** Text area whose mouse-press selection is being dragged ('' = none). */
    private string $textAreaDrag = '';

    /** @var array{id: string, at: int, x: float, y: float} last press in a text area (double-click detection) */
    private array $lastTextAreaPress = ['id' => '', 'at' => 0, 'x' => 0.0, 'y' => 0.0];

    /** Two presses closer than this (ns) and {@see DOUBLE_CLICK_DISTANCE} px apart select a word. */
    private const int DOUBLE_CLICK_NS = 400_000_000;
    private const float DOUBLE_CLICK_DISTANCE = 4.0;

    /**
     * The clipboard and keyboard layout default to the system ones when the
     * input is php-vio's ({@see VioInput}), else to an in-process clipboard
     * and the US layout - so tests and headless runs never touch the user's
     * clipboard.
     */
    public function __construct(
        Renderer2DInterface $renderer,
        InputInterface $input,
        ?UIStyle $style = null,
        ?ClipboardInterface $clipboard = null,
        ?KeyboardLayoutInterface $keyboardLayout = null,
    ) {
        $this->renderer = $renderer;
        $this->input = $input;
        $this->style = $style ?? UIStyle::dark();
        $system = $input instanceof VioInput;
        $this->clipboard = $clipboard ?? ($system ? new VioClipboard() : new MemoryClipboard());
        $this->keyboardLayout = $keyboardLayout ?? ($system ? new VioKeyboardLayout() : new UsKeyboardLayout());
        $this->lastLeading = new Rect();
    }

    public function getClipboard(): ClipboardInterface
    {
        return $this->clipboard;
    }

    public function setClipboard(ClipboardInterface $clipboard): void
    {
        $this->clipboard = $clipboard;
    }

    public function setKeyboardLayout(KeyboardLayoutInterface $layout): void
    {
        $this->keyboardLayout = $layout;
    }

    /** Force macOS (true) or PC (false) key bindings in text areas; null = the running platform's. */
    public function useMacKeys(?bool $mac): void
    {
        $this->macKeys = $mac;
    }

    public function getInput(): InputInterface
    {
        return $this->input;
    }

    public function getStyle(): UIStyle
    {
        return $this->style;
    }

    public function setStyle(UIStyle $style): void
    {
        $this->style = $style;
    }

    /**
     * Begin a UI layout region.
     *
     * @param string $flow 'vertical' (default) or 'horizontal'
     */
    public function begin(float $x = 10.0, float $y = 10.0, float $width = 300.0, string $flow = 'vertical'): void
    {
        // Push current state onto stack for nesting
        $this->layoutStack[] = [
            'x' => $this->cursorX,
            'y' => $this->cursorY,
            'w' => $this->regionWidth,
            'flow' => $this->flow,
            'hovered' => $this->anyHovered,
            'hoveredWidget' => $this->hoveredWidget,
        ];

        $this->cursorX = $x;
        $this->cursorY = $y;
        $this->regionWidth = $width;
        $this->flow = $flow;
        $this->anyHovered = false;
        $this->hoveredWidget = '';
        $this->renderer->setFont($this->style->fontName);
        $this->renderer->setTextAlign(\PHPolygon\Rendering\TextAlign::LEFT | \PHPolygon\Rendering\TextAlign::TOP);
    }

    /**
     * End a UI layout region. Restores parent layout state.
     * When the outermost region ends, deferred overlays (dropdowns) are drawn
     * so they always appear on top of all other widgets.
     */
    public function end(): void
    {
        $wasHovered = $this->anyHovered;
        $childHoveredWidget = $this->hoveredWidget;

        if (count($this->layoutStack) > 0) {
            $prev = array_pop($this->layoutStack);
            $this->cursorX = $prev['x'];
            $this->cursorY = $prev['y'];
            $this->regionWidth = $prev['w'];
            $this->flow = $prev['flow'];
            // Propagate hover state to parent: a hovered child wins, otherwise
            // keep whatever the parent region already had hovered.
            $this->anyHovered = $prev['hovered'] || $wasHovered;
            $this->hoveredWidget = $wasHovered ? $childHoveredWidget : $prev['hoveredWidget'];
        }

    }

    /**
     * Begin a vertically scrolling region of fixed size at (x, y, width, height).
     *
     * Clips drawing to the region (pushScissor) and shifts all content drawn
     * before the matching {@see endScroll()} up by a per-id scroll offset, so a
     * tall column of immediate-mode widgets becomes a scrollable list. The
     * offset is advanced/retreated by the mouse wheel while the cursor is over
     * the region and clamped to [0, max(0, contentHeight - height)].
     *
     * Internally this opens a layout region (like {@see begin()}) at the shifted
     * y, so widgets flow exactly as in a normal column — call the usual widget
     * methods between begin/endScroll. A scrollbar is drawn on the right edge by
     * {@see endScroll()} when the content is taller than the region.
     *
     * Dropdowns work inside the region: their expanded overlay is deferred to
     * {@see flushOverlays()} (drawn after, on top, unclipped) and is captured at
     * its already-scrolled screen position, so it anchors correctly to the field.
     *
     * @param float $contentHeight Estimated total height of the content; use
     *                              {@see estimateContentHeight()} or sum control
     *                              heights manually (controls * rowHeight() + spacings).
     */
    public function beginScroll(string $id, float $x, float $y, float $width, float $height, float $contentHeight): void
    {
        $maxOffset = max(0.0, $contentHeight - $height);
        $offset = $this->scrollRegionOffset[$id] ?? 0.0;

        // Mouse-wheel scroll while hovering the region.
        $regionHovered = false;
        if ($maxOffset > 0.0) {
            $regionRect = new Rect($x, $y, $width, $height);
            if ($this->isHovered($regionRect)) {
                $regionHovered = true;
                $scrollY = $this->input->getScrollY();
                if (abs($scrollY) > 0.001) {
                    // Wheel up (positive) scrolls content up toward the top.
                    $offset -= $scrollY * $this->style->fontSize * 2.0;
                }
            }
        }
        $offset = max(0.0, min($offset, $maxOffset));
        $this->scrollRegionOffset[$id] = $offset;

        $this->scrollStack[] = [
            'x' => $x, 'y' => $y, 'w' => $width, 'h' => $height,
            'content' => $contentHeight, 'offset' => $offset,
        ];

        // Clip in physical (screen) coordinates so it matches the renderer's
        // 2D space; the layout/cursor work in virtual coordinates.
        $this->renderer->pushScissor(
            $x * $this->contentScale + $this->viewportOffsetX,
            $y * $this->contentScale + $this->viewportOffsetY,
            $width * $this->contentScale,
            $height * $this->contentScale,
        );

        // Open a layout region shifted up by the scroll offset so content flows
        // from (y - offset). begin() pushes the current cursor state for us and
        // resets anyHovered, so flag the region hover after it.
        $this->begin($x, $y - $offset, $width);
        if ($regionHovered) {
            $this->anyHovered = true;
        }
    }

    /**
     * End the current {@see beginScroll()} region: draw the scrollbar (when the
     * content overflows), close the layout region, and pop the scissor clip.
     */
    public function endScroll(): void
    {
        $region = array_pop($this->scrollStack);
        $this->end();

        if ($region !== null) {
            $contentH = $region['content'];
            $viewH = $region['h'];
            $maxOffset = max(0.0, $contentH - $viewH);
            if ($maxOffset > 0.0 && $contentH > 0.0) {
                $s = $this->style;
                $barW = 4.0;
                $barX = $region['x'] + $region['w'] - $barW - 2.0;
                $thumbH = max(20.0, $viewH * ($viewH / $contentH));
                $t = $region['offset'] / $maxOffset;
                $thumbY = $region['y'] + ($viewH - $thumbH) * $t;
                $this->renderer->drawRoundedRect($barX, $thumbY, $barW, $thumbH, 2.0, $s->textColor->withAlpha(0.35));
            }
        }

        $this->renderer->popScissor();
    }

    /**
     * Convenience estimator for {@see beginScroll()}'s $contentHeight: a stack
     * of $rows uniform control rows plus the inter-row item spacing. Mixed
     * layouts (sliders are taller than labels) should sum control heights
     * directly instead — see {@see rowHeight()}.
     */
    public function estimateContentHeight(int $rows, float $extraSpacing = 0.0): float
    {
        return $rows * $this->rowHeight() + max(0, $rows - 1) * $this->style->itemSpacing + $extraSpacing;
    }

    /**
     * Height of one standard single-line control row (label / dropdown / button /
     * checkbox), excluding inter-row item spacing.
     */
    public function rowHeight(): float
    {
        return $this->style->fontSize + $this->style->padding * 2;
    }

    // ── Widgets ──────────────────────────────────────────────────

    /**
     * Static text label.
     */
    public function label(string $text, ?Color $color = null): void
    {
        $color ??= $this->style->textColor;
        $h = $this->style->fontSize + $this->style->padding * 2;
        $w = mb_strlen($text) * $this->style->fontSize * 0.55 + $this->style->padding * 2;

        $this->renderer->drawText(
            $text,
            $this->cursorX + $this->style->padding,
            $this->cursorY + $this->style->padding,
            $this->style->fontSize,
            $color,
        );

        $this->advance($this->flow === 'horizontal' ? $w : $h);
    }

    /**
     * Like {@see label()} but word-wraps to the current region width and grows
     * to as many lines as the text needs — for descriptions, output and hints
     * that would otherwise overflow on a single line.
     */
    public function labelWrapped(string $text, ?Color $color = null): void
    {
        $color ??= $this->style->textColor;
        $size = $this->style->fontSize;
        $pad = $this->style->padding;
        $breakWidth = max(1.0, $this->regionWidth - $pad * 2);

        $metrics = $this->renderer->measureTextBox($text, $breakWidth, $size);
        $this->renderer->drawTextBox(
            $text,
            $this->cursorX + $pad,
            $this->cursorY + $pad,
            $breakWidth,
            $size,
            $color,
        );
        $this->advance($metrics->height + $pad * 2);
    }

    /**
     * Clickable button. Returns true on the frame it was clicked.
     *
     * @param float $width    Override width (0 = auto: regionWidth in vertical, text-fit in horizontal)
     * @param bool  $disabled When true, button is grayed out and not clickable
     */
    public function button(string $id, string $label, float $width = 0.0, bool $disabled = false): bool
    {
        $s = $this->style;
        $h = $s->fontSize + $s->padding * 2;

        // Calculate button width — auto-sized text gets horizontal padding
        if ($width > 0.0) {
            $w = $width;
        } elseif ($this->flow === 'horizontal') {
            $w = mb_strlen($label) * $s->fontSize * 0.55 + $s->buttonPaddingH * 2;
        } else {
            $w = $this->regionWidth;
        }

        // Clamp to region width to prevent overflow
        $w = min($w, $this->regionWidth);

        $rect = new Rect($this->cursorX, $this->cursorY, $w, $h);
        [$clicked, $textColor] = $this->buttonBody($id, $rect, $disabled);
        $this->renderer->drawTextCentered($label, $rect->x + $w * 0.5, $rect->y + $h * 0.5, $s->fontSize, $textColor);

        $this->advance($this->flow === 'horizontal' ? $w : $h);
        return $clicked;
    }

    // ── Wrapping buttons ─────────────────────────────────────────

    /**
     * A button whose label wraps instead of being cut, and whose height follows
     * the number of lines. Click behaviour and colours are {@see button()}'s.
     *
     * $label is either a string (wrapped here into at most $maxLines lines,
     * shrinking down to $minFontSize first) or lines already fitted for a whole
     * row ({@see fitButtonLabels()} / {@see layoutButtonRow()}) together with
     * their $fontSize - pass the row's $height too, so all buttons of the row
     * are equally tall.
     *
     * $keycap draws a small key cap with that text (e.g. the shortcut digit)
     * at the left edge, set apart from the label so it is never read as part
     * of it. $leadingSpace reserves room after the key cap for something the
     * caller draws itself (an icon); {@see lastButtonLeading()} returns that
     * area. $align is TextAlign::CENTER (default) or TextAlign::LEFT.
     * $textColor overrides the label colour (the key cap follows it).
     *
     * @param string|list<string> $label
     */
    public function wrapButton(
        string $id,
        string|array $label,
        float $width = 0.0,
        bool $disabled = false,
        ?float $height = null,
        ?float $fontSize = null,
        string $keycap = '',
        float $leadingSpace = 0.0,
        int $align = TextAlign::CENTER,
        ?Color $textColor = null,
        int $maxLines = 2,
        ?float $minFontSize = null,
    ): bool {
        $s = $this->style;
        $w = min($width > 0.0 ? $width : $this->regionWidth, $this->regionWidth);
        if (is_string($label)) {
            $fit = $this->fitButtonLabels([$label], $w, $maxLines, $minFontSize, $keycap !== '', $leadingSpace);
            $lines = $fit->label(0);
            $fontSize ??= $fit->fontSize;
        } else {
            $lines = $label;
            $fontSize ??= $s->fontSize;
        }
        $h = $height ?? $this->wrapButtonHeight(count($lines), $fontSize);

        $rect = new Rect($this->cursorX, $this->cursorY, $w, $h);
        [$clicked, $color] = $this->buttonBody($id, $rect, $disabled);
        if ($textColor !== null) {
            $color = $textColor;
        }

        $this->renderer->setFont($s->fontName);
        $cy = $rect->y + $h * 0.5;
        $left = $rect->x + $s->buttonPaddingH;
        if ($keycap !== '') {
            $k = $this->keycapSize();
            $capColor = new Color($color->r, $color->g, $color->b, $color->a * 0.7);
            $this->renderer->drawRoundedRectOutline(round($left), round($cy - $k * 0.5), $k, $k, max(2.0, $s->borderRadius), $capColor, 1.5);
            $this->renderer->drawTextCentered($keycap, round($left + $k * 0.5), round($cy), round($s->fontSize * 0.7), $capColor);
            $left += $k + self::KEYCAP_GAP;
        }
        $this->lastLeading = new Rect($left, $rect->y, $leadingSpace, $h);
        $left += $leadingSpace;
        $right = $rect->x + $w - $s->buttonPaddingH;

        $lh = $this->wrapLineHeight($fontSize);
        $top = $cy - count($lines) * $lh * 0.5;
        foreach ($lines as $i => $line) {
            $mid = round($top + ($i + 0.5) * $lh);
            $cx = ($align & TextAlign::LEFT) !== 0
                ? $left + $this->renderer->measureText($line, $fontSize)->width * 0.5
                : ($left + $right) * 0.5;
            $this->renderer->drawTextCentered($line, round($cx), $mid, $fontSize, $color);
        }

        $this->advance($this->flow === 'horizontal' ? $w : $h);
        return $clicked;
    }

    /**
     * Wrap the labels of a row of equally wide buttons ($width each) with one
     * shared font size: at most $maxLines lines each, shrinking from the style
     * size down to $minFontSize (default: no shrinking) before a word would be
     * split. Pass the same $keycap / $leadingSpace as to {@see wrapButton()}.
     *
     * @param list<string> $labels
     */
    public function fitButtonLabels(
        array $labels,
        float $width,
        int $maxLines = 2,
        ?float $minFontSize = null,
        bool $keycap = false,
        float $leadingSpace = 0.0,
    ): LabelFit {
        $s = $this->style;
        $inner = $width - 2 * $s->buttonPaddingH - $leadingSpace
            - ($keycap ? $this->keycapSize() + self::KEYCAP_GAP : 0.0);
        $this->renderer->setFont($s->fontName);

        return LabelFit::fit($labels, max(1.0, $inner), $s->fontSize, $minFontSize ?? $s->fontSize, $maxLines, $this->measurer());
    }

    /**
     * A row of buttons across $totalWidth, each as wide as its label, the
     * spare room shared out evenly. When the labels do not fit on one line at
     * the style size, the font shrinks towards $minFontSize; when they still do
     * not fit, the widest labels go to two lines one by one until the row
     * fits, and as a last resort widths shrink in proportion. Nothing is cut.
     *
     * @param list<string> $labels
     */
    public function layoutButtonRow(array $labels, float $totalWidth, float $gap = 12.0, ?float $minFontSize = null): ButtonRow
    {
        $s = $this->style;
        $n = max(1, count($labels));
        $pad = $s->buttonPaddingH;
        $room = $totalWidth - ($n - 1) * $gap;
        $measure = $this->measurer();
        $this->renderer->setFont($s->fontName);
        $natural = static function (float $size) use ($labels, $measure, $pad): array {
            return array_map(static fn(string $t): float => ceil($measure($t, $size)) + 2 * $pad, $labels);
        };

        $size = $s->fontSize;
        $min = min($size, $minFontSize ?? $size);
        $widths = null;
        $lines = array_map(static fn(string $t): array => [$t], $labels);
        for ($try = $size; $try >= $min; $try -= 1.0) {
            $nat = $natural($try);
            if (array_sum($nat) <= $room) {
                $extra = ($room - array_sum($nat)) / $n;
                $widths = array_map(static fn(float $w): float => floor($w + $extra), $nat);
                $size = $try;
                break;
            }
        }

        if ($widths === null) {
            $size = $min;
            $at = static fn(string $t): float => $measure($t, $size);
            $nat = $natural($size);
            $need = $nat;
            $split = [];
            while (array_sum($need) > $room && count($split) < $n) {
                $widest = null;
                foreach ($need as $i => $w) {
                    if (!isset($split[$i]) && ($widest === null || $w > $need[$widest])) {
                        $widest = $i;
                    }
                }
                if ($widest === null) {
                    break;
                }
                $split[$widest] = true;
                // The narrowest width at which two lines are enough.
                $two = [$labels[$widest]];
                for ($f = 0.5; $f < 1.0; $f += 0.05) {
                    $two = TextWrap::lines($labels[$widest], ($nat[$widest] - 2 * $pad) * $f, $at);
                    if (count($two) <= 2) {
                        break;
                    }
                }
                $twoW = 0.0;
                foreach ($two as $line) {
                    $twoW = max($twoW, ceil($at($line)));
                }
                $need[$widest] = min($nat[$widest], $twoW + 2 * $pad);
            }
            $scale = min(1.0, $room / max(1.0, array_sum($need)));
            $extra = max(0.0, ($room - array_sum($need)) / $n);
            $widths = array_map(static fn(float $w): float => floor($w * $scale + $extra), $need);
            $lines = [];
            foreach ($labels as $i => $label) {
                $lines[] = TextWrap::linesWithin($label, max(1.0, $widths[$i] - 2 * $pad), $at);
            }
        }

        $xs = [];
        $x = 0.0;
        foreach ($widths as $w) {
            $xs[] = $x;
            $x += $w + $gap;
        }
        $count = 1;
        foreach ($lines as $l) {
            $count = max($count, count($l));
        }

        return new ButtonRow($xs, array_values($widths), $lines, $size, $this->wrapButtonHeight($count, $size));
    }

    /**
     * Height of a {@see wrapButton()} with $lines lines at $fontSize: one line
     * is exactly {@see rowHeight()}, so a row of plain and wrapping buttons
     * lines up; every further line adds {@see wrapLineHeight()}.
     */
    public function wrapButtonHeight(int $lines, float $fontSize): float
    {
        return $this->rowHeight() + (max(1, $lines) - 1) * $this->wrapLineHeight($fontSize);
    }

    /** Line pitch of wrapped widget text ({@see UIStyle::$lineHeight}). */
    public function wrapLineHeight(float $fontSize): float
    {
        return round($fontSize * $this->style->lineHeight);
    }

    /** Edge length of the key cap {@see wrapButton()} draws for $keycap. */
    public function keycapSize(): float
    {
        return round($this->style->fontSize * 1.15);
    }

    /** The area reserved by $leadingSpace in the last {@see wrapButton()}. */
    public function lastButtonLeading(): Rect
    {
        return $this->lastLeading;
    }

    /** @return callable(string, float): float */
    private function measurer(): callable
    {
        $r = $this->renderer;

        return static fn(string $t, float $size): float => $r->measureText($t, $size)->width;
    }

    /**
     * Shared button behaviour: hover, press-then-release click, background.
     *
     * @return array{0: bool, 1: Color} clicked, text colour for the state
     */
    private function buttonBody(string $id, Rect $rect, bool $disabled): array
    {
        $s = $this->style;
        $hovered = !$disabled && $this->isHovered($rect);
        $clicked = false;
        $pressing = false;

        if ($hovered) {
            $this->anyHovered = true; $this->hoveredWidget = $id;

            // Claim the widget only on the PRESS EDGE (not every down-frame), so
            // the press origin is recorded on exactly one widget.
            if ($this->input->isMouseButtonPressed(0)) {
                $this->activeWidget = $id;
            }
            // Fire only if the press began on THIS widget and we're still hovered
            // at release — the correct IMGUI press-then-release-on-same-widget
            // model. Prevents ghost clicks where a press starts on another widget
            // and the cursor is over this one at the release edge (issue #181).
            if ($this->input->isMouseButtonReleased(0) && $this->activeWidget === $id) {
                $clicked = true;
                $this->activeWidget = '';
            }
            $pressing = $this->activeWidget === $id && $this->input->isMouseButtonDown(0);
        } else {
            if ($this->activeWidget === $id && !$this->input->isMouseButtonDown(0)) {
                $this->activeWidget = '';
            }
        }

        // Visual state
        if ($disabled) {
            $bg = $s->disabledColor;
            $textColor = $s->disabledTextColor;
        } else {
            $bg = $pressing ? $s->activeColor
                : ($hovered ? $s->backgroundColor : $s->hoverColor);
            $textColor = $s->textColor;
        }

        $this->renderer->drawRoundedRect($rect->x, $rect->y, $rect->width, $rect->height, $s->borderRadius, $bg);

        return [$clicked, $textColor];
    }

    /**
     * Checkbox toggle. Returns the new value.
     */
    public function checkbox(string $id, string $label, bool $value): bool
    {
        $s = $this->style;
        $boxSize = $s->fontSize;
        $h = $boxSize + $s->padding * 2;
        $fullRect = new Rect($this->cursorX, $this->cursorY, $this->regionWidth, $h);

        $hovered = $this->isHovered($fullRect);
        if ($hovered) {
            $this->anyHovered = true; $this->hoveredWidget = $id;
            if ($this->input->isMouseButtonReleased(0)) {
                $value = !$value;
            }
        }

        $boxX = $this->cursorX + $s->padding;
        $boxY = $this->cursorY + $s->padding;

        $this->renderer->drawRoundedRect($boxX, $boxY, $boxSize, $boxSize, $s->borderRadius * 0.5, $s->backgroundColor);
        $this->renderer->drawRectOutline($boxX, $boxY, $boxSize, $boxSize, $s->borderColor, $s->borderWidth);

        if ($value) {
            $inner = $boxSize * 0.3;
            $this->renderer->drawRoundedRect(
                $boxX + $inner,
                $boxY + $inner,
                $boxSize - $inner * 2,
                $boxSize - $inner * 2,
                2.0,
                $s->accentColor,
            );
        }

        $this->renderer->drawText(
            $label,
            $boxX + $boxSize + $s->padding,
            $boxY,
            $s->fontSize,
            $s->textColor,
        );

        $this->advance($h);
        return $value;
    }

    /**
     * Horizontal slider. Returns the new value.
     */
    public function slider(string $id, string $label, float $value, float $min = 0.0, float $max = 1.0): float
    {
        $s = $this->style;
        $labelH = $s->fontSize + $s->padding;
        $barH = $s->fontSize * 0.5;
        $totalH = $labelH + $barH + $s->padding * 2;

        // Label + value
        $this->renderer->drawText(
            sprintf('%s: %.2f', $label, $value),
            $this->cursorX + $s->padding,
            $this->cursorY + $s->padding,
            $s->fontSize,
            $s->textColor,
        );

        $barX = $this->cursorX + $s->padding;
        $barY = $this->cursorY + $labelH + $s->padding;
        $barW = $this->regionWidth - $s->padding * 2;
        $barRect = new Rect($barX, $barY, $barW, $barH);

        $hovered = $this->isHovered($barRect);
        if ($hovered) {
            $this->anyHovered = true; $this->hoveredWidget = $id;
        }

        if (!$this->input->isMouseButtonDown(0)) {
            $this->mouseReleasedSinceLastSlider = true;
        }

        if ($hovered && $this->input->isMouseButtonPressed(0) && $this->mouseReleasedSinceLastSlider) {
            $this->activeWidget = $id;
            $this->mouseReleasedSinceLastSlider = false;
        }

        if ($this->activeWidget === $id) {
            if (!$this->input->isMouseButtonDown(0)) {
                // Released — deactivate and allow next real press
                $this->activeWidget = '';
                $this->mouseReleasedSinceLastSlider = true;
            } elseif ($hovered) {
                // Only track value while cursor is over the bar.
                // If cursor leaves (e.g. phantom stuck press), slider deactivates next branch.
                $mouseX = ($this->input->getMouseX() - $this->viewportOffsetX) / $this->contentScale - $this->contentOffsetX;
                $t = max(0.0, min(1.0, ($mouseX - $barX) / $barW));
                $value = $min + ($max - $min) * $t;
            } else {
                // Button held but cursor left bar — treat as phantom/accidental,
                // deactivate without allowing re-activation until real release arrives.
                $this->activeWidget = '';
                $this->mouseReleasedSinceLastSlider = false;
            }
        }

        // Track background
        $this->renderer->drawRoundedRect($barX, $barY, $barW, $barH, $barH * 0.5, $s->backgroundColor);

        // Filled portion
        $t = ($max > $min) ? ($value - $min) / ($max - $min) : 0.0;
        $fillW = $barW * $t;
        if ($fillW > 1.0) {
            $this->renderer->drawRoundedRect($barX, $barY, $fillW, $barH, $barH * 0.5, $s->accentColor);
        }

        // Thumb
        $thumbR = $barH;
        $thumbX = $barX + $fillW;
        $thumbY = $barY + $barH * 0.5;
        $this->renderer->drawCircle($thumbX, $thumbY, $thumbR, $s->accentColor);

        $this->advance($totalH);
        return $value;
    }

    /**
     * Single-line text field. Returns the current text content.
     */
    public function textField(string $id, string $label, string $value): string
    {
        $s = $this->style;
        $hasLabel = $label !== '';
        $labelH = $hasLabel ? $s->fontSize + $s->padding : 0.0;
        $fieldH = $s->fontSize + $s->padding * 2;
        $totalH = $labelH + $fieldH + $s->padding;

        // Label (only if non-empty)
        if ($hasLabel) {
            $this->renderer->drawText(
                $label,
                $this->cursorX + $s->padding,
                $this->cursorY + $s->padding,
                $s->fontSize,
                $s->textColor,
            );
        }

        $fieldX = $this->cursorX + $s->padding;
        $fieldY = $this->cursorY + ($hasLabel ? $labelH + $s->padding : 0.0);
        $fieldW = $this->regionWidth - $s->padding * 2;
        $fieldRect = new Rect($fieldX, $fieldY, $fieldW, $fieldH);

        $hovered = $this->isHovered($fieldRect);
        if ($hovered) {
            $this->anyHovered = true; $this->hoveredWidget = $id;
        }

        $focused = $this->focusedTextField === $id;
        // Click to focus
        if ($hovered && $this->input->isMouseButtonReleased(0)) {
            $this->setTextFieldFocus($id);
            $this->textFieldBuffer = $value;
            $this->textFieldCursor = mb_strlen($value);
            $focused = true;
        } elseif (!$hovered && $this->input->isMouseButtonReleased(0) && $focused) {
            // Click outside → unfocus
            $this->setTextFieldFocus('');
            $focused = false;
        }

        // Process typed characters
        if ($focused) {
            foreach ($this->input->getCharsTyped() as $char) {
                // Insert at cursor position
                $this->textFieldBuffer = mb_substr($this->textFieldBuffer, 0, $this->textFieldCursor)
                    . $char
                    . mb_substr($this->textFieldBuffer, $this->textFieldCursor);
                $this->textFieldCursor++;
            }
            // Backspace (GLFW_KEY_BACKSPACE = 259)
            // isKeyTyped statt isKeyPressed: eine gehaltene Ruecktaste soll
            // loeschen, solange sie gehalten wird, und zwar mit der
            // Wiederholrate des Systems.
            if ($this->input->isKeyTyped(259) && $this->textFieldCursor > 0) {
                $this->textFieldBuffer = mb_substr($this->textFieldBuffer, 0, $this->textFieldCursor - 1)
                    . mb_substr($this->textFieldBuffer, $this->textFieldCursor);
                $this->textFieldCursor--;
            }

            // On-screen-keyboard backspaces (iOS): no physical key edge, so the
            // soft keyboard's delete key is delivered as a per-frame count.
            for ($bs = $this->input->getBackspaceCount(); $bs > 0 && $this->textFieldCursor > 0; $bs--) {
                $this->textFieldBuffer = mb_substr($this->textFieldBuffer, 0, $this->textFieldCursor - 1)
                    . mb_substr($this->textFieldBuffer, $this->textFieldCursor);
                $this->textFieldCursor--;
            }

            // Delete (GLFW_KEY_DELETE = 261)
            if ($this->input->isKeyTyped(261) && $this->textFieldCursor < mb_strlen($this->textFieldBuffer)) {
                $this->textFieldBuffer = mb_substr($this->textFieldBuffer, 0, $this->textFieldCursor)
                    . mb_substr($this->textFieldBuffer, $this->textFieldCursor + 1);
            }

            // Arrow keys (LEFT = 263, RIGHT = 262)
            if ($this->input->isKeyTyped(263) && $this->textFieldCursor > 0) {
                $this->textFieldCursor--;
            }
            if ($this->input->isKeyTyped(262) && $this->textFieldCursor < mb_strlen($this->textFieldBuffer)) {
                $this->textFieldCursor++;
            }

            $value = $this->textFieldBuffer;
        }

        // Sideways scroll: text wider than the field follows the caret and is
        // cut at glyph boundaries (like textArea – no scissor needed).
        $innerW = max(1.0, $fieldW - $s->padding * 2);
        $scrollX = $this->textFieldScroll[$id] ?? 0.0;
        $caretPx = 0.0;
        if ($focused) {
            $caretPx = $this->renderer->measureText(mb_substr($value, 0, $this->textFieldCursor), $s->fontSize)->width;
            if ($caretPx < $scrollX) {
                $scrollX = max(0.0, $caretPx - $innerW * 0.25);
            } elseif ($caretPx > $scrollX + $innerW - self::TEXT_AREA_CARET_ROOM) {
                $scrollX = $caretPx - $innerW + self::TEXT_AREA_CARET_ROOM;
            }
        } else {
            $scrollX = 0.0;
        }
        $this->textFieldScroll[$id] = $scrollX;

        // Draw field background
        $borderCol = $focused ? $s->accentColor : $s->borderColor;
        $this->renderer->drawRoundedRect($fieldX, $fieldY, $fieldW, $fieldH, $s->borderRadius, $s->backgroundColor);
        $this->renderer->drawRectOutline($fieldX, $fieldY, $fieldW, $fieldH, $borderCol, $focused ? 2.0 : $s->borderWidth);
        // Draw text content
        if ($value !== '') {
            [$slice, $sliceX] = $this->visibleSlice($value, $scrollX, $innerW, $s->fontSize);
            $this->renderer->drawText(
                $slice,
                $fieldX + $s->padding + $sliceX,
                $fieldY + $s->padding,
                $s->fontSize,
                $s->textColor,
            );
        }

        // Blinking cursor when focused
        if ($focused) {
            $blinkOn = fmod(\PHPolygon\Runtime\Clock::now() / 1_000_000_000, 1.0) < 0.5;
            if ($blinkOn) {
                $cursorX = $fieldX + $s->padding + $caretPx - $scrollX;
                $cursorY1 = $fieldY + $s->padding;
                $cursorY2 = $cursorY1 + $s->fontSize;
                $this->renderer->drawLine(
                    new Vec2($cursorX, $cursorY1),
                    new Vec2($cursorX, $cursorY2),
                    $s->accentColor,
                    1.5,
                );
            }
        }

        $this->advance($totalH);
        return $value;
    }

    /**
     * Multi-line text input (code editor). Like {@see textField()} but renders
     * and edits across several lines: Enter inserts a newline, backspace can
     * merge lines, and the caret tracks its line + column. $rows sets the
     * visible height. Call from the render phase (reads keys once per frame).
     *
     * Text longer or wider than the field scrolls instead of spilling out:
     * only the visible window of rows is drawn, and lines are cut at glyph
     * boundaries on both edges (no scissor needed, so every renderer clips the
     * same way). The window follows the caret whenever the caret or the text
     * changes; the mouse wheel scrolls rows (Shift + wheel or a horizontal
     * wheel scrolls sideways) and stays where it was put until the next edit.
     * A click places the caret at the clicked row and column.
     *
     * $underlay is called after the field background and before the text, with
     * the frame's {@see TextAreaView} – for highlight bands under single lines.
     * The same view is available afterwards from {@see textAreaView()}.
     *
     * Editing ({@see TextEditModel}): Tab / Shift+Tab indent and outdent the
     * caret's line or every selected line, Enter keeps the indentation (one
     * level more after an opener of $indentRules, the default rules when
     * null), smart Home / End, Ctrl+Home / End, Shift extends a selection
     * (also Shift+click), drag selects, a double-click selects a word, Ctrl+A
     * / C / X / V with the clipboard ({@see setClipboard()}), Ctrl+Z / Ctrl+Y
     * (Ctrl+Shift+Z) undo and redo, Ctrl+arrows / Ctrl+Backspace / Ctrl+Delete
     * work word-wise. On macOS Command takes Control's place and Option jumps
     * words. Shortcut letters follow the keyboard layout.
     *
     * @param (\Closure(TextAreaView): void)|null $underlay
     */
    public function textArea(string $id, string $value, int $rows = 8, ?\Closure $underlay = null, ?IndentRules $indentRules = null): string
    {
        $s = $this->style;
        $lineH = $s->fontSize * 1.35;
        $rows = max(1, $rows);

        $fieldX = $this->cursorX + $s->padding;
        $fieldY = $this->cursorY;
        $fieldW = $this->regionWidth - $s->padding * 2;
        $fieldH = $rows * $lineH + $s->padding * 2;
        $fieldRect = new Rect($fieldX, $fieldY, $fieldW, $fieldH);
        $textX = $fieldX + $s->padding;
        $textY = $fieldY + $s->padding;
        $innerW = max(1.0, $fieldW - $s->padding * 2);

        $scroll = $this->textAreaScroll[$id] ?? ['row' => 0, 'x' => 0.0, 'caret' => -1, 'buffer' => null];

        $hovered = $this->isHovered($fieldRect);
        if ($hovered) {
            $this->anyHovered = true; $this->hoveredWidget = $id;
        }

        $focused = $this->focusedTextField === $id;
        $edit = null;
        $mods = KeyModifiers::fromInput($this->input, $this->macKeys);
        $caretAt = function (TextEditModel $model) use ($textX, $textY, $lineH, $scroll): int {
            return $this->caretAtPoint($model->text(), $this->designMouse(), $textX - $scroll['x'], $textY, $lineH, $scroll['row']);
        };

        if ($hovered && $this->input->isMouseButtonPressed(0)) {
            // Press: focus, place the caret (Shift extends), start a drag;
            // a second press on the same spot selects the word.
            if (!$focused) {
                $this->setTextFieldFocus($id);
                $this->textFieldBuffer = $value;
                $this->textFieldCursor = mb_strlen($value);
            }
            $edit = $this->editModel($id, $indentRules);
            $index = $caretAt($edit);
            $mouse = $this->designMouse();
            $now = \PHPolygon\Runtime\Clock::now();
            $last = $this->lastTextAreaPress;
            $double = $focused && $last['id'] === $id && $now - $last['at'] < self::DOUBLE_CLICK_NS
                && abs($mouse->x - $last['x']) <= self::DOUBLE_CLICK_DISTANCE
                && abs($mouse->y - $last['y']) <= self::DOUBLE_CLICK_DISTANCE;
            if ($double) {
                $edit->selectWordAt($index);
                $this->textAreaDrag = '';
                $this->lastTextAreaPress = ['id' => '', 'at' => 0, 'x' => 0.0, 'y' => 0.0];
            } else {
                $edit->setCaret($index, extend: $focused && $mods->shift);
                $this->textAreaDrag = $id;
                $this->lastTextAreaPress = ['id' => $id, 'at' => $now, 'x' => $mouse->x, 'y' => $mouse->y];
            }
            $focused = true;
        } elseif ($focused && $this->textAreaDrag === $id && $this->input->isMouseButtonDown(0)
            && !$this->input->isMouseButtonPressed(0)) {
            // Drag: the caret follows the mouse, also outside the field
            // (above / below it the view scrolls along with the caret).
            $edit = $this->editModel($id, $indentRules);
            $edit->setCaret($caretAt($edit), extend: true);
        } elseif ($this->input->isMouseButtonReleased(0)) {
            if ($this->textAreaDrag === $id) {
                $this->textAreaDrag = ''; // end of a drag - wherever the mouse is now
            } elseif ($hovered && !$focused) {
                // A release without the press (e.g. focus handed over mid-click).
                $this->setTextFieldFocus($id);
                $this->textFieldBuffer = $value;
                $edit = $this->editModel($id, $indentRules);
                $edit->setCaret($caretAt($edit));
                $focused = true;
            } elseif (!$hovered && $focused) {
                $this->setTextFieldFocus('');
                $focused = false;
            }
        }

        if ($focused) {
            $edit ??= $this->editModel($id, $indentRules);
            $this->applyTextAreaKeys($edit, $mods, $rows);
            $this->textFieldBuffer = $edit->text();
            $this->textFieldCursor = $edit->caret();
            $value = $edit->text();
        }

        $lines = explode("\n", $value);
        $lineCount = count($lines);
        $maxRow = max(0, $lineCount - $rows);
        $contentW = 0.0;
        foreach ($lines as $line) {
            if ($line !== '') {
                $contentW = max($contentW, $this->renderer->measureText($line, $s->fontSize)->width);
            }
        }
        $maxX = max(0.0, $contentW - $innerW + self::TEXT_AREA_CARET_ROOM);

        // Wheel: rows; Shift + wheel or a horizontal wheel: sideways.
        if ($hovered) {
            $wheelY = $this->input->getScrollY();
            $wheelX = $this->input->getScrollX();
            if ($this->input->isKeyDown(340) || $this->input->isKeyDown(344)) {
                $wheelX += $wheelY;
                $wheelY = 0.0;
            }
            if (abs($wheelY) > 0.001) {
                $scroll['row'] -= (int) round($wheelY * TextAreaView::WHEEL_ROWS);
            }
            if (abs($wheelX) > 0.001) {
                $scroll['x'] -= $wheelX * $s->fontSize * 4.0;
            }
        }

        // Follow the caret whenever it or the text moved – not every frame, or
        // the wheel could never look away from it.
        $caretRow = 0;
        $caretPx = 0.0;
        if ($focused) {
            $before = explode("\n", mb_substr($value, 0, $this->textFieldCursor));
            $caretRow = count($before) - 1;
            $caretPx = $this->renderer->measureText($before[$caretRow], $s->fontSize)->width;
            if ($scroll['caret'] !== $this->textFieldCursor || $scroll['buffer'] !== $value) {
                if ($caretRow < $scroll['row']) {
                    $scroll['row'] = $caretRow;
                } elseif ($caretRow >= $scroll['row'] + $rows) {
                    $scroll['row'] = $caretRow - $rows + 1;
                }
                if ($caretPx < $scroll['x']) {
                    $scroll['x'] = max(0.0, $caretPx - $innerW * 0.25);
                } elseif ($caretPx > $scroll['x'] + $innerW - self::TEXT_AREA_CARET_ROOM) {
                    $scroll['x'] = $caretPx - $innerW + self::TEXT_AREA_CARET_ROOM;
                }
            }
            $scroll['caret'] = $this->textFieldCursor;
            $scroll['buffer'] = $value;
        } else {
            $scroll['caret'] = -1;
            $scroll['buffer'] = null;
        }

        // A requested row (revealTextAreaRow) wins over everything else.
        if (isset($this->textAreaReveal[$id])) {
            $want = $this->textAreaReveal[$id];
            unset($this->textAreaReveal[$id]);
            if ($want < $scroll['row'] || $want >= $scroll['row'] + $rows) {
                $scroll['row'] = $want - intdiv($rows, 2);
            }
        }

        $scroll['row'] = max(0, min($scroll['row'], $maxRow));
        $scroll['x'] = max(0.0, min($scroll['x'], $maxX));
        $this->textAreaScroll[$id] = $scroll;

        $view = new TextAreaView(
            id: $id,
            x: $fieldX,
            y: $fieldY,
            width: $fieldW,
            height: $fieldH,
            textX: $textX,
            textY: $textY,
            innerWidth: $innerW,
            lineHeight: $lineH,
            firstRow: $scroll['row'],
            visibleRows: $rows,
            lineCount: $lineCount,
            scrollX: $scroll['x'],
            contentWidth: $contentW,
            focused: $focused,
            caret: $edit?->caret() ?? -1,
            selectionStart: $edit?->selectionRange()[0] ?? -1,
            selectionEnd: $edit?->selectionRange()[1] ?? -1,
        );
        $this->textAreaViews[$id] = $view;

        // Background
        $borderCol = $focused ? $s->accentColor : $s->borderColor;
        $this->renderer->drawRoundedRect($fieldX, $fieldY, $fieldW, $fieldH, $s->borderRadius, $s->backgroundColor);
        $this->renderer->drawRectOutline($fieldX, $fieldY, $fieldW, $fieldH, $borderCol, $focused ? 2.0 : $s->borderWidth);

        if ($underlay !== null) {
            $underlay($view);
        }

        // Selection: one band per visible selected row, above the underlay's
        // bands and below the text.
        if ($edit !== null && $edit->hasSelection()) {
            $this->drawTextAreaSelection($edit, $view, $lines);
        }

        // Lines: only the visible window, each cut to the visible width.
        $last = min($lineCount, $scroll['row'] + $rows);
        for ($i = $scroll['row']; $i < $last; $i++) {
            $line = $lines[$i];
            if ($line === '') {
                continue;
            }
            [$slice, $sliceX] = $this->visibleSlice($line, $scroll['x'], $innerW, $s->fontSize);
            if ($slice === '') {
                continue;
            }
            $this->renderer->drawText(
                $slice,
                $textX + $sliceX,
                $view->rowY($i),
                $s->fontSize,
                $s->textColor,
            );
        }

        // Scroll indicators – only when something is hidden.
        $thumbColor = $s->textColor->withAlpha(0.35);
        if ($maxRow > 0) {
            $trackH = $fieldH - 4.0;
            $thumbH = max(12.0, $trackH * $rows / $lineCount);
            $thumbY = $fieldY + 2.0 + ($trackH - $thumbH) * ($scroll['row'] / $maxRow);
            $this->renderer->drawRoundedRect($fieldX + $fieldW - 5.0, $thumbY, 3.0, $thumbH, 1.5, $thumbColor);
        }
        if ($maxX > 0.0) {
            $trackW = $fieldW - 4.0;
            $thumbW = max(16.0, $trackW * $innerW / ($contentW + self::TEXT_AREA_CARET_ROOM));
            $thumbX = $fieldX + 2.0 + ($trackW - $thumbW) * ($scroll['x'] / $maxX);
            $this->renderer->drawRoundedRect($thumbX, $fieldY + $fieldH - 5.0, $thumbW, 3.0, 1.5, $thumbColor);
        }

        // Caret (line + column), only while it is inside the window.
        if ($focused && $view->isRowVisible($caretRow)) {
            $blinkOn = fmod(\PHPolygon\Runtime\Clock::now() / 1_000_000_000, 1.0) < 0.5;
            $caretX = $textX + $caretPx - $scroll['x'];
            if ($blinkOn && $caretX >= $textX - 0.5 && $caretX <= $textX + $innerW + 0.5) {
                $caretY = $view->rowY($caretRow);
                $this->renderer->drawLine(
                    new Vec2($caretX, $caretY),
                    new Vec2($caretX, $caretY + $s->fontSize),
                    $s->accentColor,
                    1.5,
                );
            }
        }

        $this->advance($fieldH + $s->padding);
        return $value;
    }

    /** Room kept right of the caret when the view scrolls sideways (px). */
    private const float TEXT_AREA_CARET_ROOM = 4.0;

    /**
     * The editing model of text area $id, in step with the focused-field
     * buffer: focusTextField() with other text starts it afresh (and its
     * history), with the same text only the caret moves.
     */
    private function editModel(string $id, ?IndentRules $rules): TextEditModel
    {
        $model = $this->editModels[$id] ?? null;
        if ($model === null) {
            $model = new TextEditModel($this->textFieldBuffer, $this->textFieldCursor, $rules ?? new IndentRules());
            $this->editModels[$id] = $model;
        } elseif ($model->text() !== $this->textFieldBuffer) {
            $model->setText($this->textFieldBuffer, $this->textFieldCursor);
        } elseif ($model->caret() !== $this->textFieldCursor) {
            $model->setCaret($this->textFieldCursor);
        }
        if ($rules !== null && $model->rules() !== $rules) {
            $model->setRules($rules);
        }
        return $model;
    }

    /** Keyboard and clipboard for the focused text area. */
    private function applyTextAreaKeys(TextEditModel $edit, KeyModifiers $mods, int $rows): void
    {
        $in = $this->input;
        $shift = $mods->shift;
        $word = $mods->wordJump();

        if ($mods->shortcut()) {
            // Typed letters with the shortcut modifier are commands, not text.
            $key = fn (string $letter): bool => ($k = $this->keyboardLayout->keyFor($letter)) >= 0 && $in->isKeyTyped($k);
            if ($key('a')) {
                $edit->selectAll();
            }
            if ($key('c') && $edit->hasSelection()) {
                $this->clipboard->setText($edit->selectedText());
            }
            if ($key('x') && $edit->hasSelection()) {
                $this->clipboard->setText($edit->cut());
            }
            if ($key('v')) {
                $edit->paste($this->clipboard->getText());
            }
            if ($key('z')) {
                $shift ? $edit->redo() : $edit->undo();
            }
            if ($key('y')) {
                $edit->redo();
            }
        } else {
            foreach ($in->getCharsTyped() as $char) {
                $edit->insert($char);
            }
        }

        // Enter / keypad Enter (Ctrl+Enter is left to the caller, e.g. "run").
        if (!$mods->primary() && ($in->isKeyTyped(257) || $in->isKeyTyped(335))) {
            $edit->newline();
        }
        // Tab / Shift+Tab (Alt+Tab and Ctrl+Tab belong to the system / the caller).
        if (!$mods->alt && !$mods->primary() && $in->isKeyTyped(258)) {
            $shift ? $edit->outdent() : $edit->indent();
        }
        if ($in->isKeyTyped(259)) {
            $edit->backspace(word: $word);
        }
        // On-screen-keyboard backspaces (iOS): delivered as a per-frame count.
        for ($bs = $in->getBackspaceCount(); $bs > 0; $bs--) {
            $edit->backspace();
        }
        if ($in->isKeyTyped(261)) {
            $edit->delete(word: $word);
        }

        $lineJump = $mods->mac && $mods->super;    // Command + arrows on macOS
        $docJump = $mods->mac ? $mods->super : $mods->control;
        if ($in->isKeyTyped(263)) {
            $lineJump ? $edit->home($shift) : $edit->moveLeft($shift, $word);
        }
        if ($in->isKeyTyped(262)) {
            $lineJump ? $edit->end($shift) : $edit->moveRight($shift, $word);
        }
        if ($in->isKeyTyped(265)) {
            $lineJump ? $edit->documentStart($shift) : $edit->moveUp($shift);
        }
        if ($in->isKeyTyped(264)) {
            $lineJump ? $edit->documentEnd($shift) : $edit->moveDown($shift);
        }
        if ($in->isKeyTyped(268)) {
            $docJump ? $edit->documentStart($shift) : $edit->home($shift);
        }
        if ($in->isKeyTyped(269)) {
            $docJump ? $edit->documentEnd($shift) : $edit->end($shift);
        }
        // Page Up / Page Down: one view height.
        if ($in->isKeyTyped(266)) {
            for ($i = 0; $i < $rows; $i++) {
                $edit->moveUp($shift);
            }
        }
        if ($in->isKeyTyped(267)) {
            for ($i = 0; $i < $rows; $i++) {
                $edit->moveDown($shift);
            }
        }
    }

    /**
     * Selection bands of a focused text area, clipped to its visible rows and
     * inner width. A selected line break shows as a short tail past the line.
     *
     * @param list<string> $lines
     */
    private function drawTextAreaSelection(TextEditModel $edit, TextAreaView $view, array $lines): void
    {
        $s = $this->style;
        [$start, $end] = $edit->selectionRange();
        [$firstLine, $firstCol] = $edit->lineAndColumn($start);
        [$lastLine, $lastCol] = $edit->lineAndColumn($end);
        $color = $s->accentColor->withAlpha(0.32);
        $tail = $s->fontSize * 0.3;
        $left = $view->textX;
        $right = $view->textX + $view->innerWidth;
        $padY = ($view->lineHeight - $s->fontSize) / 2.0;

        $from = max($firstLine, $view->firstRow);
        $to = min($lastLine, $view->firstRow + $view->visibleRows - 1);
        for ($row = $from; $row <= $to; $row++) {
            $line = $lines[$row] ?? '';
            $c0 = $row === $firstLine ? $firstCol : 0;
            $c1 = $row === $lastLine ? $lastCol : mb_strlen($line);
            $x0 = $c0 > 0 ? $this->renderer->measureText(mb_substr($line, 0, $c0), $s->fontSize)->width : 0.0;
            $x1 = $c1 > 0 ? $this->renderer->measureText(mb_substr($line, 0, $c1), $s->fontSize)->width : 0.0;
            if ($row < $lastLine) {
                $x1 += $tail; // the line break is selected too
            }
            $sx0 = max($left, $view->textX + $x0 - $view->scrollX);
            $sx1 = min($right, $view->textX + $x1 - $view->scrollX);
            if ($sx1 - $sx0 < 0.5) {
                continue;
            }
            $this->renderer->drawRect($sx0, $view->rowY($row) - $padY, $sx1 - $sx0, $view->lineHeight, $color);
        }
    }

    /**
     * Per text area: first visible row, horizontal scroll (px) and the caret /
     * text the window last followed.
     *
     * @var array<string, array{row: int, x: float, caret: int, buffer: ?string}>
     */
    private array $textAreaScroll = [];

    /** @var array<string, float> horizontal scroll (px) per single-line text field */
    private array $textFieldScroll = [];

    /** @var array<string, int> rows to bring into view on the next draw */
    private array $textAreaReveal = [];

    /** @var array<string, TextAreaView> geometry of the last draw per text area */
    private array $textAreaViews = [];

    /**
     * Geometry of the text area $id as last drawn (null before its first draw).
     * Use it to line up a gutter, markers or bands with the visible rows.
     */
    public function textAreaView(string $id): ?TextAreaView
    {
        return $this->textAreaViews[$id] ?? null;
    }

    /**
     * Scroll text area $id so that $row (0-based) is visible on its next draw,
     * roughly centred if it has to move. For views that point at a line from
     * outside the field (a debugger's current line, an error location).
     */
    public function revealTextAreaRow(string $id, int $row): void
    {
        $this->textAreaReveal[$id] = max(0, $row);
    }

    /**
     * The part of $line that fits into [scrollX, scrollX + width], cut at
     * glyph boundaries, and its x offset relative to the text origin.
     *
     * @return array{0: string, 1: float}
     */
    private function visibleSlice(string $line, float $scrollX, float $width, float $size): array
    {
        $full = $this->renderer->measureText($line, $size)->width;
        if ($scrollX <= 0.0 && $full <= $width) {
            return [$line, 0.0];
        }

        $len = mb_strlen($line);
        $prefix = fn(int $n): float => $n <= 0 ? 0.0 : $this->renderer->measureText(mb_substr($line, 0, $n), $size)->width;

        // First glyph that starts at or right of the scroll offset.
        $lo = 0;
        $hi = $len;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($prefix($mid) < $scrollX - 0.001) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        $start = $lo;
        $startPx = $prefix($start);

        // Last glyph that still ends inside the visible width.
        $lo = $start;
        $hi = $len;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if ($prefix($mid) - $scrollX <= $width + 0.001) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        return [mb_substr($line, $start, $lo - $start), $startPx - $scrollX];
    }

    /** Caret index under $point in a text area whose row $firstRow starts at ($originX, $originY). */
    private function caretAtPoint(string $buf, Vec2 $point, float $originX, float $originY, float $lineH, int $firstRow): int
    {
        $lines = explode("\n", $buf);
        $row = $firstRow + (int) floor(($point->y - $originY) / $lineH);
        $row = max(0, min($row, count($lines) - 1));
        $line = $lines[$row];
        $size = $this->style->fontSize;
        $x = $point->x - $originX;

        $col = 0;
        $len = mb_strlen($line);
        $prev = 0.0;
        // Nearest glyph boundary: linear is fine for one clicked line.
        for ($n = 1; $n <= $len; $n++) {
            $w = $this->renderer->measureText(mb_substr($line, 0, $n), $size)->width;
            if ($x < ($prev + $w) / 2.0) {
                break;
            }
            $col = $n;
            $prev = $w;
        }

        $offset = 0;
        for ($i = 0; $i < $row; $i++) {
            $offset += mb_strlen($lines[$i]) + 1;
        }
        return $offset + $col;
    }

    /**
     * Set (or clear, with '') the focused text field, raising/dismissing the
     * on-screen keyboard on the transition. No-op for the keyboard on desktop;
     * on iOS it brings up / hides the soft keyboard so touch users can type.
     */
    private function setTextFieldFocus(string $id): void
    {
        if ($id === $this->focusedTextField) {
            return;
        }
        $this->focusedTextField = $id;
        if ($id !== '') {
            $this->input->showSoftKeyboard();
        } else {
            $this->input->hideSoftKeyboard();
        }
    }

    /**
     * Programmatically focus a text field / area (e.g. so a freshly-opened
     * editor accepts typing without a click first). Seeds the edit buffer with
     * $value and puts the caret at $caret (clamped), or at the end when null –
     * pass {@see focusedTextFieldCaret()} from before to hand focus back to a
     * field exactly where the caret was.
     */
    public function focusTextField(string $id, string $value = '', ?int $caret = null): void
    {
        $this->setTextFieldFocus($id);
        $this->textFieldBuffer = $value;
        $length = mb_strlen($value);
        $this->textFieldCursor = $caret === null ? $length : max(0, min($caret, $length));
    }

    /** Caret index (characters) in the focused text field; 0 when none has focus. */
    public function focusedTextFieldCaret(): int
    {
        return $this->focusedTextField === '' ? 0 : $this->textFieldCursor;
    }

    /**
     * Dropdown selector. Returns the (possibly new) selected index.
     *
     * Click on the closed field toggles the option list open.
     * Clicking an option selects it and closes the list.
     * Clicking outside closes the list without changing the selection.
     *
     * The open list is drawn as an overlay — only the closed button height
     * is advanced in the layout so widgets below are not pushed down.
     *
     * @param list<string> $options
     * @param float        $width   Override width (0 = regionWidth)
     */
    public function dropdown(string $id, array $options, int $selectedIndex, float $width = 0.0, int $maxVisibleItems = 0): int
    {
        $s = $this->style;
        $h = $s->fontSize + $s->padding * 2;
        $w = $width > 0.0 ? min($width, $this->regionWidth) : $this->regionWidth;

        $fieldRect = new Rect($this->cursorX, $this->cursorY, $w, $h);
        $isOpen = $this->openDropdown === $id;
        $hovered = $this->isHovered($fieldRect);

        if ($hovered) {
            $this->anyHovered = true; $this->hoveredWidget = $id;
            if ($this->input->isMouseButtonReleased(0)) {
                $this->openDropdown = $isOpen ? '' : $id;
                $isOpen = !$isOpen;
                if ($isOpen) {
                    // Reset scroll to show selected item
                    $this->dropdownScrollOffset[$id] = max(0, $selectedIndex - (int)(($maxVisibleItems > 0 ? $maxVisibleItems : count($options)) / 2));
                }
            }
        }

        // Draw the closed button
        $bg = ($hovered || $isOpen) ? $s->backgroundColor : $s->hoverColor;
        $this->renderer->drawRoundedRect($fieldRect->x, $fieldRect->y, $w, $h, $s->borderRadius, $bg);
        $this->renderer->drawRectOutline($fieldRect->x, $fieldRect->y, $w, $h, $s->borderColor, $s->borderWidth);

        $selected = $options[$selectedIndex] ?? '';
        $this->renderer->drawText($selected, $fieldRect->x + $s->padding, $fieldRect->y + $s->padding, $s->fontSize, $s->textColor);

        $arrow = $isOpen ? '▲' : '▼';
        $this->renderer->drawText($arrow, $fieldRect->right() - $s->fontSize - $s->padding, $fieldRect->y + $s->padding, $s->fontSize * 0.75, $s->textColor);

        // Process input for the open option list (inline for responsiveness)
        // but defer rendering so the overlay draws on top of all widgets.
        if ($isOpen && count($options) > 0) {
            $rowH = $h;
            $totalCount = count($options);
            $visibleCount = ($maxVisibleItems > 0 && $maxVisibleItems < $totalCount) ? $maxVisibleItems : $totalCount;
            $scrollable = $visibleCount < $totalCount;

            $scrollOffset = $this->dropdownScrollOffset[$id] ?? 0;
            $maxOffset = $totalCount - $visibleCount;
            $scrollOffset = max(0, min($scrollOffset, $maxOffset));

            $listY = $fieldRect->y + $h + 2.0;
            $listH = $rowH * $visibleCount;
            // Flip the list upward when opening downward would run past the bottom
            // of the viewport and there is room above the field — so a dropdown
            // near the screen edge stays fully visible instead of being clipped.
            if ($this->viewportHeight > 0.0
                && $listY + $listH > $this->viewportHeight
                && $fieldRect->y - $listH - 2.0 >= 0.0
            ) {
                $listY = $fieldRect->y - $listH - 2.0;
            }
            $listRect = new Rect($fieldRect->x, $listY, $w, $listH);

            $listHovered = $this->isHovered($listRect);
            if ($listHovered) {
                $this->anyHovered = true; $this->hoveredWidget = $id;
            }

            // Mouse wheel scrolling
            if ($scrollable && ($hovered || $listHovered)) {
                $scrollY = $this->input->getScrollY();
                if (abs($scrollY) > 0.001) {
                    $scrollOffset -= ($scrollY > 0.0) ? 1 : -1;
                    $scrollOffset = max(0, min($scrollOffset, $maxOffset));
                    $this->dropdownScrollOffset[$id] = $scrollOffset;
                }
            }

            // Per-item input (hover highlight state + click selection)
            $hoveredItem = -1;
            for ($vi = 0; $vi < $visibleCount; $vi++) {
                $i = $vi + $scrollOffset;
                if ($i >= $totalCount) break;
                $optRect = new Rect($fieldRect->x, $listY + $vi * $rowH, $w, $rowH);
                if ($this->isHovered($optRect)) {
                    $this->anyHovered = true; $this->hoveredWidget = $id;
                    $hoveredItem = $vi;
                    if ($this->input->isMouseButtonReleased(0)) {
                        $selectedIndex = $i;
                        $this->openDropdown = '';
                        $isOpen = false;
                    }
                }
            }

            // Click outside closes
            if (!$hovered && !$listHovered && $this->input->isMouseButtonReleased(0)) {
                $this->openDropdown = '';
            }

            // Defer all rendering to after the outermost end() call
            $drawScrollOffset = $scrollOffset;
            $drawSelectedIndex = $selectedIndex;
            $drawHoveredItem = $hoveredItem;
            $this->deferredOverlays[] = function () use (
                $s, $fieldRect, $w, $listY, $listH, $rowH,
                $visibleCount, $totalCount, $scrollable,
                $drawScrollOffset, $drawSelectedIndex, $drawHoveredItem,
                $options, $maxOffset,
            ) {
                $overlayBg = new Color(
                    max(0.0, $s->backgroundColor->r * 0.5),
                    max(0.0, $s->backgroundColor->g * 0.5),
                    max(0.0, $s->backgroundColor->b * 0.5),
                    1.0,
                );
                $this->renderer->drawRoundedRect($fieldRect->x, $listY, $w, $listH, $s->borderRadius, $overlayBg);
                $this->renderer->drawRectOutline($fieldRect->x, $listY, $w, $listH, $s->accentColor, $s->borderWidth);

                for ($vi = 0; $vi < $visibleCount; $vi++) {
                    $i = $vi + $drawScrollOffset;
                    if ($i >= $totalCount) break;
                    $optY = $listY + $vi * $rowH;

                    if ($vi === $drawHoveredItem) {
                        $this->renderer->drawRoundedRect($fieldRect->x, $optY, $w, $rowH, $s->borderRadius, $s->hoverColor);
                    } elseif ($i === $drawSelectedIndex) {
                        $this->renderer->drawRoundedRect($fieldRect->x, $optY, $w, $rowH, $s->borderRadius, $s->accentColor->withAlpha(0.25));
                    }

                    $this->renderer->drawText($options[$i], $fieldRect->x + $s->padding, $optY + $s->padding, $s->fontSize, $s->textColor);
                }

                if ($scrollable && $maxOffset > 0) {
                    $scrollbarW = 4.0;
                    $scrollbarX = $fieldRect->x + $w - $scrollbarW - 2.0;
                    $thumbH = max(20.0, $listH * ($visibleCount / (float)$totalCount));
                    $thumbY = $listY + ($listH - $thumbH) * ($drawScrollOffset / (float)$maxOffset);
                    $this->renderer->drawRoundedRect($scrollbarX, $thumbY, $scrollbarW, $thumbH, 2.0, $s->textColor->withAlpha(0.3));
                }
            };
        }

        // Horizontal flow steps along by the field's width, the way label() and
        // button() do. Advancing by the height instead put the next widget in the
        // row a fraction of a field along, drawing it over this one.
        $this->advance($this->flow === 'horizontal' ? $w : $h);
        return $selectedIndex;
    }

    /**
     * Progress bar (read-only). Value between 0.0 and 1.0.
     */
    public function progressBar(string $label, float $value): void
    {
        $s = $this->style;
        $barH = $s->fontSize * 0.6;
        $totalH = $s->fontSize + $barH + $s->padding * 3;

        $this->renderer->drawText(
            $label,
            $this->cursorX + $s->padding,
            $this->cursorY + $s->padding,
            $s->fontSize,
            $s->textColor,
        );

        $barX = $this->cursorX + $s->padding;
        $barY = $this->cursorY + $s->fontSize + $s->padding * 2;
        $barW = $this->regionWidth - $s->padding * 2;

        $this->renderer->drawRoundedRect($barX, $barY, $barW, $barH, $barH * 0.5, $s->backgroundColor);

        $fillW = $barW * max(0.0, min(1.0, $value));
        if ($fillW > 1.0) {
            $this->renderer->drawRoundedRect($barX, $barY, $fillW, $barH, $barH * 0.5, $s->accentColor);
        }

        $this->advance($totalH);
    }

    /**
     * Horizontal separator line.
     */
    public function separator(): void
    {
        $y = $this->cursorY + $this->style->itemSpacing;
        $this->renderer->drawRect(
            $this->cursorX,
            $y,
            $this->regionWidth,
            1.0,
            $this->style->borderColor,
        );
        $this->advance($this->style->itemSpacing * 2 + 1.0);
    }

    /**
     * Spacing in the current flow direction.
     * In vertical flow: adds vertical space. In horizontal flow: adds horizontal space.
     */
    public function space(float $size = 0.0): void
    {
        $this->advance($size > 0.0 ? $size : $this->style->itemSpacing * 2);
    }

    /**
     * Panel/window background with title bar.
     */
    public function panel(string $title, float $width, float $height): void
    {
        $s = $this->style;
        $titleH = $s->fontSize + $s->padding * 2;

        // Background
        $this->renderer->drawRoundedRect($this->cursorX, $this->cursorY, $width, $height, $s->borderRadius, $s->backgroundColor);

        // Title bar
        $this->renderer->drawRoundedRect($this->cursorX, $this->cursorY, $width, $titleH, $s->borderRadius, $s->activeColor);

        $this->renderer->drawText(
            $title,
            $this->cursorX + $s->padding,
            $this->cursorY + $s->padding,
            $s->fontSize,
            $s->textColor,
        );

        // Border
        $this->renderer->drawRectOutline($this->cursorX, $this->cursorY, $width, $height, $s->borderColor, $s->borderWidth);

        // Move cursor inside panel
        $this->cursorX += $s->padding;
        $this->cursorY += $titleH + $s->padding;
        $this->regionWidth = $width - $s->padding * 2;
    }

    /**
     * Draw deferred overlays (open dropdown lists). Call once at the end
     * of the frame, after all begin/end pairs, so overlays render on top.
     */
    public function flushOverlays(): void
    {
        if (count($this->deferredOverlays) === 0) {
            return;
        }
        $this->renderer->setFont($this->style->fontName);
        $overlays = $this->deferredOverlays;
        $this->deferredOverlays = [];
        foreach ($overlays as $draw) {
            $draw();
        }
    }

    /**
     * Returns true if any widget was hovered this frame.
     */
    public function isAnyHovered(): bool
    {
        return $this->anyHovered;
    }

    /**
     * Id of the leaf widget currently hovered, or '' when none. Lets callers
     * detect per-widget hover-enter across frames (e.g. to play a hover SFX
     * each time the cursor moves onto a different widget).
     */
    public function hoveredWidgetId(): string
    {
        return $this->hoveredWidget;
    }

    /**
     * Whether a dropdown's option list is currently open. Persistent across frames
     * (set on open, cleared on select/dismiss), so a scrollable container drawn
     * behind the dropdown can suppress its own mouse-wheel handling while the list
     * is open — otherwise the wheel scrolls both the list and the container.
     */
    public function hasOpenDropdown(): bool
    {
        return $this->openDropdown !== '';
    }

    /**
     * Check if any text field currently has input focus.
     */
    public function hasTextFieldFocus(): bool
    {
        return $this->focusedTextField !== '';
    }

    /**
     * Id of the text field / area that has input focus, or '' when none does.
     * Lets a caller route keys by focus – e.g. Enter inserts a newline in one
     * field, submits a single-line field, and does something else entirely
     * with nothing focused.
     */
    public function focusedTextFieldId(): string
    {
        return $this->focusedTextField;
    }

    /**
     * Get the current cursor Y position (useful for custom layouts).
     */
    public function getCursorY(): float
    {
        return $this->cursorY;
    }

    /**
     * Get the current cursor X position (useful for custom layouts).
     */
    public function getCursorX(): float
    {
        return $this->cursorX;
    }

    /**
     * Current layout region width (useful for custom layouts inside a region).
     */
    public function getRegionWidth(): float
    {
        return $this->regionWidth;
    }

    public function setCursorPosition(float $x, float $y): void
    {
        $this->cursorX = $x;
        $this->cursorY = $y;
    }

    /**
     * Set viewport offset for letterboxing. Mouse coordinates will be
     * adjusted by this offset when hit-testing widgets.
     */
    public function setViewportOffset(float $x, float $y): void
    {
        $this->viewportOffsetX = $x;
        $this->viewportOffsetY = $y;
    }

    /**
     * Set the design-space content-region offset applied to hit-testing only
     * (see {@see $contentOffsetX}). Pass the same translation the renderer is
     * transformed by while drawing into a sub-region; reset to (0, 0) afterwards.
     */
    public function setContentOffset(float $x, float $y): void
    {
        $this->contentOffsetX = $x;
        $this->contentOffsetY = $y;
    }

    /**
     * Set the virtual viewport height (design-space units). When set, a dropdown
     * whose option list would extend past this height opens upward instead, so a
     * dropdown near the bottom of the screen stays fully visible.
     */
    public function setViewportHeight(float $height): void
    {
        $this->viewportHeight = $height;
    }

    /**
     * Set content scale for resolution-independent rendering.
     * When set, mouse coordinates are divided by this factor after
     * viewport-offset correction, so hit-testing works in virtual coordinates.
     */
    public function setContentScale(float $scale): void
    {
        $this->contentScale = max(0.001, $scale);
    }

    /**
     * Toggle whether widgets respond to hover/click. False makes every
     * widget rendered after the call non-interactive — hover misses, button
     * release-triggers never fire, sliders/text fields can't be grabbed.
     * Use to gate an underlying scene while a modal overlay is open:
     *
     *   $ui->setInteractive(false);
     *   drawPanelsBehindModal();
     *   $ui->setInteractive(true);
     *   ConfirmDialog::draw($engine, $w, $h);
     *
     * Widgets still render — only input is blocked. Defaults to true on
     * construction; callers must restore to true before drawing the modal
     * itself or its own widgets will be dead.
     */
    public function setInteractive(bool $on): void
    {
        $this->interactive = $on;
    }

    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    // ── Internals ────────────────────────────────────────────────

    private function isHovered(Rect $rect): bool
    {
        if (!$this->interactive) {
            return false;
        }
        return $rect->contains($this->designMouse());
    }

    /** The mouse position in the layout's design space (see isHovered). */
    private function designMouse(): Vec2
    {
        $mouse = $this->input->getMousePosition();
        // Adjust mouse position for viewport offset (letterboxing) and content scale
        $mx = ($mouse->x - $this->viewportOffsetX) / $this->contentScale;
        // RTL: mirror the cursor X so hit-tests match mirror-drawn widgets.
        if (self::$rtlMirrorWidth !== null) {
            $mx = self::$rtlMirrorWidth - $mx;
        }
        // Content-region translate (draws moved by a renderer transform): bring
        // the cursor into that same space. Zero unless set by the consumer.
        return new Vec2(
            $mx - $this->contentOffsetX,
            ($mouse->y - $this->viewportOffsetY) / $this->contentScale - $this->contentOffsetY,
        );
    }

    /**
     * Advance the cursor after rendering a widget.
     * In vertical flow: moves Y down. In horizontal flow: moves X right.
     *
     * @param float $size The size to advance (height in vertical, width in horizontal).
     *                    In horizontal mode, this is treated as the widget width.
     */
    private function advance(float $size): void
    {
        if ($this->flow === 'horizontal') {
            $this->cursorX += $size + $this->style->itemSpacing;
        } else {
            $this->cursorY += $size + $this->style->itemSpacing;
        }
    }
}

