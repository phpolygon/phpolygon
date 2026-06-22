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

    /**
     * Font names registered for asynchronous (background-thread) loading via
     * {@see preloadFontAsync()}. When such a font is first needed at a given
     * size, the renderer kicks off `vio_font_load_async()` instead of blocking
     * on `vio_font()` — the render thread keeps drawing (the font is simply
     * skipped in the fallback chain) until the worker thread finishes packing
     * the atlas and {@see pollAsyncFontLoads()} promotes it into $fontCache.
     *
     * Used for the large CJK fallback fonts (NotoSansSC/KR, ~13 MB / ~32k
     * glyphs) whose synchronous pack froze the render thread for 20-25 s the
     * first time a CJK glyph hit the chain.
     *
     * @var array<string, true> Font name -> registered flag
     */
    private array $asyncFontNames = [];

    /**
     * In-flight async font loads, keyed by the same "name:size" cache key used
     * by {@see $fontCache}. Holds the opaque resource handle returned by
     * `vio_font_load_async()`; {@see pollAsyncFontLoads()} polls each one and,
     * once ready, moves the resulting VioFont into $fontCache and clears the
     * entry here. A key present here (but not in $fontCache) means "loading —
     * skip this font for now".
     *
     * @var array<string, resource>
     */
    private array $pendingFontLoads = [];

    /**
     * Memoized vio_text_measure results, keyed by font-object-id|text.
     *
     * Bounded with FIFO eviction once {@see $measureCacheCap} is exceeded. The
     * cap exists because UI/HUD text (date strings, money counters, project
     * timers) feeds new keys every frame; an unbounded map produced GC and
     * hashtable-resize stalls in the v0.17.1 perf regression (Code Tycoon p95
     * 1-2 ms -> 10-13 ms across all panels). PHP arrays keep insertion order,
     * so the first key is always the oldest entry — array_key_first + unset is
     * O(1) and cheaper than array_shift.
     *
     * @var array<string, array{width: float, height: float}>
     */
    private array $measureCache = [];

    /**
     * Maximum number of entries the {@see $measureCache} keeps before evicting
     * the oldest entry. Sourced from {@see \PHPolygon\EngineConfig::$textMeasureCacheCap}
     * when the renderer is built by the engine, defaulting to 4096 — which
     * comfortably holds the stable strings of a panel-rich HUD while still
     * bounding worst-case memory usage.
     */
    private int $measureCacheCap = 4096;

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
        int $measureCacheCap = 4096,
    ) {
        $this->width = 1280;
        $this->height = 720;
        // Guard against pathological configs: a cap of 0 would disable caching
        // entirely (defeating the original purpose); a negative cap would never
        // trigger eviction. Clamp to >=1 so the cache always serves at least
        // one in-flight string and the FIFO eviction path stays well-defined.
        $this->measureCacheCap = max(1, $measureCacheCap);
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
        // Complete any background font loads whose worker thread has finished.
        // The GPU upload happens here, on the render thread, with a current
        // GL/Metal/D3D/Vulkan context — exactly where the engine already polls
        // its async work each frame. Cheap no-op when nothing is in flight.
        $this->pollAsyncFontLoads();

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
        \PHPolygon\Runtime\PerfProfiler::begin('render2d.draw2d');
        vio_draw_2d($this->ctx);
        \PHPolygon\Runtime\PerfProfiler::end();
        \PHPolygon\Runtime\PerfProfiler::begin('render2d.vio_end');
        vio_end($this->ctx);
        \PHPolygon\Runtime\PerfProfiler::end();

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
     * Assign each character of a string to exactly one font in the chain and
     * compute the explicit x-offset at which that character must be drawn.
     *
     * Pure coverage/claim/positioning logic, extracted from {@see
     * drawTextWithChain} so it can be unit-tested without a live VioContext.
     * Returns one draw op per contiguous run of characters claimed by the same
     * fallback font, each carrying the *absolute* x at which that run begins
     * (relative to the text origin), so the engine can render every fallback
     * glyph at exactly the position the primary pen would have reached.
     *
     * Two bugs are fixed here, both invisible for pure-Latin text:
     *
     *  1. CJK double-draw (v0.22.0). A glyph present in two fallback fonts
     *     (e.g. a Han char shared by noto-sans-sc and noto-sans-kr) used to be
     *     emitted into *every* covering fallback and overprinted. Now each
     *     character is claimed by the *earliest* font in the chain that covers
     *     it and drawn exactly once.
     *
     *  2. Mixed-string mis-positioning (v0.22.1). The previous implementation
     *     padded each fallback run with substituted spaces and drew the whole
     *     run at the text origin, relying on the *fallback* font's space advance
     *     to skip over primary-covered characters. Because the fallback
     *     (NotoSansSC/KR) space advance differs from the primary (Inter)
     *     per-glyph advances, CJK glyphs in a mixed string such as "Save 接受"
     *     landed at the wrong x — offset by SC-space widths for "Save " instead
     *     of Inter's real width of "Save ". Now each run's x is computed from
     *     the *primary* font's measured width of the preceding substring (via
     *     $prefixWidth), so it matches the primary pen exactly. No spaces are
     *     substituted at all.
     *
     * Claim rules per character (unchanged from the double-draw fix):
     *  - Covered by the primary (index 0)  -> claimed by primary; emits no
     *    fallback draw (the primary's full-string draw already rendered it).
     *  - Otherwise the first fallback index i (i >= 1) whose $covers(i, ch) is
     *    true claims it and draws the glyph.
     *  - Covered by no font in the chain    -> no fallback draw; the primary's
     *    full-string draw renders the .notdef box (unchanged behaviour).
     *
     * Contiguous characters claimed by the *same* fallback font are merged into
     * one run/draw. Within such a run the fallback font's own advances position
     * the glyphs relative to each other correctly; only the run's *starting* x
     * needs to come from the primary measurement, which is what we anchor here.
     *
     * @param list<string>                 $chars      ordered single characters
     * @param int                          $chainSize  total fonts in the chain (>= 1)
     * @param callable(int, string): bool  $covers     fn($fontIndex, $char): bool —
     *                                                 does the font at $fontIndex have
     *                                                 a glyph for $char?
     * @param callable(int): float         $prefixWidth fn($charCount): float —
     *                                                 the primary font's measured
     *                                                 width of the first $charCount
     *                                                 characters of the string. Used
     *                                                 to anchor each fallback run to
     *                                                 the primary pen position.
     * @return list<array{font: int, text: string, x: float}> draw ops, in order,
     *         each rendering $text with chain font $font at offset $x from origin
     */
    private static function planFallbackDraws(
        array $chars,
        int $chainSize,
        callable $covers,
        callable $prefixWidth,
    ): array {
        if ($chainSize <= 1) {
            return [];
        }

        // First pass: collect contiguous runs of characters claimed by the same
        // fallback font as {font, startIndex, text} tuples. A run breaks when
        // the claiming font changes or a character is claimed by the primary /
        // no font (those need no fallback draw). Kept as a plain loop with no
        // by-reference closure so the run state stays a simple, analysable value.
        /** @var list<array{font: int, start: int, text: string}> $runs */
        $runs = [];
        foreach ($chars as $index => $ch) {
            // Find the first font in the chain (primary included) that covers
            // this char; that font "claims" it.
            $claimedBy = -1;
            for ($i = 0; $i < $chainSize; $i++) {
                if ($covers($i, $ch)) {
                    $claimedBy = $i;
                    break;
                }
            }

            // Primary-covered (0) or uncovered (-1): no fallback draw, and it
            // breaks any open run.
            if ($claimedBy < 1) {
                continue;
            }

            $lastRun = $runs === [] ? null : $runs[count($runs) - 1];
            if (
                $lastRun !== null
                && $lastRun['font'] === $claimedBy
                && $lastRun['start'] + mb_strlen($lastRun['text']) === $index
            ) {
                // Contiguous extension of the current run.
                $runs[count($runs) - 1]['text'] .= $ch;
                continue;
            }

            // Start a fresh run anchored at this character's index.
            $runs[] = ['font' => $claimedBy, 'start' => $index, 'text' => $ch];
        }

        // Second pass: anchor each run at the primary pen position — the
        // primary font's measured width of the substring before the run.
        $draws = [];
        foreach ($runs as $run) {
            $draws[] = [
                'font' => $run['font'],
                'text' => $run['text'],
                'x' => $prefixWidth($run['start']),
            ];
        }

        return $draws;
    }

    /**
     * Render text using the font chain. Primary font renders the whole string
     * first; each remaining character is then drawn by exactly one fallback
     * font — the earliest one in the chain that covers it — at the explicit x
     * the primary pen reached (see {@see planFallbackDraws}).
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
        $chainSize = count($chain);
        if ($chainSize <= 1 || !self::textNeedsFallback($text)) {
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

        // Build per-character claims. A char is "covered" by a font when that
        // font reports a non-zero advance for it on its own. This is memoised
        // through measureCached, so each (font, char) probe is paid at most once.
        $chars = mb_str_split($text);
        $covers = fn (int $fontIndex, string $ch): bool
            => (float)$this->measureCached($chain[$fontIndex], $ch)['width'] > 0.001;

        // Each fallback run is anchored at the *primary* font's width of the
        // preceding substring — the exact x the primary draw left the pen at.
        // Memoised through measureCached, so repeated prefixes (a run boundary
        // measures the same prefix once) cost nothing extra.
        $prefixWidth = function (int $charCount) use ($primary, $chars, $x): float {
            if ($charCount <= 0) {
                return $x;
            }
            $prefix = implode('', array_slice($chars, 0, $charCount));
            return $x + (float)$this->measureCached($primary, $prefix)['width'];
        };

        $draws = self::planFallbackDraws($chars, $chainSize, $covers, $prefixWidth);
        foreach ($draws as $draw) {
            vio_text($this->ctx, $chain[$draw['font']], $draw['text'], $draw['x'], $y, ['color' => $argb, 'z' => $z]);
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

    /**
     * Register a font for background (worker-thread) loading.
     *
     * Behaves like {@see loadFont()} — it records the path so the font can be
     * resolved by name — but additionally marks the font so that the first time
     * it is actually needed at a given size, the renderer rasterizes its glyph
     * atlas on a vio worker thread (`vio_font_load_async`) instead of blocking
     * the render thread (`vio_font`). Until the worker finishes, the font is
     * simply skipped in the fallback chain: text renders without it (the
     * primary font's glyphs and .notdef boxes), then the real glyphs pop in a
     * few frames later once {@see pollAsyncFontLoads()} has promoted the
     * finished atlas into the cache.
     *
     * This exists for the large CJK fallback fonts (NotoSansSC/KR): loading
     * them synchronously on first use froze Code Tycoon's splash for 20-25 s.
     * Register them with this method and add them as fallbacks via
     * {@see addFallbackFont()} exactly as before; everything else is unchanged.
     *
     * No-op-safe: if vio lacks the async font functions (older extension build)
     * the renderer transparently falls back to synchronous loading, so games
     * keep working against any vio version.
     */
    public function preloadFontAsync(string $name, string $path): void
    {
        $this->fontPaths[$name] = $path;
        if (\function_exists('vio_font_load_async') && \function_exists('vio_font_load_poll')) {
            $this->asyncFontNames[$name] = true;
        }
        // If the async API is unavailable we leave $asyncFontNames untouched, so
        // resolveFontByName() takes the normal synchronous vio_font() path.
    }

    /**
     * Poll all in-flight async font loads. For each one whose worker thread has
     * finished, complete it (the GPU upload happens inside vio_font_load_poll)
     * and move the resulting VioFont into the per-size font cache.
     *
     * Called once per frame from {@see beginFrame()}. Safe to call when nothing
     * is pending (returns immediately). Must run on the render thread because
     * the poll performs the atlas GPU upload.
     */
    public function pollAsyncFontLoads(): void
    {
        if ($this->pendingFontLoads === []) {
            return;
        }

        foreach ($this->pendingFontLoads as $key => $handle) {
            $result = vio_font_load_poll($handle);
            if ($result === null) {
                // Still rasterizing on the worker thread — try again next frame.
                continue;
            }
            unset($this->pendingFontLoads[$key]);
            if ($result instanceof VioFont) {
                $this->fontCache[$key] = $result;
            }
            // $result === false: load failed (bad path / parse error). Drop the
            // pending entry so we stop polling; the font stays absent from the
            // chain, identical to a failed synchronous vio_font().
        }
    }

    /**
     * Pre-warm the glyph atlases for every (font, size) pair IN PARALLEL.
     *
     * Each atlas is rasterised on its own vio worker thread
     * (vio_font_load_async) — the CPU-bound glyph packing runs concurrently
     * across cores instead of one-at-a-time. The GPU upload for each finished
     * atlas happens here on the render thread (vio_font_load_poll). Blocks until
     * every requested atlas is cached.
     *
     * Replaces the common "measureText every size to prime the atlas" startup
     * pattern, which is single-threaded and can cost seconds for a 20+ size
     * warm. Falls back to synchronous vio_font() when the async font API is
     * unavailable (older extension build).
     *
     * @param list<string> $names Font names previously registered via loadFont().
     * @param list<float>  $sizes Sizes to pre-rasterise.
     * @param int $maxConcurrent Cap on simultaneously-spawned worker threads
     *                           (0 = sensible default). Bounds thread/memory
     *                           pressure while still saturating the cores.
     */
    public function warmFonts(array $names, array $sizes, int $maxConcurrent = 0): void
    {
        // Build the (key => [path, roundedSize]) work list, skipping anything
        // already cached. Keys match resolveFontByName()'s cache key exactly.
        $work = [];
        foreach ($names as $name) {
            if (!isset($this->fontPaths[$name])) {
                continue;
            }
            $path = $this->fontPaths[$name];
            foreach ($sizes as $size) {
                $rounded = (float)(int)$size;
                $key = $name . ':' . (int)$rounded;
                if (isset($this->fontCache[$key]) || isset($work[$key])) {
                    continue;
                }
                $work[$key] = [$path, $rounded];
            }
        }
        if ($work === []) {
            return;
        }

        $hasAsync = \function_exists('vio_font_load_async') && \function_exists('vio_font_load_poll');
        if (!$hasAsync) {
            foreach ($work as $key => [$path, $rounded]) {
                $font = vio_font($this->ctx, $path, $rounded);
                if ($font instanceof VioFont) {
                    $this->fontCache[$key] = $font;
                }
            }
            return;
        }

        if ($maxConcurrent <= 0) {
            $maxConcurrent = 16;
        }

        $inflight = []; // key => async handle
        while ($work !== [] || $inflight !== []) {
            // Saturate up to the concurrency cap with fresh worker threads.
            while ($work !== [] && \count($inflight) < $maxConcurrent) {
                $key = \array_key_first($work);
                [$path, $rounded] = $work[$key];
                unset($work[$key]);
                $handle = vio_font_load_async($this->ctx, $path, $rounded);
                if ($handle === false) {
                    // Worker spawn failed — load synchronously so the font is
                    // never lost.
                    $font = vio_font($this->ctx, $path, $rounded);
                    if ($font instanceof VioFont) {
                        $this->fontCache[$key] = $font;
                    }
                    continue;
                }
                $inflight[$key] = $handle;
            }

            // Collect finished atlases — each poll performs the GPU upload.
            $progressed = false;
            foreach ($inflight as $key => $handle) {
                $result = vio_font_load_poll($handle);
                if ($result === null) {
                    continue; // still rasterising on its worker thread
                }
                unset($inflight[$key]);
                $progressed = true;
                if ($result instanceof VioFont) {
                    $this->fontCache[$key] = $result;
                }
            }

            // Nothing finished this pass — yield the core briefly rather than
            // busy-spinning while the workers rasterise.
            if (!$progressed && $inflight !== []) {
                usleep(200);
            }
        }
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
     * Memoized {@see vio_text_measure}. UI/HUD text re-measures the same
     * strings every frame across the whole font fallback chain — including
     * the large CJK fallback fonts (noto-sans-sc/kr) — which dominated the
     * 2D frame cost (~120 ms with the HUD up). Fonts are immutable and cached
     * per (name, size), so the font object id + text is a stable cache key.
     *
     * The cache is bounded with FIFO eviction (see {@see $measureCache}).
     * Without this bound the v0.17.1 build leaked p95 frame time on every
     * panel of Code Tycoon (1-2 ms -> 10-13 ms) once dynamic strings — money
     * counters, dates, deadlines — had accumulated enough distinct keys to
     * trigger PHP hashtable resizes mid-frame.
     *
     * @return array{width: float, height: float}
     */
    private function measureCached(\VioFont $font, string $text): array
    {
        $key = \spl_object_id($font) . '|' . $text;
        if (isset($this->measureCache[$key])) {
            return $this->measureCache[$key];
        }

        $value = vio_text_measure($font, $text);
        $this->measureCache[$key] = $value;

        if (\count($this->measureCache) > $this->measureCacheCap) {
            // FIFO eviction — PHP preserves insertion order, so the first key
            // is the oldest entry. Pure FIFO (no LRU promotion) is deliberate:
            // LRU would re-hash on every cache hit, adding load to the very
            // hot path we are trying to relieve. With a 4096-cap and typical
            // HUDs using <100 stable strings, stable text never gets evicted —
            // only the transient money/timer churn does, which is exactly the
            // behaviour we want.
            //
            // The map is non-empty here (we just inserted into it), so
            // array_key_first cannot return null — the constructor clamps
            // measureCacheCap to >= 1.
            unset($this->measureCache[\array_key_first($this->measureCache)]);
        }

        return $value;
    }

    /**
     * Precomputed byte mask for {@see textNeedsFallback}: every byte value
     * from 0xD4 to 0xFF. Building this once at class-load is meaningfully
     * cheaper than the original `preg_match('/[\x{0500}-\x{10FFFF}]/u', ...)`
     * call (benchmarked ~1.3x faster on realistic HUD strings) because
     * strpbrk is a tight C loop with no regex compile and no UTF-8 decode.
     */
    private const FALLBACK_BYTE_MASK =
        "\xD4\xD5\xD6\xD7\xD8\xD9\xDA\xDB\xDC\xDD\xDE\xDF" .
        "\xE0\xE1\xE2\xE3\xE4\xE5\xE6\xE7\xE8\xE9\xEA\xEB" .
        "\xEC\xED\xEE\xEF\xF0\xF1\xF2\xF3\xF4\xF5\xF6\xF7" .
        "\xF8\xF9\xFA\xFB\xFC\xFD\xFE\xFF";

    /**
     * Whether $text contains any codepoint outside the Latin/Western range
     * the primary UI font already covers. The only fallback fonts wired up
     * are CJK (noto-sans-sc/kr), so plain Western text never needs the chain
     * — and skipping it avoids the dominant per-frame HUD text cost.
     *
     * Implemented as a strpbrk byte-scan instead of
     * `preg_match('/[\x{0500}-\x{10FFFF}]/u', $text)` because the PCRE u-flag
     * is expensive and this is called on every single drawText / measureText.
     * The byte-scan is exact for our purposes:
     *
     *   UTF-8 leading-byte ranges by codepoint:
     *     U+0000-U+007F : 0x00-0x7F   (ASCII, 1 byte)
     *     U+0080-U+04FF : 0xC2-0xD3   (Latin/Greek/Cyrillic, 2 bytes)
     *     U+0500-U+07FF : 0xD4-0xDF   (Cyrillic-Supplement and above, 2 bytes)
     *     U+0800-U+FFFF : 0xE0-0xEF   (3 bytes — incl. CJK)
     *     U+10000+      : 0xF0-0xF4   (4 bytes — incl. emoji/CJK-Ext-B)
     *
     *   Continuation bytes are 0x80-0xBF, all below 0xD4.
     *
     * So "any byte >= 0xD4" is equivalent to "any codepoint >= U+0500" — no
     * false positives from ASCII, Latin-1, Latin Extended, IPA, combining
     * marks, Greek, or basic Cyrillic, and no decode work needed.
     *
     * The 2026-06 fix (v0.17.2) replaced the regex here because Code Tycoon's
     * benches register CJK fallbacks (so the regex runs unconditionally) and
     * `preg_match` with the u-flag was dominating per-frame text cost.
     */
    private static function textNeedsFallback(string $text): bool
    {
        return \strpbrk($text, self::FALLBACK_BYTE_MASK) !== false;
    }

    /**
     * Measure text using the font chain, taking the max width/height across
     * all fonts (each contributes for the glyphs it has).
     * @param list<\VioFont> $chain
     */
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

        if (isset($this->fontCache[$key])) {
            return $this->fontCache[$key];
        }

        // Async-registered font: kick off (or wait on) a background load instead
        // of blocking the render thread on the atlas pack. Returns null while
        // the worker is still busy, so the caller skips this font in the chain.
        if (isset($this->asyncFontNames[$name])) {
            if (!isset($this->pendingFontLoads[$key])) {
                $handle = vio_font_load_async($this->ctx, $this->fontPaths[$name], $roundedSize);
                if ($handle === false) {
                    // Couldn't even start the worker (e.g. thread-create
                    // failure). Fall back to a synchronous load so the font
                    // still appears rather than being lost forever.
                    $font = vio_font($this->ctx, $this->fontPaths[$name], $roundedSize);
                    if ($font === false) {
                        return null;
                    }
                    $this->fontCache[$key] = $font;
                    return $font;
                }
                $this->pendingFontLoads[$key] = $handle;
            }
            // Loading in the background — not ready this frame.
            return null;
        }

        $font = vio_font($this->ctx, $this->fontPaths[$name], $roundedSize);
        if ($font === false) {
            return null;
        }
        $this->fontCache[$key] = $font;

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
