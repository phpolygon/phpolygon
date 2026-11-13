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
 * The GPU side of {@see ImageLook}: the bloom mip chain, the auto exposure metering and
 * state, the colour tables and the finishing draw. VioRenderer3D calls it from the present
 * only while the look differs from the default; until then nothing here is compiled or
 * allocated.
 */
final class VioImageChain
{
    /** A gap between two frames longer than this (a loading stall) lets the exposure jump. */
    public const MAX_FRAME_GAP = 0.25;
    /** Effect register name of the film grain: its time follows EffectRegistry::effectTime(). */
    public const GRAIN_EFFECT = 'image_grain';
    /** The bloom chain stops before a level would be narrower than this. */
    private const MIN_BLOOM_SIZE = 4;

    private const SHADER_DIR = __DIR__ . '/../../../resources/shaders/source/vio/';

    /** @var array<string, VioPipeline|false> */
    private array $pipelines = [];
    /** @var array<string, VioRenderTarget> */
    private array $targets = [];
    /** @var array<string, array{0: int, 1: VioTexture|false, 2: int}> id => version, texture, size */
    private array $luts = [];
    private VioTexture|false|null $neutralLut = null;
    /** @var array{0: VioRenderTarget, 1: VioRenderTarget}|null */
    private ?array $exposureState = null;
    private int $exposureCurrent = 0;
    private bool $exposurePrimed = false;
    private ?float $exposureTime = null;

    public function __construct(
        private readonly VioContext $ctx,
        private readonly BackendConventions $conventions,
    ) {
    }

    /** The finishing program compiled; false on a backend that cannot run it (the frame keeps the v0.97 present). */
    public function ready(): bool
    {
        return $this->pipeline('image_finish', false) !== null;
    }

    /** The next exposure starts without history. */
    public function resetExposure(): void
    {
        $this->exposurePrimed = false;
    }

    /**
     * The dither amplitude in 8-bit steps the finish pass uses. A request of 0.05 or more is raised to
     * 1.5 steps: measured on a glow in the dark (512 px, deband 0.5 left 8 runs of 5 px or more of one
     * value along the halo, 1.0 one run, 2.0 none; without it a 67 px band), so a smaller amplitude does
     * not hide the rings. Below 0.05 the value is kept (a game that wants the chain without dither passes
     * 0.0001).
     */
    public static function debandAmplitude(float $deband): float
    {
        $d = max(0.0, $deband);

        return $d >= 0.05 ? max($d, 1.5) : $d;
    }

    public function release(): void
    {
        $this->targets = [];
        $this->exposureState = null;
        $this->exposurePrimed = false;
        $this->exposureTime = null;
    }

    /**
     * Bloom of $scene as a mip chain of $levels (>= 2). Returns the finest level (half the
     * size of $scene) and the factor that normalises the sum of the levels; null when a
     * target or program is missing. Leaves no target bound.
     *
     * @return array{0: VioTexture, 1: float}|null
     */
    public function bloom(VioTexture $scene, int $w, int $h, bool $hdr, ImageLook $look, VioMesh $quad): ?array
    {
        $down = $this->pipeline('image_bloom_down', $hdr);
        $up = $this->pipeline('image_bloom_up', $hdr);
        if ($down === null || $up === null) {
            return null;
        }
        $sizes = self::bloomSizes($w, $h, $look->bloomLevels);
        if ($sizes === []) {
            return null;
        }
        $fmt = $hdr ? ':hdr' : '';
        $levels = [];
        $input = $scene;
        [$inW, $inH] = [$w, $h];
        foreach ($sizes as $i => [$lw, $lh]) {
            $target = $this->target("bloom_down{$i}:{$lw}x{$lh}{$fmt}", $lw, $lh, $hdr);
            if ($target === null) {
                return null;
            }
            vio_bind_render_target($this->ctx, $target);
            vio_viewport($this->ctx, 0, 0, $lw, $lh);
            vio_bind_pipeline($this->ctx, $down);
            vio_bind_texture($this->ctx, $input, 0);
            $this->setUniforms([
                'u_source' => 0,
                'u_texel' => [1.0 / $inW, 1.0 / $inH],
                'u_first' => $i === 0 ? 1.0 : 0.0,
                'u_threshold' => $look->bloomThreshold,
            ]);
            vio_draw($this->ctx, $quad);
            vio_unbind_render_target($this->ctx);
            $input = vio_render_target_texture($target);
            $levels[$i] = $input;
            [$inW, $inH] = [$lw, $lh];
        }
        $coarse = $levels[count($levels) - 1];
        for ($i = count($sizes) - 2; $i >= 0; $i--) {
            [$lw, $lh] = $sizes[$i];
            [$cw, $ch] = $sizes[$i + 1];
            $target = $this->target("bloom_up{$i}:{$lw}x{$lh}{$fmt}", $lw, $lh, $hdr);
            if ($target === null) {
                return null;
            }
            vio_bind_render_target($this->ctx, $target);
            vio_viewport($this->ctx, 0, 0, $lw, $lh);
            vio_bind_pipeline($this->ctx, $up);
            vio_bind_texture($this->ctx, $coarse, 0);
            vio_bind_texture($this->ctx, $levels[$i], 1);
            $this->setUniforms([
                'u_source' => 0,
                'u_base' => 1,
                'u_texel' => [1.0 / $cw, 1.0 / $ch],
                'u_scatter' => $look->bloomScatter,
            ]);
            vio_draw($this->ctx, $quad);
            vio_unbind_render_target($this->ctx);
            $coarse = vio_render_target_texture($target);
        }
        return [$coarse, self::bloomNormalisation(count($sizes), $look->bloomScatter)];
    }

    /**
     * Level sizes of the bloom chain for a scene of $w x $h, finest first.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function bloomSizes(int $w, int $h, int $levels): array
    {
        $sizes = [];
        for ($i = 0; $i < $levels; $i++) {
            $w = intdiv($w, 2);
            $h = intdiv($h, 2);
            if ($w < self::MIN_BLOOM_SIZE || $h < self::MIN_BLOOM_SIZE) {
                break;
            }
            $sizes[] = [$w, $h];
        }
        return $sizes;
    }

    /** 1 / sum(scatter^i) over the levels: the chain then has the weight of one level. */
    public static function bloomNormalisation(int $levels, float $scatter): float
    {
        $sum = 0.0;
        for ($i = 0; $i < $levels; $i++) {
            $sum += $scatter ** $i;
        }
        return $sum > 0.0 ? 1.0 / $sum : 1.0;
    }

    /**
     * Meter the linear scene colour and step the exposure state. Returns the 1x1 state
     * texture (g = exposure), null when the programs or targets are missing.
     *
     * @param float $time animation time of the frame; the gap to the last call is the adaptation step
     */
    public function exposure(VioTexture $scene, int $w, int $h, AutoExposure $auto, float $time, bool $cut, VioMesh $quad): ?VioTexture
    {
        $reduce = $this->pipeline('image_exposure_reduce', true);
        $statePipeline = $this->pipeline('image_exposure_state', true);
        if ($reduce === null || $statePipeline === null) {
            return null;
        }
        if ($this->exposureState === null) {
            $a = $this->target('exposure_state0', 1, 1, true);
            $b = $this->target('exposure_state1', 1, 1, true);
            if ($a === null || $b === null) {
                return null;
            }
            $this->exposureState = [$a, $b];
        }
        $pair = $this->exposureState;
        $input = $scene;
        [$inW, $inH] = [$w, $h];
        $first = true;
        $level = 0;
        [$rw, $rh] = [$w, $h];
        do {
            [$rw, $rh] = [max(1, (int) ceil($rw / 4)), max(1, (int) ceil($rh / 4))];
            $target = $this->target("exposure_reduce{$level}:{$rw}x{$rh}", $rw, $rh, true);
            if ($target === null) {
                return null;
            }
            vio_bind_render_target($this->ctx, $target);
            vio_viewport($this->ctx, 0, 0, $rw, $rh);
            vio_bind_pipeline($this->ctx, $reduce);
            vio_bind_texture($this->ctx, $input, 0);
            $this->setUniforms(['u_source' => 0, 'u_texel' => [1.0 / $inW, 1.0 / $inH], 'u_first' => $first ? 1.0 : 0.0]);
            vio_draw($this->ctx, $quad);
            vio_unbind_render_target($this->ctx);
            [$input, $inW, $inH, $first] = [vio_render_target_texture($target), $rw, $rh, false];
            $level++;
        } while ($rw > 1 || $rh > 1);

        $dt = $this->exposureTime !== null ? $time - $this->exposureTime : 0.0;
        $this->exposureTime = $time;
        $reset = $cut || !$this->exposurePrimed || $dt <= 0.0 || $dt > self::MAX_FRAME_GAP;
        [$previous, $next] = $this->exposureCurrent === 0
            ? [$pair[0], $pair[1]]
            : [$pair[1], $pair[0]];
        vio_bind_render_target($this->ctx, $next);
        vio_viewport($this->ctx, 0, 0, 1, 1);
        vio_bind_pipeline($this->ctx, $statePipeline);
        vio_bind_texture($this->ctx, $input, 0);
        vio_bind_texture($this->ctx, vio_render_target_texture($previous), 1);
        $this->setUniforms([
            'u_mean' => 0,
            'u_previous' => 1,
            'u_dt' => max(0.0, $dt),
            'u_reset' => $reset ? 1.0 : 0.0,
            'u_speed_up' => $auto->speedUp,
            'u_speed_down' => $auto->speedDown,
            'u_log_reference' => log($auto->reference, 2),
            'u_strength' => $auto->strength,
            'u_min_ev' => $auto->minEv,
            'u_max_ev' => $auto->maxEv,
        ]);
        vio_draw($this->ctx, $quad);
        vio_unbind_render_target($this->ctx);
        $this->exposureCurrent = 1 - $this->exposureCurrent;
        $this->exposurePrimed = true;
        return vio_render_target_texture($next);
    }

    /**
     * Finish $scene into an RGBA8 image of the same size. Returns its texture, null when the
     * program or target is missing (the caller then presents the v0.97 way).
     *
     * @param array<string, float|list<float>> $uniforms {@see finishUniforms()}
     */
    public function finish(VioTexture $scene, int $w, int $h, ?VioTexture $bloom, ?VioTexture $exposureState, ImageLook $look, array $uniforms, VioMesh $quad): ?VioTexture
    {
        $pipeline = $this->pipeline('image_finish', false);
        $target = $this->target("finish:{$w}x{$h}", $w, $h, false);
        $neutral = $this->neutralLut();
        if ($pipeline === null || $target === null || $neutral === null) {
            return null;
        }
        [$lutA, $mapA] = $this->lutTexture($look->lut) ?? [$neutral, [0.5, 0.25]];
        [$lutB, $mapB] = $this->lutTexture($look->lutBlend) ?? [$lutA, $mapA];
        if ($look->lut === null || $this->lutTexture($look->lut) === null) {
            $uniforms['u_lut_strength'] = 0.0;
        }
        if ($look->lutBlend === null || $this->lutTexture($look->lutBlend) === null) {
            $uniforms['u_lut_blend'] = 0.0;
        }
        vio_bind_render_target($this->ctx, $target);
        vio_viewport($this->ctx, 0, 0, $w, $h);
        vio_bind_pipeline($this->ctx, $pipeline);
        // Every declared sampler is bound: an unbound one reads an empty SRV on D3D12.
        vio_bind_texture($this->ctx, $scene, 0);
        vio_bind_texture($this->ctx, $bloom ?? $scene, 1);
        vio_bind_texture($this->ctx, $exposureState ?? $scene, 2);
        vio_bind_texture($this->ctx, $lutA, 3);
        vio_bind_texture($this->ctx, $lutB, 4);
        if ($bloom === null) {
            $uniforms['u_bloom_intensity'] = 0.0;
        }
        if ($exposureState === null) {
            $uniforms['u_auto_exposure'] = 0.0;
        }
        $this->setUniforms([
            'u_source' => 0,
            'u_bloom' => 1,
            'u_exposure_state' => 2,
            'u_lut_a' => 3,
            'u_lut_b' => 4,
            'u_lut_a_map' => $mapA,
            'u_lut_b_map' => $mapB,
            ...$uniforms,
        ]);
        vio_draw($this->ctx, $quad);
        vio_unbind_render_target($this->ctx);
        return vio_render_target_texture($target);
    }

    /**
     * Uniforms of the finishing draw for a look, with the comfort options applied. Pure: what
     * reaches the shader can be checked without a GPU.
     *
     * @param float $bloomGain bloom intensity times the chain's normalisation; 0 = no bloom this frame
     * @return array<string, float>
     */
    public static function finishUniforms(ImageLook $look, AccessibilitySettings $a11y, bool $hdr, float $bloomGain, bool $autoExposure, float $grainTime): array
    {
        // The player's comfort choices win over the look:
        //  - less world saturation or reduced motion: no saturation pulse,
        //  - contrast mode: no grain, no colour fringes (its outlines would trace them),
        //  - reduced motion: the grain stands still.
        $pulse = ($a11y->worldSaturation < 1.0 || $a11y->reducedMotion) ? 0.0 : max(0.0, $look->saturationPulse);
        $clean = $a11y->contrast > 0.0;
        return [
            'u_hdr' => $hdr ? 1.0 : 0.0,
            'u_bloom_intensity' => max(0.0, $bloomGain),
            'u_exposure' => 2.0 ** $look->exposureEv,
            'u_auto_exposure' => $autoExposure && $hdr ? 1.0 : 0.0,
            'u_tonemapper' => (float) $look->tonemapper->code(),
            'u_lut_strength' => $look->lut !== null ? max(0.0, min(1.0, $look->lutStrength)) : 0.0,
            'u_lut_blend' => $look->lutBlend !== null ? max(0.0, min(1.0, $look->lutBlendWeight)) : 0.0,
            'u_saturation_boost' => $pulse,
            'u_grain' => $clean ? 0.0 : max(0.0, $look->grain),
            'u_deband' => self::debandAmplitude($look->deband),
            'u_ca' => $clean ? 0.0 : max(0.0, $look->chromaticAberration),
            'u_night_vision' => $hdr ? max(0.0, min(1.0, $look->nightVision)) : 0.0,
            'u_time' => $a11y->reducedMotion ? 0.0 : $grainTime,
        ];
    }

    /** @return array{0: VioTexture, 1: list<float>}|null texture and its (scale, offset) */
    private function lutTexture(?string $id): ?array
    {
        if ($id === null) {
            return null;
        }
        $lut = ColorLutRegistry::get($id);
        if ($lut === null) {
            return null;
        }
        $version = ColorLutRegistry::version($id);
        if (!isset($this->luts[$id]) || $this->luts[$id][0] !== $version) {
            $texture = $this->upload($lut);
            if ($texture === false) {
                fwrite(STDERR, "[VioImageChain] colour table '{$id}' could not be uploaded\n");
            }
            $this->luts[$id] = [$version, $texture, $lut->size];
        }
        [, $texture, $size] = $this->luts[$id];
        return $texture === false ? null : [$texture, [($size - 1) / $size, 0.5 / $size]];
    }

    private function neutralLut(): ?VioTexture
    {
        $this->neutralLut ??= $this->upload(ColorLut::identity(2));
        return $this->neutralLut === false ? null : $this->neutralLut;
    }

    private function upload(ColorLut $lut): VioTexture|false
    {
        if (!function_exists('vio_texture_3d')
            || !defined('VIO_FEATURE_TEXTURE_3D')
            || !vio_supports_feature($this->ctx, (int) constant('VIO_FEATURE_TEXTURE_3D'))) {
            return false;
        }
        return vio_texture_3d($this->ctx, [
            'data' => $lut->data,
            'width' => $lut->size,
            'height' => $lut->size,
            'depth' => $lut->size,
            'filter' => VIO_FILTER_LINEAR,
            'wrap' => VIO_WRAP_CLAMP,
        ]);
    }

    private function target(string $key, int $w, int $h, bool $hdr): ?VioRenderTarget
    {
        if (!isset($this->targets[$key])) {
            $target = vio_render_target($this->ctx, ['width' => max(1, $w), 'height' => max(1, $h), 'hdr' => $hdr]);
            if ($target === false) {
                return null;
            }
            // A resize leaves the old sizes behind: drop targets of other sizes of this kind.
            $kind = strstr($key, ':', true) ?: $key;
            foreach (array_keys($this->targets) as $old) {
                if ((strstr($old, ':', true) ?: $old) === $kind) {
                    unset($this->targets[$old]);
                }
            }
            $this->targets[$key] = $target;
        }
        return $this->targets[$key];
    }

    /** @param array<string, int|float|array<float>> $uniforms */
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

    private function pipeline(string $name, bool $hdr): ?VioPipeline
    {
        $key = $name . ($hdr ? ':hdr' : '');
        if (!isset($this->pipelines[$key])) {
            $this->pipelines[$key] = $this->build($name . '.frag.glsl', $hdr);
        }
        $pipeline = $this->pipelines[$key];
        return $pipeline === false ? null : $pipeline;
    }

    private function build(string $fragFile, bool $hdr): VioPipeline|false
    {
        $vert = @file_get_contents(self::SHADER_DIR . 'postprocess.vert.glsl');
        $frag = @file_get_contents(self::SHADER_DIR . $fragFile);
        if ($vert === false || $frag === false) {
            fwrite(STDERR, "[VioImageChain] failed to read {$fragFile}\n");
            return false;
        }
        $shader = vio_shader($this->ctx, [
            'vertex' => $vert,
            'fragment' => $frag,
            'format' => $this->conventions->shaderSourceFormat(),
        ]);
        if ($shader === false) {
            fwrite(STDERR, "[VioImageChain] {$fragFile} failed to compile\n");
            return false;
        }
        // D3D12 bakes the RTV format into the PSO.
        return vio_pipeline($this->ctx, [
            'shader' => $shader,
            'depth_test' => false,
            'cull_mode' => VIO_CULL_NONE,
            'blend' => VIO_BLEND_NONE,
            'hdr' => $hdr,
        ]);
    }
}
