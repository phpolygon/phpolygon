<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

use VioContext;
use VioMesh;
use VioPipeline;
use VioTexture;

/**
 * vio FXAA post-process pass.
 *
 * Runs an FXAA fragment shader that samples a single-sample colour texture
 * (the offscreen target) and writes anti-aliased pixels to the bound render
 * target (or the swapchain when unbound).
 *
 * Unlike the OpenGL FXAA pass, this one uses an explicit fullscreen quad
 * mesh (`a_position`+`a_uv` layout) because vio's GLSL transpilation path
 * does not support the `gl_VertexID` trick consistently across all
 * backends (D3D11/D3D12/Metal/Vulkan).
 *
 * Lifecycle: shader + pipeline are compiled on first `apply()`. The screen
 * quad is owned by VioRenderer3D and passed in - this class never creates
 * GPU geometry. Resources are released via `release()`.
 */
final class VioFxaaPass
{
    private const SHADER_DIR = __DIR__ . '/../../../resources/shaders/source/vio/';

    private bool $initialised = false;
    private VioPipeline|false|null $pipeline = null;

    public function __construct(
        private readonly VioContext $ctx,
    ) {
    }

    /**
     * Run the FXAA pass.
     *
     * Caller binds the destination render target (or unbinds for swapchain),
     * sets the viewport to the destination resolution, then invokes apply().
     * `$inputTexture` must be the colour texture of the offscreen target;
     * `$sourceWidth`/`$sourceHeight` are its pixel dimensions (for the
     * `1/resolution` uniform).
     */
    public function apply(
        VioTexture $inputTexture,
        int $sourceWidth,
        int $sourceHeight,
        VioMesh $screenQuad,
        ?VioTexture $bloomTexture = null,
        float $bloomIntensity = 0.0,
        ?array $post = null,
    ): void {
        if (!$this->initialised) {
            $this->initialise();
        }

        if ($this->pipeline === null || $this->pipeline === false) {
            return;
        }

        $invW = $sourceWidth  > 0 ? 1.0 / (float)$sourceWidth  : 0.0;
        $invH = $sourceHeight > 0 ? 1.0 / (float)$sourceHeight : 0.0;

        vio_bind_pipeline($this->ctx, $this->pipeline);
        vio_bind_texture($this->ctx, $inputTexture, 0);
        vio_set_uniform($this->ctx, 'u_color_texture', 0);
        vio_set_uniform($this->ctx, 'u_inverse_resolution', [$invW, $invH]);

        // Additive bloom composited in the same pass (0 intensity → shader skips).
        if ($bloomTexture !== null && $bloomIntensity > 0.0) {
            vio_bind_texture($this->ctx, $bloomTexture, 1);
            vio_set_uniform($this->ctx, 'u_bloom', 1);
            vio_set_uniform($this->ctx, 'u_bloom_intensity', $bloomIntensity);
        } else {
            vio_set_uniform($this->ctx, 'u_bloom_intensity', 0.0);
        }

        // Full-screen finishing (colour grade + vignette). Always set — the
        // shader's gamma uniform must never be 0 (→ pow blows up → black).
        // Null = identity (Neutral grade, no vignette).
        $p = $post ?? [];
        vio_set_uniform($this->ctx, 'u_grade_lift',       $p['lift']       ?? [0.0, 0.0, 0.0]);
        vio_set_uniform($this->ctx, 'u_grade_gamma',      $p['gamma']      ?? [1.0, 1.0, 1.0]);
        vio_set_uniform($this->ctx, 'u_grade_gain',       $p['gain']       ?? [1.0, 1.0, 1.0]);
        vio_set_uniform($this->ctx, 'u_grade_saturation', $p['saturation'] ?? 1.0);
        vio_set_uniform($this->ctx, 'u_vignette_intensity', $p['vignette'] ?? 0.0);
        vio_set_uniform($this->ctx, 'u_viewport_size',    $p['viewport']   ?? [0.0, 0.0]);

        // HDR resolve: when the offscreen scene was FP16 linear, FXAA's
        // finishPost() applies exposure + ACES tonemap + gamma after bloom and
        // before grade/vignette. Off → LDR behaviour, unchanged.
        vio_set_uniform($this->ctx, 'u_hdr_resolve', $p['hdr']      ?? 0);
        vio_set_uniform($this->ctx, 'u_exposure',    $p['exposure'] ?? 1.0);

        vio_draw($this->ctx, $screenQuad);
    }

    public function release(): void
    {
        // vio releases shader + pipeline when references drop.
        $this->pipeline    = null;
        $this->initialised = false;
    }

    private function initialise(): void
    {
        // VIO_SHADER_GLSL_RAW is OpenGL passthrough; on Metal/Vulkan/D3D vio
        // transpiles via VIO_SHADER_GLSL. Mirror VioRenderer3D::compileShader().
        $format = \PHPolygon\Rendering\BackendConventions::forBackend(
            vio_backend_name($this->ctx)
        )->shaderSourceFormat();

        $vertSrc = @file_get_contents(self::SHADER_DIR . 'fxaa.vert.glsl');
        $fragSrc = @file_get_contents(self::SHADER_DIR . 'fxaa.frag.glsl');
        if ($vertSrc === false || $fragSrc === false) {
            // Throw instead of silently no-opping. A missing shader file means
            // a broken install or build manifest; users would otherwise see
            // "AA enabled" in settings while getting unprocessed output.
            throw new \RuntimeException(
                'VioFxaaPass: failed to read fxaa shader sources from ' . self::SHADER_DIR
            );
        }

        $shader = vio_shader($this->ctx, [
            'vertex'   => $vertSrc,
            'fragment' => $fragSrc,
            'format'   => $format,
        ]);

        if ($shader === false) {
            $this->pipeline    = false;
            $this->initialised = true;
            fwrite(STDERR, "[VioFxaaPass] shader compile failed (format={$format}).\n");
            return;
        }

        $pipeline = vio_pipeline($this->ctx, [
            'shader'     => $shader,
            'depth_test' => false,
            'cull_mode'  => VIO_CULL_NONE,
            'blend'      => VIO_BLEND_NONE,
        ]);

        if ($pipeline === false) {
            $this->pipeline    = false;
            $this->initialised = true;
            fwrite(STDERR, "[VioFxaaPass] pipeline create failed.\n");
            return;
        }

        $this->pipeline    = $pipeline;
        $this->initialised = true;
    }
}
