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

    /** @var array<string, \VioFont> Cache key "name:size" -> VioFont */
    private array $fontCache = [];

    public function clearFontCache(): void
    {
        $this->fontCache = [];
    }

    /** @var array<int, VioTexture> Texture glId/objectId -> VioTexture */
    private array $vioTextures = [];

    /** @var array<string, list<string>> Base font name -> list of fallback font names */
    private array $fallbackFonts = [];

    /** @var array<string, array<string, mixed>> Memoized vio_text_measure results, keyed by font-object-id|text */
    private array $measureCache = [];

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

    /**
     * Active off-screen target while in warm-render mode. Holding the wrapper
     * as a property (instead of a local) keeps the underlying VioRenderTarget
     * alive across beginFrame/endFrame calls inside the warm callback — PHP
     * would otherwise free the GPU resource at the end of a single bind.
     *
     * Non-null between beginOffscreenFrame() and endOffscreenFrame(); inside
     * that window every beginFrame() rebinds the target and every endFrame()
     * keeps it bound. The pair is closed in endOffscreenFrame().
     */
    private ?VioOffscreenTarget $offscreenWarmTarget = null;

    /**
     * Cached offscreen dimensions for the warm window. Drives the viewport on
     * each redirected beginFrame() and is also reported by getWidth()/getHeight()
     * so the callback sees a coherent target size.
     */
    private int $offscreenWarmWidth = 0;
    private int $offscreenWarmHeight = 0;

    public function beginFrame(): void
    {
        if ($this->offscreenWarmTarget !== null) {
            // Redirected path: route this frame's draws into the warm-up
            // offscreen target instead of the swapchain. Window-size sync is
            // skipped — getWidth/getHeight already reflect the warm dimensions
            // (set by beginOffscreenFrame()) and that's the value the callback
            // should see.
            $w = $this->offscreenWarmWidth;
            $h = $this->offscreenWarmHeight;

            vio_viewport($this->ctx, 0, 0, $w, $h);
            $this->offscreenWarmTarget->bindForDraw();
            vio_begin($this->ctx);
            vio_viewport($this->ctx, 0, 0, $w, $h);

            $this->zCounter = 0.0;
            vio_clear($this->ctx, 0.0, 0.0, 0.0, 1.0);
            return;
        }

        // Sync dimensions from vio context (handles window resize/maximize)
        $size = vio_window_size($this->ctx);
        $this->width = $size[0];
        $this->height = $size[1];

        $fb = vio_framebuffer_size($this->ctx);

        // Set viewport before AND after vio_begin:
        // - D3D11 needs it before begin (render target binding)
        // - D3D12 needs it after begin (part of command list recording)
        // - OpenGL/Metal/Vulkan: harmless duplicate
        vio_viewport($this->ctx, 0, 0, $fb[0], $fb[1]);
        vio_begin($this->ctx);
        vio_viewport($this->ctx, 0, 0, $fb[0], $fb[1]);

        $this->zCounter = 0.0;

        vio_clear($this->ctx, 0.0, 0.0, 0.0, 1.0);
    }

    public function endFrame(): void
    {
        vio_draw_2d($this->ctx);
        vio_end($this->ctx);

        // In warm-render mode, unbind so the swapchain is the default target
        // again — if endOffscreenFrame() never runs (callback never invoked
        // beginFrame/endFrame), the target is released by endOffscreenFrame
        // itself. Each beginFrame() inside the warm callback rebinds.
        if ($this->offscreenWarmTarget !== null) {
            $this->offscreenWarmTarget->unbind();
        }
    }

    public function beginOffscreenFrame(int $width, int $height): void
    {
        $width  = max(1, $width);
        $height = max(1, $height);

        // Allocate a fresh target every call. warmRender() callers expect the
        // GPU resource to be released after endOffscreenFrame() so the next
        // real frame isn't competing with a stale offscreen image for memory.
        $target = new VioOffscreenTarget($this->ctx);
        $target->resize($width, $height, 1);
        if (!$target->isAllocated()) {
            // Allocation failed — leave the warm target null so beginFrame()
            // / endFrame() inside the callback fall through to the regular
            // swapchain path. We accept the visible flash because we couldn't
            // honour the offscreen contract anyway.
            $this->offscreenWarmTarget = null;
            return;
        }

        $this->offscreenWarmTarget = $target;
        $this->offscreenWarmWidth  = $width;
        $this->offscreenWarmHeight = $height;

        // Mirror beginFrame()'s logical-size sync so widgets that read
        // getWidth()/getHeight() see the offscreen dimensions, not the
        // swapchain's. Restored in endOffscreenFrame().
        $this->width  = $width;
        $this->height = $height;
    }

    public function endOffscreenFrame(): void
    {
        if ($this->offscreenWarmTarget === null) {
            // beginOffscreenFrame() failed to allocate; nothing to release.
            // Still resync dimensions in case the caller polluted them.
            $size = vio_window_size($this->ctx);
            $this->width  = $size[0];
            $this->height = $size[1];
            return;
        }

        $this->offscreenWarmTarget->unbind();
        $this->offscreenWarmTarget->release();
        $this->offscreenWarmTarget = null;
        $this->offscreenWarmWidth  = 0;
        $this->offscreenWarmHeight = 0;

        // Resync logical dimensions to the actual window — warmRender() may be
        // followed by real frames immediately, and those expect getWidth()
        // /getHeight() to track the swapchain.
        $size = vio_window_size($this->ctx);
        $this->width  = $size[0];
        $this->height = $size[1];
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

        // If no fallbacks, or the text is plain Latin/Western (the only thing
        // the configured CJK fallback fonts could add is Han/Hangul glyphs),
        // we're done. This skips the per-glyph coverage scan AND the extra
        // vio_text draws through the large CJK fallback fonts — which cost
        // ~40 ms/frame on a HUD full of Latin text.
        if (count($chain) <= 1 || !self::textNeedsFallback($text)) {
            return;
        }

        // Check if primary covers everything
        $primaryW = (float)$this->measureCached($primary, $text)['width'];
        $chainW = 0.0;
        foreach ($chain as $font) {
            $w = (float)$this->measureCached($font, $text)['width'];
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
                $pw = (float)$this->measureCached($primary, $ch)['width'];
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
        $lineHeight = $size * 1.2;
        // vio_text renders from baseline — offset each line by ascender
        $ascender = $size * 0.65;
        $lineY = $y + $ascender;

        // Split on hard line breaks first so explicit \n in the source forces
        // a new line. Word-wrap then runs *within* each paragraph. Without this
        // step "10 LET X = 5\n20 LET Y = X + 3" would render as one long line
        // because explode(' ', ...) preserves \n inside the resulting tokens.
        $paragraphs = preg_split('/\r\n?|\n/', $text);
        if ($paragraphs === false) {
            $paragraphs = [$text];
        }

        foreach ($paragraphs as $paragraph) {
            if ($paragraph === '') {
                // Empty paragraph (e.g. consecutive \n\n) advances by one
                // line height to produce a visible blank line.
                $lineY += $lineHeight;
                continue;
            }

            $words = explode(' ', $paragraph);
            $line = '';

            foreach ($words as $word) {
                $testLine = $line === '' ? $word : $line . ' ' . $word;
                $metrics = $this->measureCached($font, $testLine);
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
                $lineY += $lineHeight;
            }
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
     * @param list<\VioFont> $chain
     */
    /**
     * Memoized {@see vio_text_measure}. UI/HUD text re-measures the same
     * strings every frame across the whole font fallback chain — including
     * the large CJK fallback fonts (noto-sans-sc/kr) — which dominated the
     * 2D frame cost (~120 ms with the HUD up). Fonts are immutable and cached
     * per (name, size), so the font object id + text is a stable cache key.
     *
     * @return array<string, mixed>
     */
    private function measureCached(mixed $font, string $text): array
    {
        if (!is_object($font)) {
            return vio_text_measure($font, $text);
        }
        $key = \spl_object_id($font) . '|' . $text;
        return $this->measureCache[$key] ??= vio_text_measure($font, $text);
    }

    /**
     * Whether $text contains any codepoint outside the Latin/Western range
     * the primary UI font already covers. The only fallback fonts wired up
     * are CJK (noto-sans-sc/kr), so plain Western text never needs the chain
     * — and skipping it avoids the dominant per-frame HUD text cost.
     */
    private static function textNeedsFallback(string $text): bool
    {
        return (bool) preg_match('/[\x{0500}-\x{10FFFF}]/u', $text);
    }

    private function measureTextWithChain(array $chain, string $text): TextMetrics
    {
        if (count($chain) > 1 && !self::textNeedsFallback($text)) {
            $chain = [$chain[0]];
        }
        $maxW = 0.0;
        $maxH = 0.0;
        foreach ($chain as $font) {
            $m = $this->measureCached($font, $text);
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

        // Word-wrap and measure each line, same algorithm as drawTextBox.
        // Must honour explicit \n breaks so measureTextBox() agrees with what
        // drawTextBox() actually renders. Empty paragraphs contribute one
        // lineHeight just like an empty visible line would.
        $lineHeight = $size * 1.2;
        $maxWidth = 0.0;
        $totalHeight = 0.0;

        $paragraphs = preg_split('/\r\n?|\n/', $text);
        if ($paragraphs === false) {
            $paragraphs = [$text];
        }

        foreach ($paragraphs as $paragraph) {
            if ($paragraph === '') {
                $totalHeight += $lineHeight;
                continue;
            }

            $words = explode(' ', $paragraph);
            $line = '';

            foreach ($words as $word) {
                $testLine = $line === '' ? $word : $line . ' ' . $word;
                $metrics = $this->measureCached($font, $testLine);
                if ($metrics['width'] > $breakWidth && $line !== '') {
                    $lineMetrics = $this->measureCached($font, $line);
                    $maxWidth = max($maxWidth, (float)$lineMetrics['width']);
                    $totalHeight += $lineHeight;
                    $line = $word;
                } else {
                    $line = $testLine;
                }
            }
            if ($line !== '') {
                $lineMetrics = $this->measureCached($font, $line);
                $maxWidth = max($maxWidth, (float)$lineMetrics['width']);
                $totalHeight += $lineHeight;
            }
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
            $metrics = $this->measureCached($font, $text);
            return $x + ($boxWidth - (float)$metrics['width']) / 2.0;
        }
        if ($align & TextAlign::RIGHT) {
            $metrics = $this->measureCached($font, $text);
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
     * @return list<\VioFont>
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
