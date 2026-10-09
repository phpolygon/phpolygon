<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

use PHPolygon\Rendering\BackendConventions;
use VioContext;
use VioMesh;
use VioPipeline;
use VioTexture;

/**
 * Fullscreen debug view of the temporal inputs, selected with the env var
 * PHPOLYGON_VIO_DEBUG_VIEW and drawn by VioRenderer3D::endFrame() over the
 * presented frame:
 *
 *   motion    per-pixel motion vectors (hue = direction, saturation = length)
 *   reactive  the transparent coverage the temporal resolve trusts less
 *   depth     the scene depth, linearised
 *   history   the temporal resolve's accumulated colour
 *
 * Only while a temporal technique runs (TAA / temporal upscaling); otherwise
 * the inputs do not exist and the frame is left alone. Shader + pipeline are
 * built on first use; the fullscreen quad is owned by the renderer.
 */
final class VioMotionDebugPass
{
    public const ENV = 'PHPOLYGON_VIO_DEBUG_VIEW';

    /** @var array<string, int> view name -> u_mode */
    public const VIEWS = ['motion' => 0, 'reactive' => 1, 'depth' => 2, 'history' => 3];

    private const SHADER_DIR = __DIR__ . '/../../../resources/shaders/source/vio/';

    private VioPipeline|false|null $pipeline = null;

    public function __construct(
        private readonly VioContext $ctx,
    ) {
    }

    /** The view selected by PHPOLYGON_VIO_DEBUG_VIEW, null when unset or unknown. */
    public static function selectedView(): ?string
    {
        $view = getenv(self::ENV);
        if (!is_string($view)) {
            return null;
        }
        $view = strtolower(trim($view));
        return isset(self::VIEWS[$view]) ? $view : null;
    }

    /**
     * Draw $source fullscreen into the bound target, interpreted as $view.
     *
     * @param array{0: float, 1: float} $sourceSize texels of $source (motion: uv -> px)
     * @param array<float>              $invProjection unjittered inverse projection (depth)
     */
    public function draw(
        string $view,
        VioTexture $source,
        VioMesh $screenQuad,
        array $sourceSize,
        array $invProjection,
        float $far,
        bool $hdr,
    ): void {
        $pipeline = $this->pipeline();
        if ($pipeline === null) {
            return;
        }
        vio_bind_pipeline($this->ctx, $pipeline);
        vio_bind_texture($this->ctx, $source, 0);
        vio_set_uniform($this->ctx, 'u_source', 0);
        vio_set_uniform($this->ctx, 'u_mode', (float) (self::VIEWS[$view] ?? 0));
        vio_set_uniform($this->ctx, 'u_scale', 8.0);
        vio_set_uniform($this->ctx, 'u_source_size', $sourceSize);
        vio_set_uniform($this->ctx, 'u_inv_proj', $invProjection);
        vio_set_uniform($this->ctx, 'u_far', $far);
        vio_set_uniform($this->ctx, 'u_hdr', $hdr ? 1.0 : 0.0);
        vio_draw($this->ctx, $screenQuad);
    }

    private function pipeline(): ?VioPipeline
    {
        if ($this->pipeline === null) {
            $this->pipeline = $this->build();
        }
        return $this->pipeline === false ? null : $this->pipeline;
    }

    private function build(): VioPipeline|false
    {
        $vert = @file_get_contents(self::SHADER_DIR . 'postprocess.vert.glsl');
        $frag = @file_get_contents(self::SHADER_DIR . 'temporal_debug.frag.glsl');
        if ($vert === false || $frag === false) {
            fwrite(STDERR, "[VioMotionDebugPass] failed to read the debug shader sources.\n");
            return false;
        }
        $shader = vio_shader($this->ctx, [
            'vertex' => $vert,
            'fragment' => $frag,
            'format' => BackendConventions::forBackend(vio_backend_name($this->ctx))->shaderSourceFormat(),
        ]);
        if ($shader === false) {
            fwrite(STDERR, "[VioMotionDebugPass] shader compile failed.\n");
            return false;
        }
        return vio_pipeline($this->ctx, [
            'shader' => $shader,
            'depth_test' => false,
            'cull_mode' => VIO_CULL_NONE,
            'blend' => VIO_BLEND_NONE,
        ]);
    }
}
