<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat3;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Accessibility\ColorVisionFilter;
use PHPolygon\Rendering\Effect2D\BuiltinEffects2D;
use PHPolygon\Rendering\Effect2D\Effect2D;
use PHPolygon\Rendering\Effect2D\Effect2DRegistry;
use PHPolygon\Rendering\Effect2D\EffectRenderer2DInterface;
use PHPolygon\Rendering\Effects\EffectFallback;
use PHPolygon\Rendering\Effects\EffectRegistry;
use PHPolygon\Rendering\Quality\ShaderQuality;
use PHPolygon\Rendering\Text\BidiOrder;
use PHPolygon\Rendering\Text\EmNormalizedFont;
use PHPolygon\Runtime\PerfProfiler;
use VioContext;
use VioFont;
use VioTexture;

class VioRenderer2D implements Renderer2DInterface, EffectRenderer2DInterface, AnimationClockInterface, SdfFontRenderer2DInterface, GpuImageRenderer2DInterface
{
    /** Frames an unregistered texture stays alive: more than php-vio ever has in flight. */
    private const int RETIRE_FRAMES = 8;

    /** Effect targets grow in steps of this many pixels, so a rectangle that changes size reuses one. */
    private const int EFFECT_TARGET_STEP = 64;

    /** Largest effect target edge; a larger rectangle is drawn at lower resolution. */
    private const int EFFECT_TARGET_MAX = 4096;

    /** Frames an unused effect target size is kept. */
    private const int EFFECT_TARGET_KEEP_FRAMES = 120;

    private const string EFFECT_VERTEX_SHADER = __DIR__ . '/../../resources/shaders/source/vio/postprocess.vert.glsl';

    private int $width;
    private int $height;

    private string $currentFontName = '';

    private int $textAlign = TextAlign::DEFAULT;

    private float $globalAlpha = 1.0;

    /** @var array<string, string> Font name -> file path */
    private array $fontPaths = [];

    /** @var array<string, \VioFont> Cache key "name:size@scale" -> VioFont */
    private array $fontCache = [];

    /**
     * Fonts used as a fallback: loaded from an {@see EmNormalizedFont} copy, so their
     * glyphs fill the advances php-vio's shaped layout gives them. Their measured
     * widths stay those of the source file.
     *
     * @var array<string, true>
     */
    private array $emNormalizedFonts = [];

    /** @var array<string, string> font name -> file actually loaded (the copy or the source) */
    private array $fontFiles = [];

    /**
     * Per font object id: the face and logical size its right-to-left runs are measured
     * with ({@see rtlRunWidth()}), null when that is not possible.
     *
     * @var array<int, array{\VioFontFace, float}|null>
     */
    private array $shapingFaces = [];

    /**
     * devicePixelRatio for glyph rasterisation. The UI is authored in a fixed
     * design grid and magnified onto the framebuffer by a transform; a glyph
     * atlas rasterised at its logical size then gets bilinearly upscaled by that
     * transform and looks blurry on HiDPI / high-resolution displays. Setting
     * this to the on-screen magnification (logical->physical px) makes the
     * renderer rasterise each atlas at size*scale physical pixels while keeping
     * all metrics in logical units, so text stays crisp.
     *
     * 1.0 = legacy behaviour (atlas == logical size). Bumping this re-keys the
     * font cache so the higher-resolution atlases live alongside any 1.0 ones.
     */
    private float $fontRenderScale = 1.0;

    public function clearFontCache(): void
    {
        $this->fontCache = [];
        // Keyed by font object id - a freed font's id can be reused.
        $this->alignHeightCache = [];
        $this->shapingFaces = [];
    }

    /**
     * Set the glyph-atlas devicePixelRatio. See {@see $fontRenderScale}. Values
     * below 1.0 are clamped (an atlas smaller than its logical size would be
     * upscaled — the very blur this exists to prevent). Cheap and idempotent:
     * re-rasterisation only happens lazily when a (font, size) at the new scale
     * is first requested. Callers should quantise to a few discrete steps to
     * avoid thrashing the atlas cache as the window resizes continuously.
     */
    public function setFontRenderScale(float $scale): void
    {
        $this->fontRenderScale = $scale < 1.0 ? 1.0 : $scale;
    }

    public function getFontRenderScale(): float
    {
        return $this->fontRenderScale;
    }

    /**
     * Cache key for a (font, logical size) pair at the current render scale.
     * The scale is folded in so atlases rasterised at different devicePixelRatios
     * never collide (e.g. moving the window between a 1x and a 2x monitor).
     */
    private function fontCacheKey(string $name, int $roundedSize): string
    {
        return $name . ':' . $roundedSize . '@' . (int)round($this->fontRenderScale * 100)
            . (isset($this->emNormalizedFonts[$name]) ? '#em' : '');
    }

    /** @var array<int, VioTexture> Texture glId/objectId -> VioTexture */
    private array $vioTextures = [];

    /**
     * Textures taken out of {@see $vioTextures}, each with the frames it has
     * left. Frames in flight may still read one, and php-vio's D3D12 backend
     * releases a texture the moment its last PHP reference goes.
     *
     * @var list<array{0: VioTexture, 1: int}>
     */
    private array $retiredVioTextures = [];

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
     * Fonts registered with loadFont(..., sdf: true): their atlases (24 px and up) are
     * signed distance fields, so text stays sharp when a transform magnifies it.
     *
     * @var array<string, true>
     */
    private array $sdfFontNames = [];

    /** Smallest atlas size (physical px) an SDF font is rasterized as SDF; below, thin strokes thin out. */
    public const float SDF_MIN_ATLAS_PX = 24.0;

    private static ?bool $vioSdf = null;

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
     * hashtable-resize stalls in the v0.17.1 perf regression (p95 1-2 ms ->
     * 10-13 ms across all HUD panels). PHP arrays keep insertion order,
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
     * Probe string for the vertical alignment of a font: a capital (cap
     * height) plus a descender. See {@see alignHeight()}.
     */
    private const string ALIGN_PROBE = 'Hg';

    /**
     * Ink height of {@see ALIGN_PROBE} per font object (spl_object_id), the
     * content-independent height TOP / MIDDLE / BOTTOM alignment works with.
     *
     * @var array<int, float>
     */
    private array $alignHeightCache = [];

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

    /**
     * Effects drawn this frame, run in endFrame() right before the 2D batch: each
     * renders into its own target, which the batch already holds as a sprite at the
     * effect's place in the draw order.
     *
     * @var list<array{0: Effect2D, 1: \VioRenderTarget, 2: int, 3: int, 4: array<string, int|float|list<float>>, 5: array<string, Texture>, 6: list<float>, 7: array{0: float, 1: float, 2: float, 3: float}}>
     */
    private array $effectJobs = [];

    /** @var array<string, bool> name -> features there and shader compiled, for this registry version */
    private array $effectReady = [];

    /** @var array<string, \VioPipeline> */
    private array $effectPipelines = [];

    private int $effectRegistryVersion = -1;

    private ?\VioMesh $effectQuad = null;

    private ?VioTexture $effectWhite = null;

    private ?GraphicsSettings $effectSettings = null;

    /** The colour vision option of the settings, null while it is off. */
    private ?ColorVisionFilter $colorVision = null;

    /** Which draw colours it reaches: all, none, or those a game's test accepts. */
    private bool|\Closure $colorVisionScope = true;

    /** $colorVision while the scope lets it reach any colour, else null: one test per draw when off. */
    private ?ColorVisionFilter $drawColorFilter = null;

    /** @var array<string, list<array{0: \VioRenderTarget, 1: VioTexture}>> target size -> targets */
    private array $effectTargets = [];

    /** @var array<string, int> target size -> targets handed out this frame */
    private array $effectTargetsTaken = [];

    /** @var array<string, int> target size -> frame it was last used */
    private array $effectTargetsUsed = [];

    private int $frameIndex = 0;

    /** Framebuffer pixels per logical pixel, from beginFrame(). */
    private float $pixelRatio = 1.0;

    /** @var array{0: int, 1: int} framebuffer size of this frame */
    private array $framebufferSize = [1280, 720];

    /** Scale of the current transform (product of the pushed ones), so effects render at the size they appear. */
    private float $transformScale = 1.0;

    /** @var list<float> */
    private array $transformScaleStack = [];

    private float $animationTime = 0.0;

    private bool $animationDriven = false;

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
        if ($this->retiredVioTextures !== []) {
            $this->sweepRetiredVioTextures();
        }

        // Complete any background font loads whose worker thread has finished.
        // The GPU upload happens here, on the render thread, with a current
        // GL/Metal/D3D/Vulkan context — exactly where the engine already polls
        // its async work each frame. Cheap no-op when nothing is in flight.
        $this->pollAsyncFontLoads();

        $this->frameIndex++;
        if (!$this->animationDriven) {
            $this->animationTime += 1.0 / 60.0;
        }
        // vio_begin() empties php-vio's transform stack.
        $this->transformScale = 1.0;
        $this->transformScaleStack = [];
        if ($this->effectTargetsUsed !== []) {
            // Jobs of a frame whose endFrame() never ran lost their sprites with the batch.
            $this->effectJobs = [];
            $this->sweepEffectTargets();
        }

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
            $this->pixelRatio = 1.0;
            $this->framebufferSize = [$w, $h];

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
        $this->framebufferSize = [$fb[0], $fb[1]];
        $this->pixelRatio = $this->width > 0 ? $fb[0] / $this->width : 1.0;

        $this->zCounter = 0.0;

        vio_clear($this->ctx, 0.0, 0.0, 0.0, 1.0);
    }

    public function endFrame(): void
    {
        if ($this->effectJobs !== []) {
            $this->runEffects();
        }
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
        if ($this->drawColorFilter !== null && $this->colorVisionReaches($color)) {
            $color = $this->drawColorFilter->color($color);
        }
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
        $chain = $this->resolveFontChain($size, $text);
        if (empty($chain)) {
            return;
        }

        $align = $this->textAlign;
        // The vertical anchor comes from the FONT, never from the string: the
        // measured height is the ink extent of exactly these glyphs, so a lone
        // '_' (3 px of ink) or '-' would get its own baseline and land at the
        // wrong height - '_' at the top of the line, looking like '-'.
        $th = $this->alignHeight($chain, $text);
        $ascender = $th * 0.65;

        if ($align & (TextAlign::CENTER | TextAlign::RIGHT)) {
            $tw = $this->measureTextWithChain($chain, $text)->width;
            $x -= ($align & TextAlign::CENTER) ? $tw / 2.0 : $tw;
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
        $chain = $this->resolveFontChain($size, $text);
        if (empty($chain)) {
            return;
        }
        $tm = $this->measureTextWithChain($chain, $text);
        // Font-level height, not the ink of this string - see drawText().
        $th = $this->alignHeight($chain, $text);
        $ascender = $th * 0.65;
        $x = $cx - $tm->width / 2.0;
        $y = $cy + $ascender - $th / 2.0;

        $this->drawTextWithChain($chain, $text, $x, $y, $this->colorToArgb($color), $this->nextZ());
    }

    /**
     * Lay a string out across the font chain: assign each character to the
     * earliest font that covers it, merge contiguous same-font characters into
     * runs, and position each run at the pen x reached by advancing through the
     * *claiming* font's per-character widths.
     *
     * Pure claim/positioning logic, separated from {@see drawTextWithChain} so
     * it can be unit-tested without a live VioContext. Returns every run (the
     * primary's runs included) plus the string's total advance width, so the
     * caller can both draw and measure from a single, consistent layout.
     *
     * Three bugs are addressed here, all invisible for pure-Latin text:
     *
     *  1. CJK double-draw (v0.22.0). A glyph present in two fallback fonts
     *     (e.g. a Han char shared by noto-sans-sc and noto-sans-kr) used to be
     *     emitted into *every* covering fallback and overprinted. Each character
     *     is now claimed by the earliest font in the chain that covers it.
     *
     *  2. Mixed-string mis-positioning (v0.22.1). Fallback runs were anchored at
     *     the *primary* font's width of the preceding substring — correct only
     *     while that prefix is pure primary text. The primary cannot measure a
     *     CJK glyph, so once the prefix held one its width collapsed.
     *
     *  3. Trailing text over full-width CJK (this version). The primary used to
     *     draw the whole string, advancing its pen by its narrow .notdef width
     *     over each CJK glyph — far less than the full-width glyph the fallback
     *     renders — so following characters overprinted the CJK. Now the pen
     *     advances by the *claiming* font's width for every character, so a
     *     full-width CJK glyph reserves its true width and the caller no longer
     *     issues a whole-string primary pass at all in the mixed case.
     *
     * Claim rules per character:
     *  - The earliest font index i (primary included) with a non-zero width for
     *    the char claims it and draws it.
     *  - A char no font covers falls back to the primary (index 0); the primary
     *    renders its .notdef box exactly at the pen, never over a neighbour.
     *
     * @param list<string>                 $chars     ordered single characters
     * @param int                          $chainSize total fonts in the chain (>= 1)
     * @param callable(int, string): float $charWidth fn($fontIndex, $char): float —
     *                                                width of $char in chain font
     *                                                $fontIndex (0.0 = no glyph)
     * @param float                        $originX   pen x of the first character
     * @return array{runs: list<array{font: int, text: string, x: float}>, width: float}
     *         ordered draw ops (each rendering $text with chain font $font at
     *         absolute offset $x) and the string's total advance width
     */
    private static function planTextRuns(
        array $chars,
        int $chainSize,
        callable $charWidth,
        callable $charCovers,
        float $originX,
    ): array {
        $runs = [];
        $penX = $originX;
        $runFont = -1;
        $runText = '';
        $runX = $originX;

        foreach ($chars as $ch) {
            // The earliest font in the chain with a glyph for this character
            // claims it. A character no font covers falls back to the primary
            // (index 0) so its .notdef box still renders, exactly where the pen
            // is — never on top of a neighbour.
            $claim = 0;
            for ($i = 0; $i < $chainSize; $i++) {
                if ($charCovers($i, $ch)) {
                    $claim = $i;
                    break;
                }
            }

            if ($claim !== $runFont) {
                if ($runText !== '') {
                    $runs[] = ['font' => $runFont, 'text' => $runText, 'x' => $runX];
                }
                $runFont = $claim;
                $runText = '';
                $runX = $penX;
            }

            $runText .= $ch;
            // Advance by the claiming font's own width for the glyph — this is
            // the crux: a full-width CJK glyph reserves its true width, so the
            // following run starts past it instead of overprinting it.
            $penX += $charWidth($claim, $ch);
        }

        if ($runText !== '') {
            $runs[] = ['font' => $runFont, 'text' => $runText, 'x' => $runX];
        }

        return ['runs' => $runs, 'width' => $penX - $originX];
    }

    /**
     * {@see planTextRuns} for a line with right-to-left text: the runs are placed in
     * visual order ({@see BidiOrder}), so an Arabic or Hebrew sentence split across
     * fonts reads from right to left instead of word by word from left to right.
     *
     * A run is a stretch of characters, adjacent on screen, with one claiming font
     * and one embedding level. Its text goes to vio_text in the order vio needs:
     * - right to left with a strong RTL character: logical order - php-vio shapes it
     *   (joining forms) and lays it out right to left itself;
     * - right to left without one (spaces, punctuation): already reversed, brackets
     *   mirrored - vio draws it left to right as given;
     * - left to right (Latin words, numbers): logical order.
     * Each run is measured whole ($runWidth), so joined Arabic letters reserve their
     * shaped width, not the sum of their isolated forms.
     *
     * @param list<string>                 $chars     one line, ordered single characters
     * @param callable(int, string): bool  $charCovers fn($fontIndex, $char): bool
     * @param callable(int, string): float $runWidth  fn($fontIndex, $text): float - advance of $text
     * @param int|null                     $base      paragraph level of a wrapped line, null = its own
     * @return array{runs: list<array{font: int, text: string, x: float}>, width: float}
     */
    private static function planBidiRuns(
        array $chars,
        int $chainSize,
        callable $charCovers,
        callable $runWidth,
        float $originX,
        ?int $base = null,
    ): array {
        $claims = [];
        foreach ($chars as $ch) {
            $claim = 0;
            for ($i = 0; $i < $chainSize; $i++) {
                if ($charCovers($i, $ch)) {
                    $claim = $i;
                    break;
                }
            }
            $claims[] = $claim;
        }
        $levels = BidiOrder::levels($chars, $base)['levels'];
        $order = BidiOrder::visualOrder($levels);

        $runs = [];
        $penX = $originX;
        $count = count($order);
        for ($v = 0; $v < $count;) {
            $font = $claims[$order[$v]];
            $level = $levels[$order[$v]];
            $group = [];
            while ($v < $count && $claims[$order[$v]] === $font && $levels[$order[$v]] === $level) {
                $group[] = $order[$v];
                $v++;
            }
            if ($level % 2 === 1) {
                $strong = false;
                foreach ($group as $index) {
                    if (BidiOrder::isStrongRtl($chars[$index])) {
                        $strong = true;
                        break;
                    }
                }
                $text = '';
                if ($strong) {
                    foreach (array_reverse($group) as $index) {
                        $text .= $chars[$index];
                    }
                } else {
                    foreach ($group as $index) {
                        $text .= BidiOrder::mirror($chars[$index]);
                    }
                }
            } else {
                $text = '';
                foreach ($group as $index) {
                    $text .= $chars[$index];
                }
            }
            $runs[] = ['font' => $font, 'text' => $text, 'x' => $penX];
            $penX += $runWidth($font, $text);
        }

        return ['runs' => $runs, 'width' => $penX - $originX];
    }

    /**
     * Render text using the font chain. Plain Western text (and text the primary
     * already covers fully) is drawn in a single primary pass. A genuinely mixed
     * string is split into runs by {@see planTextRuns} and each run is drawn with
     * its claiming font at the pen position reached by advancing through the
     * claiming fonts' own widths — so full-width CJK glyphs never get overprinted
     * by following text. A string with right-to-left text is laid out by
     * {@see planBidiRuns} instead.
     * @param list<\VioFont> $chain
     * @param int|null $bidiBase paragraph level of a wrapped line (see BidiOrder::levels())
     */
    private function drawTextWithChain(array $chain, string $text, float $x, float $y, int $argb, float $z, ?int $bidiBase = null): void
    {
        $primary = $chain[0];

        // Fast path: no fallbacks, or the text is plain Latin/Western (the only
        // thing the configured CJK fallback fonts could add is Han/Hangul). The
        // primary renders the whole string in one call. This skips the per-glyph
        // coverage scan AND the extra vio_text draws through the large CJK
        // fallback fonts — which cost ~40 ms/frame on a HUD full of Latin text.
        $chainSize = count($chain);
        if ($chainSize <= 1 || !self::textNeedsFallback($text)) {
            vio_text($this->ctx, $primary, $text, $x, $y, ['color' => $argb, 'z' => $z]);
            return;
        }

        // If the primary already covers every glyph, one call still suffices.
        // Coverage is a real per-glyph presence check (see fontCoversChar) — not
        // advance width, whose .notdef box can be non-zero and wrongly signal
        // "covered", making the primary swallow CJK/Arabic/Thai as tofu.
        $chars = mb_str_split($text);
        $primaryCoversAll = true;
        foreach ($chars as $ch) {
            if (!$this->fontCoversChar($primary, $ch)) {
                $primaryCoversAll = false;
                break;
            }
        }
        if ($primaryCoversAll) {
            vio_text($this->ctx, $primary, $text, $x, $y, ['color' => $argb, 'z' => $z]);
            return;
        }

        // Genuinely mixed. Draw each run with its claiming font, advancing the
        // pen by that font's own per-glyph widths. The primary deliberately does
        // NOT draw the whole string here: its narrow .notdef advance over a CJK
        // glyph is far smaller than the full-width glyph the fallback renders, so
        // a single primary pass would shove every following character on top of
        // the CJK (the half of the bug the v0.22.1 prefix-width fix missed).
        //
        // The (font, char) advance is memoised through measureCached, so each
        // probe is paid at most once across the whole frame.
        $charWidth = fn (int $fontIndex, string $ch): float
            => (float)$this->measureCached($chain[$fontIndex], $ch)['width'];
        $charCovers = fn (int $fontIndex, string $ch): bool
            => $this->fontCoversChar($chain[$fontIndex], $ch);

        $plan = BidiOrder::hasRtl($text) || $bidiBase === 1
            ? self::planBidiRuns($chars, $chainSize, $charCovers, $this->runWidth($chain), $x, $bidiBase)
            : self::planTextRuns($chars, $chainSize, $charWidth, $charCovers, $x);
        foreach ($plan['runs'] as $draw) {
            vio_text($this->ctx, $chain[$draw['font']], $draw['text'], $draw['x'], $y, ['color' => $argb, 'z' => $z]);
        }
    }

    public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, Color $color): void
    {
        $chain = $this->resolveFontChain($size, $text);
        if ($chain === []) {
            return;
        }
        $argb = $this->colorToArgb($color);
        $z = $this->nextZ();
        $align = $this->textAlign;
        $lineHeight = $size * 1.2;
        // vio_text renders from baseline — offset each line by ascender.
        $lineY = $y + $size * 0.65;

        // Wrap and draw across the whole font chain, exactly like drawText():
        // each line's break point AND its rendered runs honour fallback glyph
        // widths. A single-font path measured a CJK/Arabic body to ~0 in the
        // primary, so it never wrapped and then drew every glyph as a .notdef
        // box. The chain-aware width here matches what measureTextBox() reports.
        $lineWidth = fn (string $s): float => $this->measureTextWithChain($chain, $s)->width;
        // A wrapped line keeps the direction of its paragraph: an Arabic sentence whose
        // second line starts with "$language" still runs right to left there.
        $bidiBase = BidiOrder::hasRtl($text) ? BidiOrder::paragraphLevel(mb_str_split($text)) : null;

        foreach (TextWrap::lines($text, $breakWidth, $lineWidth) as $line) {
            if ($line !== '') {
                $lx = $x;
                if ($align & TextAlign::CENTER) {
                    $lx = $x + ($breakWidth - $lineWidth($line)) / 2.0;
                } elseif ($align & TextAlign::RIGHT) {
                    $lx = $x + $breakWidth - $lineWidth($line);
                }
                $this->drawTextWithChain($chain, $line, $lx, $lineY, $argb, $z, $bidiBase);
            }
            // A '' entry is a blank line from consecutive newlines; it still
            // advances one line height to produce the visible gap.
            $lineY += $lineHeight;
        }
    }


    /**
     * Sprites keep their pixels under the colour vision option: the tint is white, and
     * correcting the texels would take a render pass per sprite (a 2D effect cannot read
     * what was drawn before it). A state shown by colour belongs in a drawn shape or text,
     * or in the game's own art for each mode.
     */
    /**
     * Draw a GPU image the engine made (a minimap, a render target's texture) at
     * x, y, w, h; srcW / srcH = its pixel size (shader plan 8.1).
     */
    public function drawGpuImage(VioTexture $texture, float $srcW, float $srcH, float $x, float $y, float $w, float $h, float $opacity = 1.0): void
    {
        vio_sprite($this->ctx, $texture, [
            'x' => $x, 'y' => $y, 'width' => $w, 'height' => $h,
            'src_x' => 0.0, 'src_y' => 0.0, 'src_w' => $srcW, 'src_h' => $srcH,
            'color' => ((((int) (max(0.0, min(1.0, $opacity)) * $this->globalAlpha * 255)) & 0xFF) << 24) | 0xFFFFFF,
            'z' => $this->nextZ(),
        ]);
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
            'color' => ((((int)($opacity * $this->globalAlpha * 255)) & 0xFF) << 24) | 0xFFFFFF,
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
        $this->transformScaleStack[] = $this->transformScale;
        $this->transformScale *= sqrt(abs($m[0] * $m[4] - $m[3] * $m[1]));
    }

    public function popTransform(): void
    {
        vio_pop_transform($this->ctx);
        $this->transformScale = array_pop($this->transformScaleStack) ?? 1.0;
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
        unset($this->fontFiles[$name], $this->sdfFontNames[$name]);
    }

    /**
     * Register a font whose glyph atlases are signed distance fields (php-vio with
     * vio_font 'sdf'). Sizes whose atlas would be under {@see SDF_MIN_ATLAS_PX} and older
     * php-vio builds keep the coverage atlas. Cached atlases of the name are dropped.
     */
    public function loadSdfFont(string $name, string $path): void
    {
        $this->loadFont($name, $path);
        $this->sdfFontNames[$name] = true;
        foreach (array_keys($this->fontCache) as $key) {
            if (str_starts_with((string) $key, $name . ':')) {
                unset($this->fontCache[$key]);
            }
        }
    }

    /** Whether this php-vio build takes vio_font options (the 'sdf' atlas). */
    public static function vioSupportsSdfFonts(): bool
    {
        return self::$vioSdf ??= \function_exists('vio_font')
            && (new \ReflectionFunction('vio_font'))->getNumberOfParameters() >= 5;
    }

    /** Synchronous atlas for $name at $size: SDF where registered and large enough. */
    private function openFont(string $name, string $file, float $size, float $scale): VioFont|false
    {
        if (isset($this->sdfFontNames[$name]) && $size * $scale >= self::SDF_MIN_ATLAS_PX && self::vioSupportsSdfFonts()) {
            return vio_font($this->ctx, $file, $size, $scale, ['sdf' => true]);
        }
        return vio_font($this->ctx, $file, $size, $scale);
    }

    /**
     * The file vio loads for $name: for a fallback font the {@see EmNormalizedFont}
     * copy (php-vio's shaped path otherwise draws its glyphs too small for their
     * advances - Arabic at 47 %, CJK at 69 %), for every other font its own file.
     */
    private function fontFile(string $name): string
    {
        if (!isset($this->emNormalizedFonts[$name])) {
            return $this->fontPaths[$name];
        }

        return $this->fontFiles[$name] ??= EmNormalizedFont::path($this->fontPaths[$name]);
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
     * them synchronously on first use froze the splash for 20-25 s.
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
        unset($this->fontFiles[$name]);
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
            $path = $this->fontFile($name);
            foreach ($sizes as $size) {
                $rounded = (float)(int)$size;
                $key = $this->fontCacheKey($name, (int)$rounded);
                if (isset($this->fontCache[$key]) || isset($work[$key])) {
                    continue;
                }
                if (isset($this->sdfFontNames[$name])) {
                    $font = $this->openFont($name, $path, $rounded, $this->fontRenderScale);
                    if ($font instanceof VioFont) {
                        $this->fontCache[$key] = $font;
                    }
                    continue;
                }
                $work[$key] = [$path, $rounded];
            }
        }
        if ($work === []) {
            return;
        }

        $scale = $this->fontRenderScale;
        $hasAsync = \function_exists('vio_font_load_async') && \function_exists('vio_font_load_poll');
        if (!$hasAsync) {
            foreach ($work as $key => [$path, $rounded]) {
                $font = vio_font($this->ctx, $path, $rounded, $scale);
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
                $handle = vio_font_load_async($this->ctx, $path, $rounded, $scale);
                if ($handle === false) {
                    // Worker spawn failed — load synchronously so the font is
                    // never lost.
                    $font = vio_font($this->ctx, $path, $rounded, $scale);
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
        $chain = $this->resolveFontChain($size, $text);
        if (empty($chain)) {
            return new TextMetrics(mb_strlen($text) * $size * 0.6, $size);
        }
        return $this->measureTextWithChain($chain, $text);
    }

    /** Whether the vio backend exposes vio_font_has_glyph (older builds lack it). */
    private ?bool $hasGlyphFn = null;

    /**
     * Memoized per-(font,char) glyph coverage — see {@see fontCoversChar}.
     * @var array<string, bool>
     */
    private array $coverageCache = [];

    /**
     * True if $font carries a real glyph for the first character of $ch.
     *
     * Coverage detection for the font fallback chain. Prefers vio_font_has_glyph
     * (HarfBuzz nominal-glyph lookup, reliable) and only falls back to the
     * advance-width heuristic on older vio builds without the primitive. That
     * heuristic is unreliable where a font's .notdef box has a positive advance
     * — the signal that made the primary font wrongly claim uncovered
     * CJK/Arabic/Thai codepoints and render tofu. Memoized like {@see measureCached}.
     */
    private function fontCoversChar(\VioFont $font, string $ch): bool
    {
        $key = \spl_object_id($font) . '|' . $ch;
        if (isset($this->coverageCache[$key])) {
            return $this->coverageCache[$key];
        }

        $this->hasGlyphFn ??= \function_exists('vio_font_has_glyph');
        if ($this->hasGlyphFn) {
            // (int) cast: mb_ord returns int|false; an undecodable char maps to 0
            // (glyph 0 = .notdef = not covered), which is the correct answer.
            $cp = (int) \mb_ord($ch, 'UTF-8');
            $covered = \vio_font_has_glyph($font, $cp);
        } else {
            $covered = ((float) $this->measureCached($font, $ch)['width']) > 0.001;
        }

        $this->coverageCache[$key] = $covered;
        if (\count($this->coverageCache) > $this->measureCacheCap) {
            \array_shift($this->coverageCache);
        }

        return $covered;
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
     * HUD panel (1-2 ms -> 10-13 ms) once dynamic strings — counters, dates,
     * deadlines — had accumulated enough distinct keys to trigger PHP
     * hashtable resizes mid-frame.
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
     * Width of a run of {@see planBidiRuns}: vio_text_measure, except for a run with
     * right-to-left letters, which {@see rtlRunWidth()} measures.
     *
     * @param list<\VioFont> $chain
     * @return \Closure(int, string): float
     */
    private function runWidth(array $chain): \Closure
    {
        return fn (int $fontIndex, string $text): float => BidiOrder::hasRtl($text)
            ? $this->rtlRunWidth($chain[$fontIndex], $text)
            : (float) $this->measureCached($chain[$fontIndex], $text)['width'];
    }

    /**
     * Advance of a right-to-left run as vio_text draws it. php-vio's vio_text_measure
     * (up to at least 2.40.3) shapes every run left to right, which gives Arabic other
     * joining forms and widths than the right-to-left drawing ("حاول" 51.5 px measured,
     * 64.7 px drawn at 32 px): the next run would overlap or leave a gap. The CPU
     * layout of vio_text_bitmap resolves the direction and lays out at the same
     * pixels per EM as vio_text's HarfBuzz pass, so its advance is the drawn one.
     * Falls back to vio_text_measure where that is not available.
     */
    private function rtlRunWidth(\VioFont $font, string $text): float
    {
        $id = \spl_object_id($font);
        if (!\array_key_exists($id, $this->shapingFaces)) {
            $this->shapingFaces[$id] = null;
            $key = \array_search($font, $this->fontCache, true);
            if (\is_string($key) && \function_exists('vio_text_bitmap') && \function_exists('vio_font_face')
                && \preg_match('/^(.*):(\d+)@\d+(#em)?$/', $key, $m) === 1 && isset($this->fontPaths[$m[1]])) {
                $face = @vio_font_face($this->fontFile($m[1]));
                if ($face instanceof \VioFontFace) {
                    $this->shapingFaces[$id] = [$face, (float) $m[2]];
                }
            }
        }
        $source = $this->shapingFaces[$id];
        if ($source === null) {
            return (float) $this->measureCached($font, $text)['width'];
        }
        $cacheKey = $id . '|rtl|' . $text;
        if (!isset($this->measureCache[$cacheKey])) {
            $m = vio_text_bitmap([$source[0]], $text, $source[1], ['measure' => true]);
            $this->measureCache[$cacheKey] = ['width' => \is_array($m) ? (float) $m['advance'] : 0.0, 'height' => 0.0];
        }

        return (float) $this->measureCache[$cacheKey]['width'];
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
     * The 2026-06 fix (v0.17.2) replaced the regex here because once CJK
     * fallbacks are registered the regex runs unconditionally and `preg_match`
     * with the u-flag was dominating per-frame text cost.
     */
    private static function textNeedsFallback(string $text): bool
    {
        return \strpbrk($text, self::FALLBACK_BYTE_MASK) !== false;
    }

    /**
     * The height TOP / MIDDLE / BOTTOM alignment anchors on: the ink height of
     * {@see ALIGN_PROBE} in the chain's primary font. Constant per font and
     * size, so every string drawn at the same y shares one baseline - single
     * glyphs ('_', '-', '.') included. Typical UI strings (capitals plus
     * descenders) measure about the same, so their placement barely moves
     * against the old per-string anchor.
     *
     * A primary font without the probe glyphs falls back to the string's own
     * height (the previous behaviour).
     *
     * @param list<\VioFont> $chain
     */
    private function alignHeight(array $chain, string $text): float
    {
        $id = \spl_object_id($chain[0]);
        if (!isset($this->alignHeightCache[$id])) {
            $this->alignHeightCache[$id] = (float) $this->measureCached($chain[0], self::ALIGN_PROBE)['height'];
        }
        $height = $this->alignHeightCache[$id];

        return $height > 0.0 ? $height : $this->measureTextWithChain($chain, $text)->height;
    }

    /**
     * Measure text across the font chain. Height is the tallest contributing
     * font, but width must equal what {@see drawTextWithChain} actually renders:
     * the sum of each glyph's advance in the font that claims it. Taking the max
     * width across fonts (the old behaviour) over-measured mixed strings — a CJK
     * fallback such as noto-sans-sc also carries wide Latin glyphs, so it
     * reported a width far larger than the rendered run, throwing off centering,
     * button auto-sizing and every measure-then-place layout.
     * @param list<\VioFont> $chain
     */
    private function measureTextWithChain(array $chain, string $text): TextMetrics
    {
        $chainSize = count($chain);
        if ($chainSize > 1 && !self::textNeedsFallback($text)) {
            $chain = [$chain[0]];
            $chainSize = 1;
        }

        $maxH = 0.0;
        foreach ($chain as $font) {
            $h = (float)$this->measureCached($font, $text)['height'];
            if ($h > $maxH) { $maxH = $h; }
        }

        if ($chainSize <= 1) {
            return new TextMetrics((float)$this->measureCached($chain[0], $text)['width'], $maxH);
        }

        // Mixed string: width is the per-claiming-font advance sum (matches the
        // run layout the renderer draws).
        $charWidth = fn (int $fontIndex, string $ch): float
            => (float)$this->measureCached($chain[$fontIndex], $ch)['width'];
        $charCovers = fn (int $fontIndex, string $ch): bool
            => $this->fontCoversChar($chain[$fontIndex], $ch);
        $width = BidiOrder::hasRtl($text)
            ? self::planBidiRuns(mb_str_split($text), $chainSize, $charCovers, $this->runWidth($chain), 0.0)['width']
            : self::planTextRuns(mb_str_split($text), $chainSize, $charWidth, $charCovers, 0.0)['width'];

        return new TextMetrics($width, $maxH);
    }

    public function measureTextBox(string $text, float $breakWidth, float $size): TextMetrics
    {
        $chain = $this->resolveFontChain($size, $text);
        if ($chain === []) {
            return new TextMetrics($breakWidth, $size);
        }

        // Same chain-aware wrap as drawTextBox() so the reported box matches what
        // is rendered: line width is the per-claiming-font advance sum, height is
        // one lineHeight per emitted line (blank lines included).
        $lineHeight = $size * 1.2;
        $lineWidth = fn (string $s): float => $this->measureTextWithChain($chain, $s)->width;

        $lines = TextWrap::lines($text, $breakWidth, $lineWidth);
        $maxWidth = 0.0;
        foreach ($lines as $line) {
            if ($line !== '') {
                $maxWidth = max($maxWidth, $lineWidth($line));
            }
        }

        return new TextMetrics($maxWidth, count($lines) * $lineHeight);
    }

    /** @var array<string, array<string, bool>> font name -> script value -> covered */
    private array $scriptCoverage = [];

    /** Font size used for coverage probes; any size answers has-glyph identically. */
    private const COVERAGE_PROBE_SIZE = 16.0;

    public function fontCoversScript(string $font, Script $script): bool
    {
        if (isset($this->scriptCoverage[$font][$script->value])) {
            return $this->scriptCoverage[$font][$script->value];
        }

        $vf = $this->resolveFontByName($font, self::COVERAGE_PROBE_SIZE);
        if ($vf === null) {
            // Not loaded (or still packing async) — don't cache a false negative;
            // re-probe once the face is available.
            return false;
        }

        // Real glyph-presence check (vio_font_has_glyph), matching how
        // planTextRuns() now claims characters. Advance width is unreliable — a
        // font's .notdef box can measure non-zero and falsely report coverage.
        $covered = $this->fontCoversChar($vf, $script->sampleChar());
        $this->scriptCoverage[$font][$script->value] = $covered;

        return $covered;
    }

    public function fontForScript(Script $script, array $candidates): ?string
    {
        foreach ($candidates as $font) {
            if ($this->fontCoversScript($font, $script)) {
                return $font;
            }
        }

        return null;
    }

    public function addFallbackFont(string $baseFont, string $fallbackFont): void
    {
        if (!isset($this->fallbackFonts[$baseFont]) || !in_array($fallbackFont, $this->fallbackFonts[$baseFont], true)) {
            $this->fallbackFonts[$baseFont][] = $fallbackFont;
        }
        // From now on the font loads from its normalised copy (its own cache keys,
        // see fontCacheKey(); atlases built from the source file before stay unused).
        $this->emNormalizedFonts[$fallbackFont] = true;
    }

    public function clearFallbackFonts(?string $baseFont = null): void
    {
        if ($baseFont === null) {
            $this->fallbackFonts = [];
            return;
        }
        unset($this->fallbackFonts[$baseFont]);
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
        $this->transformScaleStack[] = $this->transformScale;
    }

    public function restoreState(): void
    {
        if (!empty($this->stateStack)) {
            [$this->currentFontName, $this->textAlign, $this->globalAlpha] = array_pop($this->stateStack);
        }
        vio_pop_transform($this->ctx);
        $this->transformScale = array_pop($this->transformScaleStack) ?? 1.0;
    }

    public function registerVioTexture(int $textureId, VioTexture $vioTexture): void
    {
        $this->vioTextures[$textureId] = $vioTexture;
    }

    /**
     * Forget a texture of {@see registerVioTexture()}. Sprites can no longer use
     * it at once, the object is dropped after {@see RETIRE_FRAMES} frames.
     */
    public function unregisterVioTexture(int $textureId): void
    {
        $texture = $this->vioTextures[$textureId] ?? null;
        if ($texture !== null) {
            unset($this->vioTextures[$textureId]);
            $this->retiredVioTextures[] = [$texture, self::RETIRE_FRAMES];
        }
    }

    private function sweepRetiredVioTextures(): void
    {
        $left = [];
        foreach ($this->retiredVioTextures as [$texture, $frames]) {
            if ($frames > 1) {
                $left[] = [$texture, $frames - 1];
            }
        }
        $this->retiredVioTextures = $left;
    }

    public function getContext(): VioContext
    {
        return $this->ctx;
    }

    public function setAnimationTime(float $seconds): void
    {
        $this->animationTime = $seconds;
        $this->animationDriven = true;
    }

    // ── Effects (Effect2DRegistry) ───────────────────────────────────

    public function applyEffectSettings(GraphicsSettings $settings): void
    {
        $this->effectSettings = $settings;
        // Colour vision reaches the UI here as well as the 3D image (VioAccessibilityPass).
        $filter = ColorVisionFilter::fromSettings($settings->accessibility);
        if ($filter === null || !$filter->sameAs($this->colorVision)) {
            $this->colorVision = $filter;
        }
        $this->drawColorFilter = $this->colorVisionScope === false ? null : $this->colorVision;
    }

    /**
     * Which draw colours the colour vision option reaches. true (default): every colour
     * of rects, outlines, circles, lines, arcs, text and the clear. false: none, for a game
     * that corrects its UI itself. A closure `fn (Color $c): bool` picks the colours, e.g.
     * only those that carry a state (success, error, warning); it runs for each such draw
     * while the option is on. Sprites are never filtered (see {@see drawSprite()}).
     */
    public function setColorVisionScope(bool|\Closure $scope): void
    {
        $this->colorVisionScope = $scope;
        $this->drawColorFilter = $scope === false ? null : $this->colorVision;
    }

    /**
     * The colour vision option as the UI applies it, null while it is off. For colours that
     * leave the 2D renderer with the same meaning: 2D effect parameters, a 3D marker.
     */
    public function colorVisionFilter(): ?ColorVisionFilter
    {
        return $this->colorVision;
    }

    public function effectAvailable(string $name): bool
    {
        $effect = Effect2DRegistry::get($name);
        return $effect !== null && $this->effectReady($effect);
    }

    public function drawEffect(
        string $name,
        float $x,
        float $y,
        float $w,
        float $h,
        array $parameters = [],
        array $textures = [],
        ?float $padding = null,
    ): bool {
        $effect = Effect2DRegistry::get($name);
        if ($effect === null || !$this->effectRuns($effect)) {
            return false;
        }
        $pad = $padding ?? $effect->padding;
        $qw = $w + 2.0 * $pad;
        $qh = $h + 2.0 * $pad;
        if ($qw <= 0.0 || $qh <= 0.0) {
            return true; // nothing to cover
        }
        // Rendered at the size it appears: framebuffer pixels under the current transform.
        $scale = $this->pixelRatio * $this->transformScale;
        $pw = max(1, min(self::EFFECT_TARGET_MAX, (int) ceil($qw * $scale)));
        $ph = max(1, min(self::EFFECT_TARGET_MAX, (int) ceil($qh * $scale)));
        $target = $this->effectTarget($pw, $ph);
        if ($target === null) {
            return false;
        }
        $this->effectJobs[] = [$effect, $target[0], $pw, $ph, $parameters, $textures, [$pad / $qw, $pad / $qh, ($pad + $w) / $qw, ($pad + $h) / $qh], [$x - $pad, $y - $pad, $qw, $qh]];
        // The region of the target the effect draws into: row 0 is the top row of
        // the image on every backend (the quad's v follows the target convention).
        vio_sprite($this->ctx, $target[1], [
            'x' => $x - $pad,
            'y' => $y - $pad,
            'width' => $qw,
            'height' => $qh,
            'src_x' => 0.0,
            'src_y' => 0.0,
            'src_w' => (float) $pw,
            'src_h' => (float) $ph,
            // White at the global alpha: like a sprite, an effect's output is not filtered.
            'color' => ((((int)($this->globalAlpha * 255)) & 0xFF) << 24) | 0xFFFFFF,
            'z' => $this->nextZ(),
        ]);

        return true;
    }

    /**
     * Bind the last 3D image to a backdrop sampler and say where the effect lies in it:
     * u_backdrop_rect (uv of the padded rectangle, row 0 at the top on every backend),
     * u_backdrop_hdr / u_backdrop_exposure for the tonemap the effect applies.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $screen x, y, w, h in 2D units
     */
    private function bindBackdrop(int $slot, array $screen): void
    {
        $b = VioRenderer3D::backdrop();
        $texture = $b['texture'] ?? ($this->effectWhite ??= (vio_texture($this->ctx, ['data' => "\xff\xff\xff\xff", 'width' => 1, 'height' => 1]) ?: null));
        if ($texture === null) {
            return;
        }
        vio_set_uniform($this->ctx, BuiltinEffects2D::BACKDROP_SAMPLER, $slot);
        vio_bind_texture($this->ctx, $texture, $slot);
        $sw = max(1.0, (float) $this->getWidth());
        $sh = max(1.0, (float) $this->getHeight());
        [$x, $y, $w, $h] = $screen;
        $top = BackendConventions::forBackend(vio_backend_name($this->ctx))->isOpenGL()
            ? [$x / $sw, 1.0 - $y / $sh, $w / $sw, -$h / $sh]
            : [$x / $sw, $y / $sh, $w / $sw, $h / $sh];
        vio_set_uniform($this->ctx, 'u_backdrop_rect', $top);
        vio_set_uniform($this->ctx, 'u_backdrop_hdr', ($b['hdr'] ?? false) ? 1.0 : 0.0);
        vio_set_uniform($this->ctx, 'u_backdrop_exposure', $b['exposure'] ?? 1.0);
    }

    /** Whether an effect draws now: settings, reduced motion, shader quality, then its gates. */
    private function effectRuns(Effect2D $effect): bool
    {
        $settings = $this->effectSettings ??= new GraphicsSettings();
        if (!$effect->enabledBy($settings)) {
            return false;
        }
        $entry = EffectRegistry::get($effect->name);
        if (EffectRegistry::motionSwitchesOff($entry)) {
            return false;
        }
        if ($entry !== null && $settings->shaderQuality === ShaderQuality::Unlit && $entry->unlitFallback !== EffectFallback::Same) {
            return false;
        }
        return $this->effectReady($effect);
    }

    /** Features and compile of an effect, once per registry version. A shader that fails is logged; its fallback draws. */
    private function effectReady(Effect2D $effect): bool
    {
        if ($this->effectRegistryVersion !== Effect2DRegistry::version()) {
            $this->effectRegistryVersion = Effect2DRegistry::version();
            $this->effectReady = [];
            $this->effectPipelines = [];
        }
        if (isset($this->effectReady[$effect->name])) {
            return $this->effectReady[$effect->name];
        }
        $ready = true;
        foreach ($effect->features as $feature) {
            $flag = defined($feature) ? constant($feature) : null;
            $ready = $ready && is_int($flag) && vio_supports_feature($this->ctx, $flag);
        }
        if ($ready) {
            try {
                $this->effectPipelines[$effect->name] = $this->compileEffect($effect);
            } catch (\RuntimeException $e) {
                fwrite(STDERR, "[VioRenderer2D] 2D effect '{$effect->name}' unavailable: {$e->getMessage()}\n");
                $ready = false;
            }
        }
        return $this->effectReady[$effect->name] = $ready;
    }

    private function compileEffect(Effect2D $effect): \VioPipeline
    {
        $fragment = @file_get_contents($effect->fragmentPath);
        $vertex = @file_get_contents(self::EFFECT_VERTEX_SHADER);
        if ($fragment === false || $vertex === false) {
            throw new \RuntimeException("cannot read {$effect->fragmentPath}");
        }
        $conventions = BackendConventions::forBackend(vio_backend_name($this->ctx));
        $start = hrtime(true);
        $shader = @vio_shader($this->ctx, ['vertex' => $vertex, 'fragment' => $fragment, 'format' => $conventions->shaderSourceFormat()]);
        \PHPolygon\Runtime\FrameSpikeRecorder::note(\PHPolygon\Runtime\FrameSpikeRecorder::NOTE_SHADER, 1, hrtime(true) - $start);
        if ($shader === false) {
            throw new \RuntimeException('shader does not compile');
        }
        $pipeline = vio_pipeline($this->ctx, [
            'shader' => $shader,
            'depth_test' => false,
            'cull_mode' => VIO_CULL_NONE,
            'blend' => VIO_BLEND_NONE,
        ]);
        if ($pipeline === false) {
            throw new \RuntimeException('pipeline refused');
        }
        if ($this->effectQuad === null) {
            // v = 0 at the top row of the target image on every backend (see VioRenderer3D's screen quad).
            [$vBottom, $vTop] = $conventions->flipRenderTargetClipY() ? [1, 0] : [0, 1];
            $this->effectQuad = vio_mesh($this->ctx, [
                'vertices' => [
                    -1, -1, 0, 0, $vBottom,
                     1, -1, 0, 1, $vBottom,
                     1,  1, 0, 1, $vTop,
                    -1,  1, 0, 0, $vTop,
                ],
                'indices' => [0, 1, 2, 0, 2, 3],
                'layout' => [VIO_FLOAT3, VIO_FLOAT2],
            ]) ?: throw new \RuntimeException('quad refused');
        }
        return $pipeline;
    }

    /**
     * A target that holds at least $w x $h, not yet used this frame. Sizes are rounded
     * up to {@see EFFECT_TARGET_STEP}, so a rectangle that grows or shrinks keeps its target.
     *
     * @return array{0: \VioRenderTarget, 1: VioTexture}|null
     */
    private function effectTarget(int $w, int $h): ?array
    {
        $step = self::EFFECT_TARGET_STEP;
        $tw = min(self::EFFECT_TARGET_MAX, (int) (ceil($w / $step) * $step));
        $th = min(self::EFFECT_TARGET_MAX, (int) (ceil($h / $step) * $step));
        $key = "{$tw}x{$th}";
        $i = $this->effectTargetsTaken[$key] ?? 0;
        if (!isset($this->effectTargets[$key][$i])) {
            $target = vio_render_target($this->ctx, ['width' => $tw, 'height' => $th]);
            $texture = $target !== false ? vio_render_target_texture($target) : false;
            if ($target === false || $texture === false) {
                return null;
            }
            $this->effectTargets[$key][$i] = [$target, $texture];
        }
        $this->effectTargetsTaken[$key] = $i + 1;
        $this->effectTargetsUsed[$key] = $this->frameIndex;
        return $this->effectTargets[$key][$i];
    }

    /** Drop target sizes no frame used for a while; called before vio_begin(). */
    private function sweepEffectTargets(): void
    {
        $this->effectTargetsTaken = [];
        foreach ($this->effectTargetsUsed as $key => $frame) {
            if ($this->frameIndex - $frame > self::EFFECT_TARGET_KEEP_FRAMES) {
                unset($this->effectTargets[$key], $this->effectTargetsUsed[$key]);
            }
        }
    }

    /**
     * Render the frame's effects into their targets, then bind the 2D batch's target
     * again. Runs after the 3D present and before vio_draw_2d(): the batch samples the
     * targets when it draws, and nothing of the 3D frame is bound any more.
     */
    private function runEffects(): void
    {
        PerfProfiler::begin('render2d.effects');
        $quad = $this->effectQuad;
        foreach ($this->effectJobs as [$effect, $target, $w, $h, $parameters, $textures, $rect, $screen]) {
            $pipeline = $this->effectPipelines[$effect->name] ?? null;
            if ($pipeline === null || $quad === null) {
                continue;
            }
            $entry = EffectRegistry::get($effect->name);
            vio_bind_render_target($this->ctx, $target);
            vio_viewport($this->ctx, 0, 0, $w, $h);
            vio_bind_pipeline($this->ctx, $pipeline);
            foreach ($effect->samplers as $slot => $sampler) {
                if ($sampler === BuiltinEffects2D::BACKDROP_SAMPLER && !isset($textures[$sampler])) {
                    // The last 3D frame behind the effect (shader plan 8.1).
                    $this->bindBackdrop($slot, $screen);
                    continue;
                }
                $texture = isset($textures[$sampler]) ? $this->getVioTexture($textures[$sampler]) : null;
                // A declared sampler is always bound: an unbound one reads an empty SRV on D3D12.
                $texture ??= $this->effectWhite ??= (vio_texture($this->ctx, ['data' => "\xff\xff\xff\xff", 'width' => 1, 'height' => 1]) ?: null);
                if ($texture !== null) {
                    vio_set_uniform($this->ctx, $sampler, $slot);
                    vio_bind_texture($this->ctx, $texture, $slot);
                }
            }
            vio_set_uniform($this->ctx, 'u_resolution', [(float) $w, (float) $h]);
            vio_set_uniform($this->ctx, 'u_rect', $rect);
            vio_set_uniform($this->ctx, 'u_time', EffectRegistry::timeFrozen($entry) || EffectRegistry::flashLimited($entry) ? 0.0 : $this->animationTime);
            vio_set_uniform($this->ctx, 'u_motion', EffectRegistry::motionScale($entry));
            foreach ($parameters as $uniform => $value) {
                vio_set_uniform($this->ctx, $uniform, $value);
            }
            vio_draw($this->ctx, $quad);
            vio_unbind_render_target($this->ctx);
        }
        $this->effectJobs = [];
        if ($this->offscreenWarmTarget !== null) {
            $this->offscreenWarmTarget->bindForDraw();
        }
        vio_viewport($this->ctx, 0, 0, $this->framebufferSize[0], $this->framebufferSize[1]);
        PerfProfiler::end();
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

        $roundedSize = (int)$size;
        $scale = $this->fontRenderScale;
        $key = $this->fontCacheKey($name, $roundedSize);

        if (isset($this->fontCache[$key])) {
            return $this->fontCache[$key];
        }

        // Async-registered font: kick off (or wait on) a background load instead
        // of blocking the render thread on the atlas pack. Returns null while
        // the worker is still busy, so the caller skips this font in the chain.
        if (isset($this->asyncFontNames[$name])) {
            if (!isset($this->pendingFontLoads[$key])) {
                $handle = vio_font_load_async($this->ctx, $this->fontFile($name), (float)$roundedSize, $scale);
                if ($handle === false) {
                    // Couldn't even start the worker (e.g. thread-create
                    // failure). Fall back to a synchronous load so the font
                    // still appears rather than being lost forever.
                    $font = vio_font($this->ctx, $this->fontFile($name), (float)$roundedSize, $scale);
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

        $font = $this->openFont($name, $this->fontFile($name), (float)$roundedSize, $scale);
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
    private function resolveFontChain(float $size, string $text = ''): array
    {
        $primary = $this->resolveFont($size);
        if ($primary === null) {
            return [];
        }
        $chain = [$primary];
        // Lazy fallback resolution: only resolve (and thereby rasterise + upload)
        // the fallback atlases when the text actually contains a codepoint the
        // primary can't cover. Resolving them eagerly meant every drawText() of
        // plain Latin/Western text still loaded the full CJK/Arabic/Thai fallback
        // chain (~16 MB atlas each) — pure waste, and a multi-second synchronous
        // pack when the async worker can't start. Empty text has no glyphs at all
        // (an immediate-mode button hit-tested with a '' label measures/draws it),
        // so it never needs a fallback either — treat it like plain Latin.
        if ($text === '' || !self::textNeedsFallback($text)) {
            return $chain;
        }
        // The byte scan over-triggers: it flags any codepoint >= U+0500, but the
        // primary font often still covers it — an em dash, ellipsis or curly
        // quote all live in a Western UI face like Inter. Resolving the fallback
        // chain for such a string needlessly loads the multi-megabyte
        // CJK/Arabic/Thai atlases (a multi-second SYNCHRONOUS pack when the async
        // worker can't start), which stalled the first frame that drew, say, a
        // tagline with an em dash. Skip the chain when the primary already covers
        // every flagged character. Per-(font,char) coverage is memoised in
        // fontCoversChar(), so after the first frame this is a couple of cheap
        // hashmap hits.
        // $text is guaranteed non-empty here (the $text === '' case returned above).
        if ($this->primaryCoversFlagged($primary, $text)) {
            return $chain;
        }
        $fallbacks = $this->fallbackFonts[$this->currentFontName] ?? [];
        foreach ($fallbacks as $fbName) {
            $fb = $this->resolveFontByName($fbName, $size);
            if ($fb !== null) {
                $chain[] = $fb;
            }
        }
        return $chain;
    }

    /**
     * True if the primary font covers every character that
     * {@see textNeedsFallback} would flag (codepoint >= U+0500). Lets a string
     * the cheap byte scan flagged — but the primary actually renders (em dash,
     * ellipsis, curly quotes) — skip the expensive fallback-chain resolution and
     * the atlas loads it triggers. Only flagged characters are probed; ASCII /
     * Latin-1 is always covered. Coverage is memoised in {@see fontCoversChar}.
     */
    private function primaryCoversFlagged(\VioFont $primary, string $text): bool
    {
        foreach (\mb_str_split($text) as $ch) {
            if (self::textNeedsFallback($ch) && !$this->fontCoversChar($primary, $ch)) {
                return false;
            }
        }
        return true;
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
        $argb = ($a << 24) | ($r << 16) | ($g << 8) | $b;
        if ($this->drawColorFilter !== null && $this->colorVisionReaches($color)) {
            return $this->drawColorFilter->argb($argb);
        }
        return $argb;
    }

    private function colorVisionReaches(Color $color): bool
    {
        return $this->colorVisionScope === true || ($this->colorVisionScope instanceof \Closure && ($this->colorVisionScope)($color) === true);
    }
}
