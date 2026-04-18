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

    public function clearFontCache(): void
    {
        $this->fontCache = [];
    }

    /** @var array<int, VioTexture> Texture glId/objectId -> VioTexture */
    private array $vioTextures = [];

    /** @var array<string, list<string>> Base font name -> list of fallback font names */
    private array $fallbackFonts = [];

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

        // Set viewport to framebuffer size (required for D3D11/D3D12 on Windows)
        $fb = vio_framebuffer_size($this->ctx);
        vio_viewport($this->ctx, 0, 0, $fb[0], $fb[1]);

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
        $chain = $this->resolveFontChain($size);
        if (empty($chain)) {
            return;
        }

        $tm = $this->measureTextWithChain($chain, $text);
        $tw = $tm->width;
        $th = $tm->height;
        $ascender = $th * 0.65;
        $align = $this->textAlign;

        if ($align & TextAlign::CENTER) {
            $x -= $tw / 2.0;
        } elseif ($align & TextAlign::RIGHT) {
            $x -= $tw;
        }

        if ($align & TextAlign::TOP) {
            $y += $ascender;
        } elseif ($align & TextAlign::MIDDLE) {
            $y += $ascender - $th / 2.0;
        } elseif ($align & TextAlign::BOTTOM) {
            $y += $ascender - $th;
        }

        $this->drawTextWithChain($chain, $text, $x, $y, $this->colorToArgb($color), $this->nextZ());
    }

    public function drawTextCentered(string $text, float $cx, float $cy, float $size, Color $color): void
    {
        $chain = $this->resolveFontChain($size);
        if (empty($chain)) {
            return;
        }
        $tm = $this->measureTextWithChain($chain, $text);
        $th = $tm->height;
        $ascender = $th * 0.65;
        $x = $cx - $tm->width / 2.0;
        $y = $cy + $ascender - $th / 2.0;

        $this->drawTextWithChain($chain, $text, $x, $y, $this->colorToArgb($color), $this->nextZ());
    }

    /**
     * Render text using the font chain. Primary font renders first;
     * fallback fonts only render characters the primary doesn't cover.
     * @param list<\VioFont> $chain
     */
    private function drawTextWithChain(array $chain, string $text, float $x, float $y, int $argb, float $z): void
    {
        $primary = $chain[0];
        vio_text($this->ctx, $primary, $text, $x, $y, ['color' => $argb, 'z' => $z]);

        // If no fallbacks, done
        if (count($chain) <= 1) {
            return;
        }

        // Check if primary covers everything
        $primaryW = (float)vio_text_measure($primary, $text)['width'];
        $chainW = 0.0;
        foreach ($chain as $font) {
            $w = (float)vio_text_measure($font, $text)['width'];
            if ($w > $chainW) { $chainW = $w; }
        }
        if ($primaryW >= $chainW - 0.01) {
            return; // Primary covers all glyphs
        }

        // Build per-character font assignments: find chars primary can't render
        $len = mb_strlen($text);
        for ($i = 1; $i < count($chain); $i++) {
            $fb = $chain[$i];
            $fbText = '';
            for ($c = 0; $c < $len; $c++) {
                $ch = mb_substr($text, $c, 1);
                $pw = (float)vio_text_measure($primary, $ch)['width'];
                if ($pw > 0.001) {
                    // Primary has this glyph — insert a space so fallback advances cursor
                    $fbText .= ' ';
                } else {
                    $fbText .= $ch;
                }
            }
            vio_text($this->ctx, $fb, $fbText, $x, $y, ['color' => $argb, 'z' => $z]);
        }
    }

    public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, Color $color): void
    {
        $font = $this->resolveFont($size);
        if ($font === null) {
            return;
        }
        $argb = $this->colorToArgb($color);
        $z = $this->nextZ();
        $align = $this->textAlign;
        $words = explode(' ', $text);
        $line = '';
        $lineHeight = $size * 1.2;
        // vio_text renders from baseline — offset each line by ascender
        $ascender = $size * 0.65;
        $lineY = $y + $ascender;

        foreach ($words as $word) {
            $testLine = $line === '' ? $word : $line . ' ' . $word;
            $metrics = vio_text_measure($font, $testLine);
            if ($metrics['width'] > $breakWidth && $line !== '') {
                $lx = $this->alignTextX($font, $line, $x, $breakWidth, $align);
                vio_text($this->ctx, $font, $line, $lx, $lineY, ['color' => $argb, 'z' => $z]);
                $lineY += $lineHeight;
                $line = $word;
            } else {
                $line = $testLine;
            }
        }
        if ($line !== '') {
            $lx = $this->alignTextX($font, $line, $x, $breakWidth, $align);
            vio_text($this->ctx, $font, $line, $lx, $lineY, ['color' => $argb, 'z' => $z]);
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
        $chain = $this->resolveFontChain($size);
        if (empty($chain)) {
            return new TextMetrics(mb_strlen($text) * $size * 0.6, $size);
        }
        return $this->measureTextWithChain($chain, $text);
    }

    /**
     * Measure text width using the font chain. Takes the max width across
     * all fonts (each font contributes width for glyphs it has, skips missing).
     * @param list<VioFont> $chain
     */
    private function measureTextWithChain(array $chain, string $text): TextMetrics
    {
        $maxW = 0.0;
        $maxH = 0.0;
        foreach ($chain as $font) {
            $m = vio_text_measure($font, $text);
            $w = (float)$m['width'];
            $h = (float)$m['height'];
            if ($w > $maxW) { $maxW = $w; }
            if ($h > $maxH) { $maxH = $h; }
        }
        return new TextMetrics($maxW, $maxH);
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
        if (!isset($this->fallbackFonts[$baseFont]) || !in_array($fallbackFont, $this->fallbackFonts[$baseFont], true)) {
            $this->fallbackFonts[$baseFont][] = $fallbackFont;
        }
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

    /**
     * Adjust X position for horizontal text alignment within a text box.
     */
    private function alignTextX(VioFont $font, string $text, float $x, float $boxWidth, int $align): float
    {
        if ($align & TextAlign::CENTER) {
            $metrics = vio_text_measure($font, $text);
            return $x + ($boxWidth - (float)$metrics['width']) / 2.0;
        }
        if ($align & TextAlign::RIGHT) {
            $metrics = vio_text_measure($font, $text);
            return $x + $boxWidth - (float)$metrics['width'];
        }
        return $x;
    }

    private function resolveFont(float $size): ?VioFont
    {
        return $this->resolveFontByName($this->currentFontName, $size);
    }

    private function resolveFontByName(string $name, float $size): ?VioFont
    {
        if ($name === '' || !isset($this->fontPaths[$name])) {
            return null;
        }

        $roundedSize = (float)(int)$size;
        $key = $name . ':' . (int)$roundedSize;

        if (!isset($this->fontCache[$key])) {
            $font = vio_font($this->ctx, $this->fontPaths[$name], $roundedSize);
            if ($font === false) {
                return null;
            }
            $this->fontCache[$key] = $font;
        }

        return $this->fontCache[$key];
    }

    /**
     * Get the primary font plus all registered fallback fonts for the current font.
     * @return list<VioFont>
     */
    private function resolveFontChain(float $size): array
    {
        $primary = $this->resolveFont($size);
        if ($primary === null) {
            return [];
        }
        $chain = [$primary];
        $fallbacks = $this->fallbackFonts[$this->currentFontName] ?? [];
        foreach ($fallbacks as $fbName) {
            $fb = $this->resolveFontByName($fbName, $size);
            if ($fb !== null) {
                $chain[] = $fb;
            }
        }
        return $chain;
    }

    private function getVioTexture(Texture $texture): ?VioTexture
    {
        return $this->vioTextures[$texture->glId] ?? null;
    }

    private function colorToArgb(Color $color): int
    {
        $a = ((int)($color->a * $this->globalAlpha * 255)) & 0xFF;
        $r = ((int)($color->r * 255)) & 0xFF;
        $g = ((int)($color->g * 255)) & 0xFF;
        $b = ((int)($color->b * 255)) & 0xFF;
        return ($a << 24) | ($r << 16) | ($g << 8) | $b;
    }
}
