<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat3;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;

interface Renderer2DInterface extends RenderContextInterface
{
    public function drawRect(float $x, float $y, float $w, float $h, Color $color): void;

    public function drawRectOutline(float $x, float $y, float $w, float $h, Color $color, float $lineWidth = 1.0): void;

    public function drawRoundedRect(float $x, float $y, float $w, float $h, float $radius, Color $color): void;

    public function drawRoundedRectOutline(float $x, float $y, float $w, float $h, float $radius, Color $color, float $lineWidth = 1.0): void;

    public function drawCircle(float $cx, float $cy, float $r, Color $color): void;

    public function drawCircleOutline(float $cx, float $cy, float $r, Color $color, float $lineWidth = 1.0): void;

    public function drawLine(Vec2 $from, Vec2 $to, Color $color, float $width = 1.0): void;

    public function drawText(string $text, float $x, float $y, float $size, Color $color): void;

    public function drawTextCentered(string $text, float $cx, float $cy, float $size, Color $color): void;

    /**
     * Draws text wrapped to fit within `breakWidth`. Word-wraps on spaces.
     *
     * Explicit newline characters (`\n`, `\r\n`, `\r`) are honoured as hard line
     * breaks across all backends. Empty paragraphs (`"\n\n"`) take one line of
     * vertical space. Word-wrap then runs within each hard-broken paragraph.
     */
    public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, Color $color): void;

    public function drawSprite(Texture $texture, ?Rect $srcRegion, float $x, float $y, float $w, float $h, float $opacity = 1.0): void;

    public function pushTransform(Mat3 $matrix): void;

    public function popTransform(): void;

    public function pushScissor(float $x, float $y, float $w, float $h): void;

    public function popScissor(): void;

    public function loadFont(string $name, string $path): void;

    /**
     * Register a font for background (non-blocking) loading.
     *
     * Like {@see loadFont()}, but on backends that support it (currently the
     * vio backend) the font's glyph atlas is rasterized on a worker thread the
     * first time the font is needed, instead of blocking the render thread.
     * Until the worker finishes, the font is skipped in the fallback chain
     * (text still renders with the remaining fonts) and its real glyphs appear
     * a few frames later. Intended for large CJK fallback fonts whose
     * synchronous load otherwise stalls startup for many seconds.
     *
     * Backends without async support (NanoVG/GLFW fallback, GD test renderer,
     * null renderer) may treat this as a synchronous alias for {@see loadFont()}
     * — the call is always safe and the font ends up registered either way.
     */
    public function preloadFontAsync(string $name, string $path): void;

    public function setFont(string $name): void;

    /**
     * Set the glyph-atlas devicePixelRatio (logical->physical magnification).
     *
     * Backends that rasterize glyphs into a fixed-size atlas (vio) bilinearly
     * upscale that atlas when a transform magnifies text, which looks blurry on
     * HiDPI / high-resolution displays. Passing the on-screen magnification here
     * makes such backends rasterize the atlas at size*scale physical pixels
     * while keeping every metric in logical units, so text stays crisp.
     *
     * Backends that already rasterize per device-pixel (NanoVG/GLFW via
     * devicePixelRatio, the GD test renderer, the null renderer) may treat this
     * as a no-op — the call is always safe. Values below 1.0 are clamped.
     */
    public function setFontRenderScale(float $scale): void;

    /**
     * Sets the text alignment for subsequent drawText/drawTextCentered/drawTextBox calls.
     * Use TextAlign constants combined with bitwise OR, e.g. TextAlign::CENTER | TextAlign::MIDDLE.
     */
    public function setTextAlign(int $align): void;

    /**
     * Measures the width and height of a single line of text without drawing it.
     */
    public function measureText(string $text, float $size): TextMetrics;

    /**
     * Measures the bounding box of text wrapped at the given breakWidth.
     *
     * Honours explicit `\n` / `\r\n` / `\r` as hard line breaks, so the reported
     * height matches what `drawTextBox()` will actually render. Empty paragraphs
     * contribute one `lineHeight` each.
     */
    public function measureTextBox(string $text, float $breakWidth, float $size): TextMetrics;

    /**
     * Registers a fallback font to be used when glyphs are missing from the base font.
     * Primarily used for CJK character support.
     */
    public function addFallbackFont(string $baseFont, string $fallbackFont): void;

    /**
     * Removes registered fallback fonts — for $baseFont only, or the whole
     * chain when null. Lets a locale switch REORDER the chain (fallbacks are
     * first-covering-glyph-wins, so e.g. a Japanese locale wants its regional
     * CJK face chained before the Simplified-Chinese one): clear, then re-add
     * in the new preference order. Loaded font faces stay registered; only
     * the fallback wiring is reset.
     */
    public function clearFallbackFonts(?string $baseFont = null): void;

    /**
     * Sets the global alpha (opacity) applied to all subsequent draw calls.
     * Value should be between 0.0 (fully transparent) and 1.0 (fully opaque).
     */
    public function setGlobalAlpha(float $alpha): void;

    /**
     * Draws a filled arc (pie slice).
     *
     * @param float $cx Center X
     * @param float $cy Center Y
     * @param float $r Radius
     * @param float $startAngle Start angle in radians
     * @param float $endAngle End angle in radians
     * @param int $direction Winding direction: 0 = counter-clockwise (CCW), 1 = clockwise (CW)
     */
    public function drawArc(float $cx, float $cy, float $r, float $startAngle, float $endAngle, Color $color, int $direction = 0): void;

    /**
     * Saves the current drawing state (transform, scissor, font, alpha, text alignment)
     * onto an internal stack.
     */
    public function saveState(): void;

    /**
     * Restores the most recently saved drawing state from the internal stack.
     */
    public function restoreState(): void;

    /**
     * Begin a frame whose draws are routed into a private off-screen render
     * target instead of the swapchain. Used by `Engine::warmRender()` to
     * pre-rasterise glyph atlases and warm sprite uploads during splash without
     * ever presenting the pixels.
     *
     * Implementations that cannot redirect rendering (NanoVG/GLFW fallback,
     * `NullRenderer2D`, GD test renderer) may treat this as a no-op alias for
     * `beginFrame()` — the warm callback still exercises glyph paths against
     * the default surface; we accept the minor flash on those backends because
     * vio is the shipping path.
     *
     * Lifecycle: every `beginOffscreenFrame()` must be paired with exactly one
     * `endOffscreenFrame()`. Mixing with `beginFrame()` / `endFrame()` inside
     * the same frame is undefined.
     *
     * @param int $width  Desired off-screen target width in pixels (>= 1).
     * @param int $height Desired off-screen target height in pixels (>= 1).
     */
    public function beginOffscreenFrame(int $width, int $height): void;

    /**
     * End a frame that was started with `beginOffscreenFrame()`. The off-screen
     * target is unbound and the swapchain becomes the active draw target again.
     * On backends without offscreen support this falls back to `endFrame()`.
     */
    public function endOffscreenFrame(): void;
}
