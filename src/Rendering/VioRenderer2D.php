<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat3;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use VioContext;
use VioFont;
use VioTexture;

class VioRenderer2D implements Renderer2DInterface
{
    private int $width;
    private int $height;

    private string $currentFontName = '';

    private int $textAlign = TextAlign::DEFAULT;

    private float $globalAlpha = 1.0;

    /** @var array<string, string> Font name -> file path */
    private array $fontPaths = [];

    /** @var array<string, VioFont> Cache key "name:size" -> VioFont */
    private array $fontCache = [];

    /** @var array<int, VioTexture> Texture glId/objectId -> VioTexture */
    private array $vioTextures = [];

    /**
     * State stack for saveState()/restoreState().
     * Each entry stores: [fontName, textAlign, globalAlpha]
     * @var list<array{string, int, float}>
     */
    private array $stateStack = [];

    /**
     * Auto-incrementing z-order counter. Every draw call gets a unique z value,
     * preserving painter's algorithm order after vio's qsort by z-order.
     */
    private float $zCounter = 0.0;
    private const Z_STEP = 0.0001;

    public function __construct(
        private readonly VioContext $ctx,
    ) {
        $this->width = 1280;
        $this->height = 720;
    }

    private function nextZ(): float
    {
        $z = $this->zCounter;
        $this->zCounter += self::Z_STEP;
        return $z;
    }

    public function beginFrame(): void
    {
        vio_begin($this->ctx);

        // Sync dimensions from vio context (handles window resize/maximize)
        $size = vio_window_size($this->ctx);
        $this->width = $size[0];
        $this->height = $size[1];

        $this->zCounter = 0.0;

        vio_clear($this->ctx, 0.0, 0.0, 0.0, 1.0);
    }

    public function endFrame(): void
    {
        vio_draw_2d($this->ctx);
        vio_end($this->ctx);
    }

    public function clear(Color $color): void
    {
        vio_clear($this->ctx, $color->r, $color->g, $color->b, $color->a);
    }

    public function setViewport(int $x, int $y, int $width, int $height): void
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function drawRect(float $x, float $y, float $w, float $h, Color $color): void
    {
        vio_rect($this->ctx, $x, $y, $w, $h, ['color' => $this->colorToArgb($color), 'z' => $this->nextZ()]);
    }

    public function drawRectOutline(float $x, float $y, float $w, float $h, Color $color, float $lineWidth = 1.0): void
    {
        vio_rect($this->ctx, $x, $y, $w, $h, [
            'color' => $this->colorToArgb($color),
            'outline' => true,
            'line_width' => $lineWidth,
            'z' => $this->nextZ(),
        ]);
    }

    public function drawRoundedRect(float $x, float $y, float $w, float $h, float $radius, Color $color): void
    {
        vio_rounded_rect($this->ctx, $x, $y, $w, $h, $radius, ['color' => $this->colorToArgb($color), 'z' => $this->nextZ()]);
    }

    public function drawRoundedRectOutline(float $x, float $y, float $w, float $h, float $radius, Color $color, float $lineWidth = 1.0): void
    {
        vio_rounded_rect($this->ctx, $x, $y, $w, $h, $radius, [
            'color' => $this->colorToArgb($color),
            'outline' => true,
            'line_width' => $lineWidth,
            'z' => $this->nextZ(),
        ]);
    }

    public function drawCircle(float $cx, float $cy, float $r, Color $color): void
    {
        vio_circle($this->ctx, $cx, $cy, $r, ['color' => $this->colorToArgb($color), 'z' => $this->nextZ()]);
    }

    public function drawCircleOutline(float $cx, float $cy, float $r, Color $color, float $lineWidth = 1.0): void
    {
        vio_circle($this->ctx, $cx, $cy, $r, [
            'color' => $this->colorToArgb($color),
            'outline' => true,
            'line_width' => $lineWidth,
            'z' => $this->nextZ(),
        ]);
    }

    public function drawLine(Vec2 $from, Vec2 $to, Color $color, float $width = 1.0): void
    {
        vio_line($this->ctx, $from->x, $from->y, $to->x, $to->y, [
            'color' => $this->colorToArgb($color),
            'width' => $width,
            'z' => $this->nextZ(),
        ]);
    }

    public function drawText(string $text, float $x, float $y, float $size, Color $color): void
    {
        $font = $this->resolveFont($size);
        if ($font === null) {
            return;
        }
        vio_text($this->ctx, $font, $text, $x, $y, ['color' => $this->colorToArgb($color), 'z' => $this->nextZ()]);
    }

    public function drawTextCentered(string $text, float $cx, float $cy, float $size, Color $color): void
    {
        $font = $this->resolveFont($size);
        if ($font === null) {
            return;
        }
        $metrics = vio_text_measure($font, $text);
        $x = $cx - $metrics['width'] / 2.0;
        $y = $cy - $metrics['height'] / 2.0;
        vio_text($this->ctx, $font, $text, $x, $y, ['color' => $this->colorToArgb($color), 'z' => $this->nextZ()]);
    }

    public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, Color $color): void
    {
        $font = $this->resolveFont($size);
        if ($font === null) {
            return;
        }
        $argb = $this->colorToArgb($color);
        $z = $this->nextZ();
        $words = explode(' ', $text);
        $line = '';
        $lineY = $y;
        $lineHeight = $size * 1.2;

        foreach ($words as $word) {
            $testLine = $line === '' ? $word : $line . ' ' . $word;
            $metrics = vio_text_measure($font, $testLine);
            if ($metrics['width'] > $breakWidth && $line !== '') {
                vio_text($this->ctx, $font, $line, $x, $lineY, ['color' => $argb, 'z' => $z]);
                $lineY += $lineHeight;
                $line = $word;
            } else {
                $line = $testLine;
            }
        }
        if ($line !== '') {
            vio_text($this->ctx, $font, $line, $x, $lineY, ['color' => $argb, 'z' => $z]);
        }
    }

    public function drawSprite(Texture $texture, ?Rect $srcRegion, float $x, float $y, float $w, float $h, float $opacity = 1.0): void
    {
        $vioTex = $this->getVioTexture($texture);
        if ($vioTex === null) {
            return;
        }

        $options = [
            'x' => $x,
            'y' => $y,
            'width' => $w,
            'height' => $h,
            'color' => $this->colorToArgb(new Color(1.0, 1.0, 1.0, $opacity)),
            'z' => $this->nextZ(),
        ];

        if ($srcRegion !== null) {
            $options['src_x'] = $srcRegion->x;
            $options['src_y'] = $srcRegion->y;
            $options['src_w'] = $srcRegion->width;
            $options['src_h'] = $srcRegion->height;
        }

        vio_sprite($this->ctx, $vioTex, $options);
    }

    public function pushTransform(Mat3 $matrix): void
    {
        $m = $matrix->toArray();
        // Mat3 column-major: [0]=m00, [1]=m10, [2]=m20, [3]=m01, [4]=m11, [5]=m21, [6]=m02, [7]=m12, [8]=m22
        // vio affine [a,b,c,d,e,f]: | a b e |  =>  a=m00, b=m01, c=m10, d=m11, e=m02(tx), f=m12(ty)
        //                           | c d f |
        vio_push_transform($this->ctx, $m[0], $m[3], $m[1], $m[4], $m[6], $m[7]);
    }

    public function popTransform(): void
    {
        vio_pop_transform($this->ctx);
    }

    public function pushScissor(float $x, float $y, float $w, float $h): void
    {
        vio_push_scissor($this->ctx, $x, $y, $w, $h);
    }

    public function popScissor(): void
    {
        vio_pop_scissor($this->ctx);
    }

    public function loadFont(string $name, string $path): void
    {
        $this->fontPaths[$name] = $path;
    }

    public function setFont(string $name): void
    {
        $this->currentFontName = $name;
    }

    public function setTextAlign(int $align): void
    {
        $this->textAlign = $align;
    }

    public function measureText(string $text, float $size): TextMetrics
    {
        $font = $this->resolveFont($size);
        if ($font === null) {
            // Estimate based on size if no font is loaded
            return new TextMetrics(strlen($text) * $size * 0.6, $size);
        }
        $metrics = vio_text_measure($font, $text);
        return new TextMetrics((float)$metrics['width'], (float)$metrics['height']);
    }

    public function measureTextBox(string $text, float $breakWidth, float $size): TextMetrics
    {
        $font = $this->resolveFont($size);
        if ($font === null) {
            return new TextMetrics($breakWidth, $size);
        }

        // Word-wrap and measure each line, same algorithm as drawTextBox
        $words = explode(' ', $text);
        $line = '';
        $lineHeight = $size * 1.2;
        $maxWidth = 0.0;
        $totalHeight = 0.0;

        foreach ($words as $word) {
            $testLine = $line === '' ? $word : $line . ' ' . $word;
            $metrics = vio_text_measure($font, $testLine);
            if ($metrics['width'] > $breakWidth && $line !== '') {
                $lineMetrics = vio_text_measure($font, $line);
                $maxWidth = max($maxWidth, (float)$lineMetrics['width']);
                $totalHeight += $lineHeight;
                $line = $word;
            } else {
                $line = $testLine;
            }
        }
        if ($line !== '') {
            $lineMetrics = vio_text_measure($font, $line);
            $maxWidth = max($maxWidth, (float)$lineMetrics['width']);
            $totalHeight += $lineHeight;
        }

        return new TextMetrics($maxWidth, $totalHeight);
    }

    public function addFallbackFont(string $baseFont, string $fallbackFont): void
    {
        // VIO does not currently support fallback fonts at runtime.
        // This is a no-op; CJK support must be handled by loading
        // a combined font file for the VIO backend.
    }

    public function setGlobalAlpha(float $alpha): void
    {
        $this->globalAlpha = $alpha;
    }

    public function drawArc(float $cx, float $cy, float $r, float $startAngle, float $endAngle, Color $color, int $direction = 0): void
    {
        // Approximate the arc as a filled polygon using line segments
        $segments = max(16, (int)(abs($endAngle - $startAngle) / (M_PI * 2) * 64));
        $step = ($endAngle - $startAngle) / $segments;
        if ($direction === 1) {
            // CW: swap direction
            $step = -$step;
        }

        // Draw as a series of triangles from center
        $argb = $this->colorToArgb($color);
        $z = $this->nextZ();
        for ($i = 0; $i < $segments; $i++) {
            $a1 = $startAngle + $step * $i;
            $a2 = $startAngle + $step * ($i + 1);
            $x1 = $cx + cos($a1) * $r;
            $y1 = $cy + sin($a1) * $r;
            $x2 = $cx + cos($a2) * $r;
            $y2 = $cy + sin($a2) * $r;
            // Draw as two lines forming a triangle sector (visual approximation)
            vio_line($this->ctx, $x1, $y1, $x2, $y2, ['color' => $argb, 'width' => 1.0, 'z' => $z]);
        }
    }

    public function saveState(): void
    {
        $this->stateStack[] = [$this->currentFontName, $this->textAlign, $this->globalAlpha];
        // Also push transform/scissor state in VIO
        vio_push_transform($this->ctx, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0);
    }

    public function restoreState(): void
    {
        if (!empty($this->stateStack)) {
            [$this->currentFontName, $this->textAlign, $this->globalAlpha] = array_pop($this->stateStack);
        }
        vio_pop_transform($this->ctx);
    }

    public function registerVioTexture(int $textureId, VioTexture $vioTexture): void
    {
        $this->vioTextures[$textureId] = $vioTexture;
    }

    public function getContext(): VioContext
    {
        return $this->ctx;
    }

    private function resolveFont(float $size): ?VioFont
    {
        if ($this->currentFontName === '' || !isset($this->fontPaths[$this->currentFontName])) {
            return null;
        }

        $roundedSize = (float)(int)$size;
        $key = $this->currentFontName . ':' . (int)$roundedSize;

        if (!isset($this->fontCache[$key])) {
            $font = vio_font($this->ctx, $this->fontPaths[$this->currentFontName], $roundedSize);
            if ($font === false) {
                return null;
            }
            $this->fontCache[$key] = $font;
        }

        return $this->fontCache[$key];
    }

    private function getVioTexture(Texture $texture): ?VioTexture
    {
        return $this->vioTextures[$texture->glId] ?? null;
    }

    private function colorToArgb(Color $color): int
    {
        $a = ((int)($color->a * 255)) & 0xFF;
        $r = ((int)($color->r * 255)) & 0xFF;
        $g = ((int)($color->g * 255)) & 0xFF;
        $b = ((int)($color->b * 255)) & 0xFF;
        return ($a << 24) | ($r << 16) | ($g << 8) | $b;
    }
}
