<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat3;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\UI\UIScale;

/**
 * A 2D renderer in logical pixels: every position, size, radius, line width,
 * font size and scissor is multiplied by the shared {@see UIScale} before it
 * reaches the wrapped renderer, measurements come back divided, and
 * getWidth()/getHeight() report the screen in logical pixels.
 *
 * Unlike a magnifying transform, text is asked for at the larger size, so a
 * glyph atlas never gets stretched (crisp on 4K). Axis-aligned rectangles snap
 * their edges to whole physical pixels: neighbours keep sharing an edge and a
 * 1 px hairline stays one sharp pixel at a fractional factor. Factor 1.0 passes
 * everything through unchanged.
 *
 * Pair it with {@see \PHPolygon\Runtime\ScaledInput} on the same UIScale so
 * the pointer lands in the same logical space, and hand both to a UIContext
 * via {@see \PHPolygon\UI\UIContext::useBackends()} while it lays out.
 */
final class ScaledRenderer2D implements Renderer2DInterface
{
    public function __construct(
        private readonly Renderer2DInterface $inner,
        private readonly UIScale $scale,
    ) {}

    public function inner(): Renderer2DInterface
    {
        return $this->inner;
    }

    public function scale(): UIScale
    {
        return $this->scale;
    }

    // ── Frame ────────────────────────────────────────────────────────

    public function beginFrame(): void
    {
        $this->inner->beginFrame();
    }

    public function endFrame(): void
    {
        $this->inner->endFrame();
    }

    public function clear(Color $color): void
    {
        $this->inner->clear($color);
    }

    public function setViewport(int $x, int $y, int $width, int $height): void
    {
        $s = $this->scale->factor;
        $this->inner->setViewport((int) round($x * $s), (int) round($y * $s), (int) round($width * $s), (int) round($height * $s));
    }

    public function getWidth(): int
    {
        return (int) floor($this->inner->getWidth() / $this->scale->factor);
    }

    public function getHeight(): int
    {
        return (int) floor($this->inner->getHeight() / $this->scale->factor);
    }

    // ── Shapes ───────────────────────────────────────────────────────

    public function drawRect(float $x, float $y, float $w, float $h, Color $color): void
    {
        [$x, $y, $w, $h] = $this->snap($x, $y, $w, $h);
        $this->inner->drawRect($x, $y, $w, $h, $color);
    }

    public function drawRectOutline(float $x, float $y, float $w, float $h, Color $color, float $lineWidth = 1.0): void
    {
        [$x, $y, $w, $h] = $this->snap($x, $y, $w, $h);
        $this->inner->drawRectOutline($x, $y, $w, $h, $color, $this->line($lineWidth));
    }

    public function drawRoundedRect(float $x, float $y, float $w, float $h, float $radius, Color $color): void
    {
        [$x, $y, $w, $h] = $this->snap($x, $y, $w, $h);
        $this->inner->drawRoundedRect($x, $y, $w, $h, $radius * $this->scale->factor, $color);
    }

    public function drawRoundedRectOutline(float $x, float $y, float $w, float $h, float $radius, Color $color, float $lineWidth = 1.0): void
    {
        [$x, $y, $w, $h] = $this->snap($x, $y, $w, $h);
        $this->inner->drawRoundedRectOutline($x, $y, $w, $h, $radius * $this->scale->factor, $color, $this->line($lineWidth));
    }

    public function drawCircle(float $cx, float $cy, float $r, Color $color): void
    {
        $s = $this->scale->factor;
        $this->inner->drawCircle($cx * $s, $cy * $s, $r * $s, $color);
    }

    public function drawCircleOutline(float $cx, float $cy, float $r, Color $color, float $lineWidth = 1.0): void
    {
        $s = $this->scale->factor;
        $this->inner->drawCircleOutline($cx * $s, $cy * $s, $r * $s, $color, $this->line($lineWidth));
    }

    public function drawLine(Vec2 $from, Vec2 $to, Color $color, float $width = 1.0): void
    {
        $s = $this->scale->factor;
        $this->inner->drawLine(new Vec2($from->x * $s, $from->y * $s), new Vec2($to->x * $s, $to->y * $s), $color, $this->line($width));
    }

    public function drawArc(float $cx, float $cy, float $r, float $startAngle, float $endAngle, Color $color, int $direction = 0): void
    {
        $s = $this->scale->factor;
        $this->inner->drawArc($cx * $s, $cy * $s, $r * $s, $startAngle, $endAngle, $color, $direction);
    }

    public function drawSprite(Texture $texture, ?Rect $srcRegion, float $x, float $y, float $w, float $h, float $opacity = 1.0): void
    {
        $s = $this->scale->factor;
        // The source region is in texture pixels, not on screen: unchanged.
        $this->inner->drawSprite($texture, $srcRegion, $x * $s, $y * $s, $w * $s, $h * $s, $opacity);
    }

    // ── Text ─────────────────────────────────────────────────────────

    public function drawText(string $text, float $x, float $y, float $size, Color $color): void
    {
        $s = $this->scale->factor;
        $this->inner->drawText($text, $x * $s, $y * $s, $size * $s, $color);
    }

    public function drawTextCentered(string $text, float $cx, float $cy, float $size, Color $color): void
    {
        $s = $this->scale->factor;
        $this->inner->drawTextCentered($text, $cx * $s, $cy * $s, $size * $s, $color);
    }

    public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, Color $color): void
    {
        $s = $this->scale->factor;
        $this->inner->drawTextBox($text, $x * $s, $y * $s, $breakWidth * $s, $size * $s, $color);
    }

    public function measureText(string $text, float $size): TextMetrics
    {
        $s = $this->scale->factor;
        $m = $this->inner->measureText($text, $size * $s);

        return $s === 1.0 ? $m : new TextMetrics($m->width / $s, $m->height / $s);
    }

    public function measureTextBox(string $text, float $breakWidth, float $size): TextMetrics
    {
        $s = $this->scale->factor;
        $m = $this->inner->measureTextBox($text, $breakWidth * $s, $size * $s);

        return $s === 1.0 ? $m : new TextMetrics($m->width / $s, $m->height / $s);
    }

    public function loadFont(string $name, string $path): void
    {
        $this->inner->loadFont($name, $path);
    }

    public function preloadFontAsync(string $name, string $path): void
    {
        $this->inner->preloadFontAsync($name, $path);
    }

    public function setFont(string $name): void
    {
        $this->inner->setFont($name);
    }

    /** Magnification by a transform, on top of this renderer's factor: passed through. */
    public function setFontRenderScale(float $scale): void
    {
        $this->inner->setFontRenderScale($scale);
    }

    public function setTextAlign(int $align): void
    {
        $this->inner->setTextAlign($align);
    }

    public function fontCoversScript(string $font, Script $script): bool
    {
        return $this->inner->fontCoversScript($font, $script);
    }

    public function fontForScript(Script $script, array $candidates): ?string
    {
        return $this->inner->fontForScript($script, $candidates);
    }

    public function addFallbackFont(string $baseFont, string $fallbackFont): void
    {
        $this->inner->addFallbackFont($baseFont, $fallbackFont);
    }

    public function clearFallbackFonts(?string $baseFont = null): void
    {
        $this->inner->clearFallbackFonts($baseFont);
    }

    // ── State ────────────────────────────────────────────────────────

    /**
     * A transform in logical pixels: conjugated with the factor, so it acts on
     * the scaled coordinates exactly as it would on logical ones (a rotation
     * about a logical point turns about that point on screen).
     */
    public function pushTransform(Mat3 $matrix): void
    {
        $s = $this->scale->factor;
        if ($s !== 1.0) {
            $matrix = Mat3::scaling($s, $s)->multiply($matrix)->multiply(Mat3::scaling(1.0 / $s, 1.0 / $s));
        }
        $this->inner->pushTransform($matrix);
    }

    public function popTransform(): void
    {
        $this->inner->popTransform();
    }

    public function pushScissor(float $x, float $y, float $w, float $h): void
    {
        [$x, $y, $w, $h] = $this->snap($x, $y, $w, $h);
        $this->inner->pushScissor($x, $y, $w, $h);
    }

    public function popScissor(): void
    {
        $this->inner->popScissor();
    }

    public function setGlobalAlpha(float $alpha): void
    {
        $this->inner->setGlobalAlpha($alpha);
    }

    public function saveState(): void
    {
        $this->inner->saveState();
    }

    public function restoreState(): void
    {
        $this->inner->restoreState();
    }

    public function beginOffscreenFrame(int $width, int $height): void
    {
        $this->inner->beginOffscreenFrame($width, $height);
    }

    public function endOffscreenFrame(): void
    {
        $this->inner->endOffscreenFrame();
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Scaled rectangle with its edges on whole physical pixels (both edges
     * rounded, the size is their difference). Passes through at factor 1.0.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function snap(float $x, float $y, float $w, float $h): array
    {
        $s = $this->scale->factor;
        if ($s === 1.0) {
            return [$x, $y, $w, $h];
        }
        $x0 = round($x * $s);
        $y0 = round($y * $s);

        return [$x0, $y0, round(($x + $w) * $s) - $x0, round(($y + $h) * $s) - $y0];
    }

    /** Line width in physical pixels: scaled, at least the width asked for. */
    private function line(float $width): float
    {
        $s = $this->scale->factor;

        return $s === 1.0 ? $width : max($width, round($width * $s));
    }
}
