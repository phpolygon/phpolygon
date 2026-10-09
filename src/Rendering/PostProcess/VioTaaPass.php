<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

use PHPolygon\Rendering\BackendConventions;
use PHPolygon\Rendering\Quality\TemporalFrame;
use VioContext;
use VioMesh;
use VioPipeline;
use VioRenderTarget;
use VioTexture;

/**
 * Temporal anti-aliasing and temporal upsampling (TAA / TAAU) on php-vio.
 *
 * Two fragment passes, so it runs on every backend with a fragment stage:
 *
 *   taa_dilate   render resolution: nearest-depth motion of the 3x3
 *                neighbourhood (sky motion reconstructed from the depth), the
 *                linear depth and the depth the surface had last frame.
 *   taa_resolve  display resolution: jitter-aware reconstruction of the current
 *                frame, Catmull-Rom history fetch at uv + motion, YCoCg variance
 *                clipping, disocclusion against last frame's depth, Karis-weighted
 *                blend; the reactive mask raises the current frame's weight.
 *
 * Both write FP16 targets that ping-pong between frames: the resolve's output is
 * next frame's history, the dilate output next frame's depth reference. A frame
 * whose {@see TemporalFrame::$historyValid} is false (first frame, cut, resize,
 * settings change) starts over from the current frame alone.
 */
final class VioTaaPass
{
    private const SHADER_DIR = __DIR__ . '/../../../resources/shaders/source/vio/';

    /**
     * Cap of the sample weight the history accumulates (an exact sample hit
     * weighs 1): still surfaces / moving surfaces. 12 still is about a
     * 30-frame memory at the average native-resolution weight.
     */
    public const HISTORY_WEIGHT_STATIC = 12.0;
    public const HISTORY_WEIGHT_MOVING = 4.0;
    public const REACTIVE_STRENGTH = 0.6;
    /** Variance box size in standard deviations: moving surfaces / still surfaces. */
    public const VARIANCE_GAMMA = 1.0;
    public const VARIANCE_GAMMA_STATIC = 2.5;

    private VioPipeline|false|null $dilatePipeline = null;
    private VioPipeline|false|null $resolvePipeline = null;

    /** @var array{0: VioRenderTarget|null, 1: VioRenderTarget|null} */
    private array $dilated = [null, null];
    /** @var array{0: VioRenderTarget|null, 1: VioRenderTarget|null} */
    private array $history = [null, null];
    private int $current = 0;
    /** @var array<int, VioTexture> colour textures of the ping-pong targets, by target object id */
    private array $textures = [];
    private ?\PHPolygon\Math\Mat4 $invProjectionOf = null;
    /** @var array<float> */
    private array $invProjection = [];
    private string $sizeKey = '';
    /** Both ping-pong slots hold frames of the current size (the previous one is usable). */
    private bool $primed = false;

    public function __construct(
        private readonly VioContext $ctx,
        private readonly BackendConventions $conventions,
    ) {
    }

    /** Both passes compiled (built on first call); false when this backend cannot run them. */
    public function ready(): bool
    {
        return $this->pipeline('dilate') !== null && $this->pipeline('resolve') !== null;
    }

    /**
     * Resolve this frame into the display-size history target and return it,
     * or null when a target or program is unavailable (the caller then presents
     * the scene colour as is). Leaves no render target bound.
     */
    public function apply(
        VioTexture $color,
        VioTexture $motion,
        VioTexture $depth,
        VioTexture $reactive,
        int $renderW,
        int $renderH,
        int $displayW,
        int $displayH,
        TemporalFrame $frame,
        VioMesh $quad,
    ): ?VioTexture {
        $dilate = $this->pipeline('dilate');
        $resolve = $this->pipeline('resolve');
        if ($dilate === null || $resolve === null) {
            return null;
        }
        $this->ensureTargets($renderW, $renderH, $displayW, $displayH);
        $next = 1 - $this->current;
        $dilatedOut = $this->dilated[$next];
        $historyOut = $this->history[$next];
        $prevDilated = $this->dilated[$this->current];
        $prevHistory = $this->history[$this->current];
        if ($dilatedOut === null || $historyOut === null || $prevDilated === null || $prevHistory === null) {
            return null;
        }

        $flipY = $this->conventions->flipRenderTargetClipY() ? -1.0 : 1.0;
        $jitterPx = [$frame->jitter->pixelX, $frame->jitter->pixelY * $flipY];
        $historyValid = $frame->historyValid && $this->primed;

        // The inverse projection only changes with the camera's projection.
        $projection = $frame->jitter->unjitteredProjection;
        if ($projection !== $this->invProjectionOf) {
            $this->invProjectionOf = $projection;
            $this->invProjection = $projection->inverse()->toArray();
        }

        // 1. Dilate (render resolution).
        vio_bind_render_target($this->ctx, $dilatedOut);
        vio_viewport($this->ctx, 0, 0, $renderW, $renderH);
        vio_bind_pipeline($this->ctx, $dilate);
        vio_bind_texture($this->ctx, $motion, 0);
        vio_bind_texture($this->ctx, $depth, 1);
        $this->setUniforms([
            'u_motion' => 0,
            'u_depth' => 1,
            'u_texel' => [1.0 / $renderW, 1.0 / $renderH],
            'u_jitter_uv' => [$jitterPx[0] / $renderW, $jitterPx[1] / $renderH],
            'u_uv_flip_y' => $flipY,
            'u_reprojection' => $frame->reprojection->toArray(),
            'u_inv_proj' => $this->invProjection,
        ]);
        vio_draw($this->ctx, $quad);
        vio_unbind_render_target($this->ctx);

        // 2. Resolve (display resolution).
        vio_bind_render_target($this->ctx, $historyOut);
        vio_viewport($this->ctx, 0, 0, $displayW, $displayH);
        vio_bind_pipeline($this->ctx, $resolve);
        vio_bind_texture($this->ctx, $color, 0);
        vio_bind_texture($this->ctx, $this->texture($prevHistory), 1);
        vio_bind_texture($this->ctx, $this->texture($dilatedOut), 2);
        vio_bind_texture($this->ctx, $this->texture($prevDilated), 3);
        vio_bind_texture($this->ctx, $reactive, 4);
        $this->setUniforms([
            'u_color' => 0,
            'u_history' => 1,
            'u_dilated' => 2,
            'u_prev_dilated' => 3,
            'u_reactive' => 4,
            'u_render_size' => [(float) $renderW, (float) $renderH],
            'u_display_size' => [(float) $displayW, (float) $displayH],
            'u_jitter_px' => $jitterPx,
            'u_history_valid' => $historyValid ? 1.0 : 0.0,
            'u_history_weight_static' => self::HISTORY_WEIGHT_STATIC,
            'u_history_weight_moving' => self::HISTORY_WEIGHT_MOVING,
            'u_reactive_strength' => self::REACTIVE_STRENGTH,
            'u_variance_gamma' => self::VARIANCE_GAMMA,
            'u_variance_gamma_static' => self::VARIANCE_GAMMA_STATIC,
        ]);
        vio_draw($this->ctx, $quad);
        vio_unbind_render_target($this->ctx);

        $this->current = $next;
        $this->primed = true;
        return $this->texture($historyOut);
    }

    /** The colour texture of one of the pass's own targets (looked up once per target). */
    private function texture(VioRenderTarget $target): VioTexture
    {
        $id = spl_object_id($target);
        return $this->textures[$id] ??= vio_render_target_texture($target);
    }

    /**
     * One native call per pass where php-vio has vio_set_uniforms.
     *
     * @param array<string, int|float|array<float>> $uniforms
     */
    private function setUniforms(array $uniforms): void
    {
        if (function_exists('vio_set_uniforms')) {
            vio_set_uniforms($this->ctx, $uniforms);
            return;
        }
        foreach ($uniforms as $name => $value) {
            vio_set_uniform($this->ctx, $name, $value);
        }
    }

    /** The latest resolve (debug view), null before the first frame. */
    public function historyTexture(): ?VioTexture
    {
        $rt = $this->history[$this->current];
        if ($rt === null || !$this->primed) {
            return null;
        }
        return $this->texture($rt);
    }

    /** The latest resolve as a render target (tests read it back), null before the first frame. */
    public function historyTarget(): ?VioRenderTarget
    {
        return $this->primed ? $this->history[$this->current] : null;
    }

    public function release(): void
    {
        $this->dilated = [null, null];
        $this->history = [null, null];
        $this->textures = [];
        $this->sizeKey = '';
        $this->primed = false;
    }

    private function ensureTargets(int $renderW, int $renderH, int $displayW, int $displayH): void
    {
        $key = "{$renderW}x{$renderH}:{$displayW}x{$displayH}";
        if ($key === $this->sizeKey) {
            return;
        }
        // A new size: the history is gone (the temporal camera also reports
        // the resize, so the first resolve ignores it either way).
        $this->primed = false;
        $this->sizeKey = $key;
        $this->textures = [];
        for ($i = 0; $i < 2; $i++) {
            $this->dilated[$i] = vio_render_target($this->ctx, [
                'width' => max(1, $renderW), 'height' => max(1, $renderH), 'hdr' => true,
            ]) ?: null;
            $this->history[$i] = vio_render_target($this->ctx, [
                'width' => max(1, $displayW), 'height' => max(1, $displayH), 'hdr' => true,
            ]) ?: null;
        }
    }

    /** @param 'dilate'|'resolve' $which */
    private function pipeline(string $which): ?VioPipeline
    {
        if ($which === 'dilate') {
            $this->dilatePipeline ??= $this->build('taa_dilate.frag.glsl');
            return $this->dilatePipeline === false ? null : $this->dilatePipeline;
        }
        $this->resolvePipeline ??= $this->build('taa_resolve.frag.glsl');
        return $this->resolvePipeline === false ? null : $this->resolvePipeline;
    }

    private function build(string $fragFile): VioPipeline|false
    {
        $vert = @file_get_contents(self::SHADER_DIR . 'postprocess.vert.glsl');
        $frag = @file_get_contents(self::SHADER_DIR . $fragFile);
        if ($vert === false || $frag === false) {
            fwrite(STDERR, "[VioTaaPass] failed to read {$fragFile}\n");
            return false;
        }
        $shader = vio_shader($this->ctx, [
            'vertex' => $vert,
            'fragment' => $frag,
            'format' => $this->conventions->shaderSourceFormat(),
        ]);
        if ($shader === false) {
            fwrite(STDERR, "[VioTaaPass] {$fragFile} failed to compile\n");
            return false;
        }
        // Both write FP16 targets (D3D12 bakes the RTV format into the PSO).
        return vio_pipeline($this->ctx, [
            'shader' => $shader,
            'depth_test' => false,
            'cull_mode' => VIO_CULL_NONE,
            'blend' => VIO_BLEND_NONE,
            'hdr' => true,
        ]);
    }
}
