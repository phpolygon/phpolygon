<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

use PHPolygon\Rendering\Accessibility\AccessibilitySettings;
use PHPolygon\Rendering\BackendConventions;
use VioContext;
use VioMesh;
use VioPipeline;
use VioRenderTarget;
use VioTexture;

/**
 * The accessibility options of {@see AccessibilitySettings} as one fullscreen draw
 * over the finished image (`a11y_apply.frag.glsl`). VioRenderer3D runs it after the
 * Final-stage passes, before the HDR10 encoding and the UI, and only while an option
 * changes the image: with the defaults there is no pass, no target, no compile.
 *
 * The flash limit and the night boost need the mean luminance of the frame: a chain
 * of 4x4 reductions down to one texel (`a11y_reduce`), then a one-texel state
 * (`a11y_state`) that carries the shown luminance from frame to frame.
 */
final class VioAccessibilityPass
{
    /**
     * Fastest change of the mean relative luminance of the whole image the flash limit
     * lets through, per second. WCAG 2.3.1 counts a general flash as a pair of opposing
     * changes of at least 0.1 relative luminance and allows three per second, i.e. six
     * changes of 0.1, i.e. 0.6 per second. Half of that keeps a full-screen flash at
     * 1.5 per second at most; a cut from black to white takes 3.3 s.
     */
    public const FLASH_RATE = 0.3;
    /** Most the limit may lift a frame that darkened at once (it cannot lift black). */
    public const FLASH_GAIN_MAX = 4.0;
    /** A gap between two frames longer than this (a loading stall) starts the limit afresh. */
    public const MAX_FRAME_GAP = 0.25;

    private const SHADER_DIR = __DIR__ . '/../../../resources/shaders/source/vio/';

    private VioPipeline|false|null $applyPipeline = null;
    private VioPipeline|false|null $reducePipeline = null;
    private VioPipeline|false|null $statePipeline = null;
    private ?VioRenderTarget $output = null;
    private ?VioTexture $outputTexture = null;
    private string $sizeKey = '';
    /** @var list<array{0: VioRenderTarget, 1: VioTexture, 2: int, 3: int}> reduction steps, largest first */
    private array $reduction = [];
    /** @var array{0: array{0: VioRenderTarget, 1: VioTexture}|null, 1: array{0: VioRenderTarget, 1: VioTexture}|null} */
    private array $state = [null, null];
    private int $current = 0;
    private bool $primed = false;

    public function __construct(
        private readonly VioContext $ctx,
        private readonly BackendConventions $conventions,
    ) {
    }

    /** The programs compiled (built on first call); false when this backend cannot run them. */
    public function ready(): bool
    {
        return $this->pipeline('apply') !== null;
    }

    /** The luminance programs compiled; without them the flash limit and the night boost stay off. */
    public function stateReady(): bool
    {
        return $this->pipeline('reduce') !== null && $this->pipeline('state') !== null;
    }

    /** Next frame starts without history (the limit lets the first frame through as it is). */
    public function reset(): void
    {
        $this->primed = false;
    }

    /**
     * Uniforms of the apply shader for a settings record. Pure: what reaches the shader
     * can be checked without a GPU.
     *
     * @return array<string, float>
     */
    public static function uniforms(AccessibilitySettings $a): array
    {
        return [
            'u_cvd_mode' => (float) $a->colorVision->code(),
            'u_cvd_strength' => $a->colorVisionStrength,
            'u_cvd_simulate' => $a->colorVisionSimulate ? 1.0 : 0.0,
            'u_saturation' => $a->worldSaturation,
            'u_contrast' => $a->contrast,
            'u_flash_limit' => $a->flashLimit ? 1.0 : 0.0,
        ];
    }

    /**
     * Draw the options over $source (finished, encoded colour) into the pass's own
     * RGBA8 target and return its texture; null when a target or the program is
     * unavailable (the caller presents $source). Leaves no render target bound.
     *
     * @param array<string, float|list<float>> $uniforms {@see uniforms()} plus the frame's own
     * @param bool $luminance run the reduction and the state (flash limit, night boost)
     * @param float $dt seconds since the last frame, for the flash limit
     */
    public function apply(VioTexture $source, int $w, int $h, VioMesh $quad, array $uniforms, bool $luminance = false, bool $limit = false, float $dt = 0.0): ?VioTexture
    {
        $pipeline = $this->pipeline('apply');
        if ($pipeline === null) {
            return null;
        }
        $output = $this->ensureTargets($w, $h);
        if ($output === null || $this->outputTexture === null) {
            return null;
        }
        $state = $luminance && $this->stateReady() ? $this->updateState($source, $w, $h, $quad, $limit, $dt) : null;

        vio_bind_render_target($this->ctx, $output);
        vio_viewport($this->ctx, 0, 0, $w, $h);
        vio_bind_pipeline($this->ctx, $pipeline);
        vio_bind_texture($this->ctx, $source, 0);
        vio_bind_texture($this->ctx, $state ?? $source, 1);
        $this->setUniforms([
            'u_source' => 0,
            'u_state' => 1,
            'u_has_state' => $state !== null ? 1.0 : 0.0,
            'u_texel' => [1.0 / $w, 1.0 / $h],
            ...$uniforms,
        ]);
        vio_draw($this->ctx, $quad);
        vio_unbind_render_target($this->ctx);
        return $this->outputTexture;
    }

    /** Reduce $source to its mean luminance and step the state; returns the new state texture. */
    private function updateState(VioTexture $source, int $w, int $h, VioMesh $quad, bool $limit, float $dt): ?VioTexture
    {
        $reduce = $this->pipeline('reduce');
        $statePipeline = $this->pipeline('state');
        $next = $this->state[1 - $this->current];
        $previous = $this->state[$this->current];
        if ($reduce === null || $statePipeline === null || $next === null || $previous === null || $this->reduction === []) {
            return null;
        }
        $input = $source;
        [$inW, $inH] = [$w, $h];
        $decode = 1.0;
        foreach ($this->reduction as [$target, $texture, $tw, $th]) {
            vio_bind_render_target($this->ctx, $target);
            vio_viewport($this->ctx, 0, 0, $tw, $th);
            vio_bind_pipeline($this->ctx, $reduce);
            vio_bind_texture($this->ctx, $input, 0);
            $this->setUniforms(['u_source' => 0, 'u_texel' => [1.0 / $inW, 1.0 / $inH], 'u_decode' => $decode]);
            vio_draw($this->ctx, $quad);
            vio_unbind_render_target($this->ctx);
            [$input, $inW, $inH, $decode] = [$texture, $tw, $th, 0.0];
        }

        $reset = !$this->primed || $dt <= 0.0 || $dt > self::MAX_FRAME_GAP;
        vio_bind_render_target($this->ctx, $next[0]);
        vio_viewport($this->ctx, 0, 0, 1, 1);
        vio_bind_pipeline($this->ctx, $statePipeline);
        vio_bind_texture($this->ctx, $input, 0);
        vio_bind_texture($this->ctx, $previous[1], 1);
        $this->setUniforms([
            'u_mean' => 0,
            'u_previous' => 1,
            'u_dt' => $dt,
            'u_rate' => self::FLASH_RATE,
            'u_gain_max' => self::FLASH_GAIN_MAX,
            'u_reset' => $reset ? 1.0 : 0.0,
            'u_limit' => $limit ? 1.0 : 0.0,
        ]);
        vio_draw($this->ctx, $quad);
        vio_unbind_render_target($this->ctx);
        $this->current = 1 - $this->current;
        $this->primed = true;
        return $next[1];
    }

    public function release(): void
    {
        $this->output = null;
        $this->outputTexture = null;
        $this->reduction = [];
        $this->state = [null, null];
        $this->sizeKey = '';
        $this->primed = false;
    }

    private function ensureTargets(int $w, int $h): ?VioRenderTarget
    {
        $key = "{$w}x{$h}";
        if ($key === $this->sizeKey) {
            return $this->output;
        }
        $this->sizeKey = $key;
        $this->primed = false;
        $this->output = vio_render_target($this->ctx, ['width' => max(1, $w), 'height' => max(1, $h)]) ?: null;
        $this->outputTexture = $this->output !== null ? vio_render_target_texture($this->output) : null;
        $this->reduction = [];
        [$rw, $rh] = [$w, $h];
        do {
            [$rw, $rh] = [max(1, (int) ceil($rw / 4)), max(1, (int) ceil($rh / 4))];
            $target = vio_render_target($this->ctx, ['width' => $rw, 'height' => $rh, 'hdr' => true]) ?: null;
            if ($target === null) {
                $this->reduction = [];
                break;
            }
            $this->reduction[] = [$target, vio_render_target_texture($target), $rw, $rh];
        } while ($rw > 1 || $rh > 1);
        for ($i = 0; $i < 2; $i++) {
            $target = vio_render_target($this->ctx, ['width' => 1, 'height' => 1, 'hdr' => true]) ?: null;
            $this->state[$i] = $target !== null ? [$target, vio_render_target_texture($target)] : null;
        }
        return $this->output;
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

    /** @param 'apply'|'reduce'|'state' $which */
    private function pipeline(string $which): ?VioPipeline
    {
        $pipeline = match ($which) {
            'apply' => $this->applyPipeline ??= $this->build('a11y_apply.frag.glsl', false),
            'reduce' => $this->reducePipeline ??= $this->build('a11y_reduce.frag.glsl', true),
            'state' => $this->statePipeline ??= $this->build('a11y_state.frag.glsl', true),
        };
        return $pipeline === false ? null : $pipeline;
    }

    private function build(string $fragFile, bool $hdr): VioPipeline|false
    {
        $vert = @file_get_contents(self::SHADER_DIR . 'postprocess.vert.glsl');
        $frag = @file_get_contents(self::SHADER_DIR . $fragFile);
        if ($vert === false || $frag === false) {
            fwrite(STDERR, "[VioAccessibilityPass] failed to read {$fragFile}\n");
            return false;
        }
        $shader = vio_shader($this->ctx, [
            'vertex' => $vert,
            'fragment' => $frag,
            'format' => $this->conventions->shaderSourceFormat(),
        ]);
        if ($shader === false) {
            fwrite(STDERR, "[VioAccessibilityPass] {$fragFile} failed to compile\n");
            return false;
        }
        // D3D12 bakes the RTV format into the PSO: RGBA8 output, FP16 for the luminance targets.
        return vio_pipeline($this->ctx, [
            'shader' => $shader,
            'depth_test' => false,
            'cull_mode' => VIO_CULL_NONE,
            'blend' => VIO_BLEND_NONE,
            'hdr' => $hdr,
        ]);
    }
}
