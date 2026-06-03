<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

use VioContext;
use VioMesh;
use VioTexture;
use VioPipeline;

/**
 * Debug pass that blits a shadow-map depth texture to a screen-space tile as
 * RAW depth (plain sampler2D, no PCF comparison). Enabled via the env var
 * PHPOLYGON_DEBUG_SHADOWMAP=1 and driven from VioRenderer3D::endFrame().
 *
 * Purpose: the in-game dark "disc" is the shadow *comparison* result; this
 * shows the *stored* cascade depth, which is what distinguishes a storage-side
 * bug (offscreen depth RT never populated → uniform/garbage) from a
 * compare-side bug (smooth gradient stored, but the shader compares it under
 * the wrong clip/depth convention).
 *
 * Mirrors {@see VioFxaaPass}: shader + pipeline compiled lazily; the fullscreen
 * quad is owned by VioRenderer3D and passed in. The caller sets a corner
 * viewport before each draw() so the fullscreen quad lands in that tile.
 */
final class VioShadowDebugPass
{
    private const SHADER_DIR = __DIR__ . '/../../../resources/shaders/source/vio/';

    private bool $initialised = false;
    private VioPipeline|false|null $pipeline = null;

    public function __construct(
        private readonly VioContext $ctx,
    ) {
    }

    /**
     * Draw $depthTexture into a screen-space tile. The tile is placed by the
     * vertex shader via $tile = [scaleX, scaleY, offsetX, offsetY] in NDC (so
     * placement does not depend on possibly-deferred viewport state).
     *
     * @param array{0:float,1:float,2:float,3:float} $tile
     */
    public function draw(VioTexture $depthTexture, VioMesh $screenQuad, array $tile): void
    {
        if (!$this->initialised) {
            $this->initialise();
        }
        if ($this->pipeline === null || $this->pipeline === false) {
            return;
        }

        vio_bind_pipeline($this->ctx, $this->pipeline);
        vio_bind_texture($this->ctx, $depthTexture, 0);
        vio_set_uniform($this->ctx, 'u_depth_map', 0);
        vio_set_uniform($this->ctx, 'u_tile', $tile);
        vio_draw($this->ctx, $screenQuad);
    }

    public function release(): void
    {
        $this->pipeline    = null;
        $this->initialised = false;
    }

    private function initialise(): void
    {
        $format = \PHPolygon\Rendering\BackendConventions::forBackend(
            vio_backend_name($this->ctx)
        )->shaderSourceFormat();

        $vertSrc = @file_get_contents(self::SHADER_DIR . 'shadow_debug.vert.glsl');
        $fragSrc = @file_get_contents(self::SHADER_DIR . 'shadow_debug.frag.glsl');
        if ($vertSrc === false || $fragSrc === false) {
            $this->pipeline    = false;
            $this->initialised = true;
            fwrite(STDERR, "[VioShadowDebugPass] failed to read shadow_debug shader sources.\n");
            return;
        }

        $shader = vio_shader($this->ctx, [
            'vertex'   => $vertSrc,
            'fragment' => $fragSrc,
            'format'   => $format,
        ]);
        if ($shader === false) {
            $this->pipeline    = false;
            $this->initialised = true;
            fwrite(STDERR, "[VioShadowDebugPass] shader compile failed (format={$format}).\n");
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
            fwrite(STDERR, "[VioShadowDebugPass] pipeline create failed.\n");
            return;
        }

        $this->pipeline    = $pipeline;
        $this->initialised = true;
    }
}
