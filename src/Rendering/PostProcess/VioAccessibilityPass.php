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
 */
final class VioAccessibilityPass
{
    private const SHADER_DIR = __DIR__ . '/../../../resources/shaders/source/vio/';

    private VioPipeline|false|null $applyPipeline = null;
    private ?VioRenderTarget $output = null;
    private ?VioTexture $outputTexture = null;
    private string $sizeKey = '';

    public function __construct(
        private readonly VioContext $ctx,
        private readonly BackendConventions $conventions,
    ) {
    }

    /** The program compiled (built on first call); false when this backend cannot run it. */
    public function ready(): bool
    {
        return $this->pipeline() !== null;
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
        ];
    }

    /**
     * Draw the options over $source (finished, encoded colour) into the pass's own
     * RGBA8 target and return its texture; null when a target or the program is
     * unavailable (the caller presents $source). Leaves no render target bound.
     *
     * @param array<string, float> $uniforms {@see uniforms()}
     */
    public function apply(VioTexture $source, int $w, int $h, VioMesh $quad, array $uniforms): ?VioTexture
    {
        $pipeline = $this->pipeline();
        if ($pipeline === null) {
            return null;
        }
        $output = $this->ensureOutput($w, $h);
        if ($output === null || $this->outputTexture === null) {
            return null;
        }
        vio_bind_render_target($this->ctx, $output);
        vio_viewport($this->ctx, 0, 0, $w, $h);
        vio_bind_pipeline($this->ctx, $pipeline);
        vio_bind_texture($this->ctx, $source, 0);
        $this->setUniforms(['u_source' => 0, ...$uniforms]);
        vio_draw($this->ctx, $quad);
        vio_unbind_render_target($this->ctx);
        return $this->outputTexture;
    }

    public function release(): void
    {
        $this->output = null;
        $this->outputTexture = null;
        $this->sizeKey = '';
    }

    private function ensureOutput(int $w, int $h): ?VioRenderTarget
    {
        $key = "{$w}x{$h}";
        if ($key !== $this->sizeKey) {
            $this->sizeKey = $key;
            $this->output = vio_render_target($this->ctx, ['width' => max(1, $w), 'height' => max(1, $h)]) ?: null;
            $this->outputTexture = $this->output !== null ? vio_render_target_texture($this->output) : null;
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

    private function pipeline(): ?VioPipeline
    {
        $this->applyPipeline ??= $this->build('a11y_apply.frag.glsl', false);
        return $this->applyPipeline === false ? null : $this->applyPipeline;
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
