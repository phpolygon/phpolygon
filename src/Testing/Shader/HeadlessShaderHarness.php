<?php

declare(strict_types=1);

namespace PHPolygon\Testing\Shader;

use VioContext;
use VioMesh;
use VioPipeline;
use VioRenderTarget;
use VioShader;

/**
 * Runs a real GLSL shader against a real GPU context for tests.
 *
 * vio supports a `'headless' => true` flag on `vio_create()` that opens an
 * invisible window + offscreen framebuffer; combined with `vio_render_target`
 * and `vio_read_pixels` this gives us a fully self-contained shader execution
 * environment. Tests assert on the actual byte output of the fragment shader -
 * no PHP simulation, no compiler heuristics, no shadow-of-the-shader. The
 * `u_grade_gamma == 0` regression that took an evening of black-screen
 * debugging would surface as a one-line failed assertion here.
 *
 * Lifecycle:
 *   $h = HeadlessShaderHarness::open(128, 128);          // 1× per test class
 *   if ($h === null) { $this->markTestSkipped(...); }
 *   $shader   = $h->compileShaderFromFiles($v, $f);
 *   $pipeline = $h->createPipeline($shader);
 *   $mesh     = $h->fullscreenQuad();
 *   $pixels   = $h->renderAndRead($pipeline, $mesh, fn(...) => $h->setUniform(...));
 *   $h->close();
 *
 * The harness is intentionally small: it never imports `Engine` or any
 * scene/world abstraction, so tests stay fast and stable.
 */
final class HeadlessShaderHarness
{
    private VioContext $ctx;

    /** @var array<string, VioShader> */
    private array $shaderCache = [];

    /** @var array<string, VioPipeline> */
    private array $pipelineCache = [];

    /** @var array<string, VioMesh> */
    private array $meshCache = [];

    /** Lazy 1x1 depth render target, created on first bindDummyShadowSamplers() call. */
    private ?VioRenderTarget $dummyShadowTarget = null;

    private function __construct(
        VioContext $ctx,
        public readonly int $width,
        public readonly int $height,
    ) {
        $this->ctx = $ctx;
    }

    /**
     * Open a hidden vio context with an offscreen framebuffer of the
     * requested size. Returns null when vio cannot create an OpenGL context
     * (no GPU + no software renderer in CI). Callers should
     * `$this->markTestSkipped(...)` on null.
     *
     * The harness renders into vio's built-in headless framebuffer rather
     * than a separate render target, because `vio_read_pixels()` is wired
     * to read from `ctx->headless_fbo` specifically - using a custom
     * render target results in vio_read_pixels returning the clear colour
     * of the headless FBO instead of the rendered content.
     */
    public static function open(int $width = 64, int $height = 64): ?self
    {
        if (!extension_loaded('vio')) {
            return null;
        }

        $ctx = vio_create('opengl', [
            'width'    => $width,
            'height'   => $height,
            'title'    => 'phpolygon-shader-test',
            'vsync'    => false,
            'headless' => true,
        ]);
        if ($ctx === false) {
            return null;
        }

        $harness = new self($ctx, $width, $height);

        // Pre-allocate the dummy depth render target outside any vio_begin/end
        // pair. vio_render_target binds the created FBO and then resets to
        // FBO 0; in headless mode FBO 0 is the swapchain (not the
        // headless_fbo), so a create call mid-frame leaves subsequent draws
        // going nowhere. Creating it once up front avoids that.
        $dummy = vio_render_target($ctx, [
            'width'      => $width,
            'height'     => $height,
            'depth_only' => true,
        ]);
        if ($dummy !== false) {
            $harness->dummyShadowTarget = $dummy;
        }

        return $harness;
    }

    public function close(): void
    {
        $this->shaderCache   = [];
        $this->pipelineCache = [];
        $this->meshCache     = [];
        vio_destroy($this->ctx);
    }

    /**
     * Compile a shader from on-disk GLSL files relative to the engine's
     * shader source directory (`resources/shaders/source/`). Cached by
     * (vertex, fragment) path pair so subsequent tests reuse the program.
     */
    public function compileShaderFromFiles(string $vertexFile, string $fragmentFile): VioShader
    {
        $key = $vertexFile . '|' . $fragmentFile;
        if (isset($this->shaderCache[$key])) {
            return $this->shaderCache[$key];
        }

        $base = __DIR__ . '/../../../resources/shaders/source/';
        $vertSrc = @file_get_contents($base . $vertexFile);
        $fragSrc = @file_get_contents($base . $fragmentFile);
        if ($vertSrc === false || $fragSrc === false) {
            throw new \RuntimeException(
                "Shader source not found: vertex={$vertexFile}, fragment={$fragmentFile}",
            );
        }

        $shader = vio_shader($this->ctx, [
            'vertex'   => $vertSrc,
            'fragment' => $fragSrc,
            'format'   => VIO_SHADER_GLSL_RAW,
        ]);
        if ($shader === false) {
            throw new \RuntimeException("vio_shader failed for {$key}");
        }

        $this->shaderCache[$key] = $shader;
        return $shader;
    }

    /**
     * Compile a shader from in-memory GLSL source. Useful for one-off
     * minimal-shader regression tests (sanity-check the harness itself).
     */
    public function compileShaderFromSource(string $key, string $vertSrc, string $fragSrc): VioShader
    {
        if (isset($this->shaderCache[$key])) {
            return $this->shaderCache[$key];
        }
        $shader = vio_shader($this->ctx, [
            'vertex'   => $vertSrc,
            'fragment' => $fragSrc,
            'format'   => VIO_SHADER_GLSL_RAW,
        ]);
        if ($shader === false) {
            throw new \RuntimeException("vio_shader failed for inline shader '{$key}'");
        }
        $this->shaderCache[$key] = $shader;
        return $shader;
    }

    /** @param int $cullMode one of VIO_CULL_*; @param int $blend one of VIO_BLEND_*. */
    public function createPipeline(
        VioShader $shader,
        bool $depthTest = false,
        int $cullMode = VIO_CULL_NONE,
        int $blend = VIO_BLEND_NONE,
    ): VioPipeline {
        $key = spl_object_id($shader) . ":{$depthTest}:{$cullMode}:{$blend}";
        if (isset($this->pipelineCache[$key])) {
            return $this->pipelineCache[$key];
        }
        $pipeline = vio_pipeline($this->ctx, [
            'shader'     => $shader,
            'depth_test' => $depthTest,
            'cull_mode'  => $cullMode,
            'blend'      => $blend,
        ]);
        if ($pipeline === false) {
            throw new \RuntimeException("vio_pipeline failed");
        }
        $this->pipelineCache[$key] = $pipeline;
        return $pipeline;
    }

    /**
     * A single quad covering the whole offscreen target in NDC.
     * Layout: position(vec3) + normal(vec3) + uv(vec2).
     */
    public function fullscreenQuad(): VioMesh
    {
        if (isset($this->meshCache['fullscreen_quad'])) {
            return $this->meshCache['fullscreen_quad'];
        }
        $mesh = vio_mesh($this->ctx, [
            'vertices' => [
                -1.0, -1.0, 0.0,  0.0, 0.0, 1.0,  0.0, 0.0,
                 1.0, -1.0, 0.0,  0.0, 0.0, 1.0,  1.0, 0.0,
                 1.0,  1.0, 0.0,  0.0, 0.0, 1.0,  1.0, 1.0,
                -1.0,  1.0, 0.0,  0.0, 0.0, 1.0,  0.0, 1.0,
            ],
            'indices' => [0, 1, 2, 0, 2, 3],
            'layout'  => [VIO_FLOAT3, VIO_FLOAT3, VIO_FLOAT2],
        ]);
        if ($mesh === false) {
            throw new \RuntimeException("vio_mesh failed for fullscreen quad");
        }
        $this->meshCache['fullscreen_quad'] = $mesh;
        return $mesh;
    }

    /**
     * Bind the offscreen target, clear, set uniforms via the passed callable,
     * draw the mesh, read back RGBA pixels.
     *
     * @param callable(self): void $setUniforms callback that receives this
     *        harness so the test can call setUniform without exposing the
     *        VioContext directly.
     * @return string Width*Height*4 bytes RGBA.
     */
    public function renderAndRead(
        VioPipeline $pipeline,
        VioMesh $mesh,
        callable $setUniforms,
        float $clearR = 0.0,
        float $clearG = 0.0,
        float $clearB = 0.0,
        float $clearA = 1.0,
    ): string {
        vio_begin($this->ctx);
        vio_viewport($this->ctx, 0, 0, $this->width, $this->height);
        vio_clear($this->ctx, $clearR, $clearG, $clearB, $clearA);
        vio_bind_pipeline($this->ctx, $pipeline);
        $setUniforms($this);
        vio_draw($this->ctx, $mesh);
        $pixels = vio_read_pixels($this->ctx);
        vio_end($this->ctx);

        return $pixels;
    }

    /**
     * Forwards to vio_set_uniform. Just a convenience so tests don't carry the ctx.
     *
     * @param int|float|list<int|float> $value
     */
    public function setUniform(string $name, int|float|array $value): void
    {
        vio_set_uniform($this->ctx, $name, $value);
    }

    /**
     * Bind a 1x1 depth texture to every sampler2DShadow referenced by the
     * mesh3d shader (u_csm_map_0..2, u_shadow_map), and set the matching
     * uniform sampler indices.
     *
     * Why this is needed:
     *   - macOS OpenGL (and likely other drivers) requires every declared
     *     sampler in a shader to be bound to a valid texture *of the
     *     matching type* before fragment execution, even if the static
     *     control flow never reaches the sampling instruction.
     *   - When the engine renders a scene without shadows, it leaves the
     *     shadow samplers unbound. On macOS headless this causes the
     *     fragment shader to bail out silently, leaving the framebuffer
     *     at a small uniform colour (~0.098 grey here).
     *   - The fix in production is to either always bind a dummy or never
     *     reference a sampler the engine doesn't intend to use. For the
     *     headless test harness, binding a dummy is sufficient.
     */
    public function bindDummyShadowSamplers(): void
    {
        if ($this->dummyShadowTarget === null) {
            // open() should have created this; if it didn't, the harness
            // can't satisfy a shader that declares sampler2DShadow.
            return;
        }
        $tex = vio_render_target_texture($this->dummyShadowTarget);
        // Match the engine's texture unit choices for the cascade samplers
        // so a future test that mixes in real shadow data does not clash.
        foreach ([6, 8, 9] as $unit) {
            vio_bind_texture($this->ctx, $tex, $unit);
        }
        $this->setUniform('u_csm_map_0',  6);
        $this->setUniform('u_csm_map_1',  8);
        $this->setUniform('u_csm_map_2',  9);
        $this->setUniform('u_shadow_map', 6);
    }

    /**
     * Read the RGBA value at a single pixel from a buffer returned by
     * renderAndRead. Returns floats in [0, 1].
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function samplePixel(string $rgba, int $x, int $y): array
    {
        $offset = ($y * $this->width + $x) * 4;
        if ($offset + 3 >= strlen($rgba)) {
            throw new \OutOfRangeException("pixel ({$x}, {$y}) out of buffer range");
        }
        $r = ord($rgba[$offset    ]) / 255.0;
        $g = ord($rgba[$offset + 1]) / 255.0;
        $b = ord($rgba[$offset + 2]) / 255.0;
        $a = ord($rgba[$offset + 3]) / 255.0;
        return [$r, $g, $b, $a];
    }
}
