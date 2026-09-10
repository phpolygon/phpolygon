<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Shader;

use PHPolygon\Testing\Shader\HeadlessShaderHarness;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The mesh übershader's MRT variant (PHPOLYGON_MRT, docs/rfcs/mrt-gbuffer.md)
 * splits the lit colour into sun / local / ambient attachments and writes the
 * G-buffer as a fourth. Two contracts keep the deferred composite honest:
 *
 *   1. sun + local + ambient == the single-target shader's linear output for
 *      the same fragment (the composite with AO = 1 reproduces the forward
 *      image), with and without fog (the fog split must be exact).
 *   2. Attachment 3 == what gbuffer.frag wrote for the same geometry: the AO /
 *      SSR passes decode it unchanged.
 *
 * Runs on the OpenGL harness context; skipped without the vio extension, a GL
 * context, or a backend without multiple render targets.
 */
#[RequiresPhpExtension('vio')]
class MrtMeshShaderHeadlessTest extends TestCase
{
    /** 8-bit read-back of FP16 data: allow a few LSB per channel (+ rounding of a 3-term sum). */
    private const TOLERANCE = 3.5 / 255.0;

    // The VIO_FORMAT_* constants only exist with the extension loaded; a class
    // constant would fail to evaluate when PHPUnit reflects the class on a runner
    // without vio (the RequiresPhpExtension skip runs later).
    /** @return list<int> the four MRT attachment formats (all FP16) */
    private static function mrt(): array
    {
        return [VIO_FORMAT_RGBA16F, VIO_FORMAT_RGBA16F, VIO_FORMAT_RGBA16F, VIO_FORMAT_RGBA16F];
    }

    /** @return list<int> one FP16 attachment */
    private static function single(): array
    {
        return [VIO_FORMAT_RGBA16F];
    }

    private ?HeadlessShaderHarness $h = null;

    protected function setUp(): void
    {
        $this->h = HeadlessShaderHarness::open(32, 32);
        if ($this->h === null) {
            $this->markTestSkipped('No vio OpenGL context available.');
        }
        if (!defined('VIO_FEATURE_MRT') || !$this->h->supportsFeature(VIO_FEATURE_MRT)) {
            $this->h->close();
            $this->h = null;
            $this->markTestSkipped('backend has no multiple render targets');
        }
    }

    protected function tearDown(): void
    {
        $this->h?->close();
        $this->h = null;
    }

    public function testAttachmentSumMatchesForwardLinearOutput(): void
    {
        $this->assertSplitMatchesForward(fogNear: 1.0e5, fogFar: 2.0e5);
    }

    /** Fog is affine in the colour: (1-t)·parts + t·fogColour on the local part must sum exactly. */
    public function testAttachmentSumMatchesForwardLinearOutputWithFog(): void
    {
        $this->assertSplitMatchesForward(fogNear: 0.0, fogFar: 4.0);
    }

    public function testSunAndAmbientLandInTheirOwnAttachments(): void
    {
        $h = $this->h;
        $this->assertNotNull($h);
        [$sun, $local, $ambient] = $this->renderMrt($h, fn () => null);

        [$sr, $sg, $sb] = $h->samplePixel($sun, 16, 16);
        [$lr, $lg, $lb] = $h->samplePixel($local, 16, 16);
        [$ar, $ag, $ab] = $h->samplePixel($ambient, 16, 16);

        $this->assertGreaterThan(0.05, $sr + $sg + $sb, 'the directional light is the sun attachment');
        $this->assertGreaterThan(0.05, $ar + $ag + $ab, 'flat ambient is the ambient attachment');
        // No point/spot lights, no emission, no IBL, no fog: nothing local.
        $this->assertLessThan(0.02, $lr + $lg + $lb, 'nothing should land in the local attachment');

        // Emission is local light (never AO-modulated).
        [, $localE] = $this->renderMrt($h, fn (HeadlessShaderHarness $h) => $h->setUniform('u_emission', [0.3, 0.0, 0.0]));
        [$er] = $h->samplePixel($localE, 16, 16);
        $this->assertEqualsWithDelta(0.3, $er, 0.02, 'emission goes to the local attachment');
    }

    public function testGbufferAttachmentMatchesGbufferShader(): void
    {
        $h = $this->h;
        $this->assertNotNull($h);

        // Same tilted geometry for both shaders: view 0.75 in front of the quad
        // (linear depth 0.75, readable through the 8-bit read-back), a normal
        // matrix that tilts the normal so its octahedral encoding is non-trivial,
        // and a polished metal so the reflectivity channel is non-zero.
        $view = [1, 0, 0, 0,  0, 1, 0, 0,  0, 0, 1, 0,  0, 0, -0.75, 1];
        $n = [0.3, 0.4, 0.8660254];
        $len = sqrt($n[0] ** 2 + $n[1] ** 2 + $n[2] ** 2);
        $n = [$n[0] / $len, $n[1] / $len, $n[2] / $len];
        $normalMatrix = [1, 0, 0,  0, 1, 0,  $n[0], $n[1], $n[2]];
        $geometry = static function (HeadlessShaderHarness $h) use ($view, $normalMatrix): void {
            $h->setUniform('u_view', $view);
            $h->setUniform('u_normal_matrix', $normalMatrix);
            $h->setUniform('u_metallic', 1.0);
            $h->setUniform('u_roughness', 0.2);
        };

        [, , , $gbuffer] = $this->renderMrt($h, function (HeadlessShaderHarness $h) use ($geometry, $view): void {
            $geometry($h);
            $h->setUniform('u_gbuffer_view', $view);
        });

        $gShader = $h->compileShaderFromFiles('vio/gbuffer.vert.glsl', 'vio/gbuffer.frag.glsl');
        $gPipe = $h->createTargetPipeline($gShader, self::single());
        [$reference] = $h->renderToTargetAndRead($gPipe, $h->fullscreenQuad(), function (HeadlessShaderHarness $h) use ($geometry): void {
            self::setNeutralMeshUniforms($h);
            $geometry($h);
        }, self::single());

        foreach ([[16, 16], [4, 4], [27, 9]] as [$x, $y]) {
            $got = $h->samplePixel($gbuffer, $x, $y);
            $want = $h->samplePixel($reference, $x, $y);
            foreach (['oct normal x', 'oct normal y', 'reflectivity', 'linear depth'] as $c => $label) {
                $this->assertEqualsWithDelta($want[$c], $got[$c], self::TOLERANCE, "$label at ($x,$y)");
            }
        }
        // And the values are the expected ones, not two shaders agreeing on garbage.
        [, , $refl, $depth] = $h->samplePixel($gbuffer, 16, 16);
        $this->assertEqualsWithDelta(0.8, $refl, self::TOLERANCE, 'metallic 1 · smoothness 0.8');
        $this->assertEqualsWithDelta(0.75, $depth, self::TOLERANCE, 'linear view depth');
        $encoded = $h->samplePixel($gbuffer, 16, 16);
        $l1 = abs($n[0]) + abs($n[1]) + abs($n[2]);
        $this->assertEqualsWithDelta($n[0] / $l1, $encoded[0], self::TOLERANCE, 'octahedral x');
        $this->assertEqualsWithDelta($n[1] / $l1, $encoded[1], self::TOLERANCE, 'octahedral y');
    }

    public function testGbufferWriteZeroLeavesTheAttachmentCleared(): void
    {
        $h = $this->h;
        $this->assertNotNull($h);
        $view = [1, 0, 0, 0,  0, 1, 0, 0,  0, 0, 1, 0,  0, 0, -0.75, 1];
        [$sun, , , $gbuffer] = $this->renderMrt($h, function (HeadlessShaderHarness $h) use ($view): void {
            $h->setUniform('u_view', $view);
            $h->setUniform('u_gbuffer_view', $view);
            $h->setUniform('u_gbuffer_write', 0);
        });
        $this->assertSame([0.0, 0.0, 0.0, 0.0], $h->samplePixel($gbuffer, 16, 16), 'opted-out draw writes no G-buffer');
        [$r, $g, $b] = $h->samplePixel($sun, 16, 16);
        $this->assertGreaterThan(0.05, $r + $g + $b, 'but it is still lit');
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function assertSplitMatchesForward(float $fogNear, float $fogFar): void
    {
        $h = $this->h;
        $this->assertNotNull($h);
        $fog = static function (HeadlessShaderHarness $h) use ($fogNear, $fogFar): void {
            $h->setUniform('u_fog_near', $fogNear);
            $h->setUniform('u_fog_far', $fogFar);
            $h->setUniform('u_fog_color', [0.2, 0.4, 0.6]);
        };

        $forwardShader = $h->compileShaderFromFiles('vio/mesh3d.vert.glsl', 'vio/mesh3d.frag.glsl');
        $forwardPipe = $h->createTargetPipeline($forwardShader, self::single());
        [$forward] = $h->renderToTargetAndRead($forwardPipe, $h->fullscreenQuad(), function (HeadlessShaderHarness $h) use ($fog): void {
            self::setNeutralMeshUniforms($h);
            $fog($h);
        }, self::single());

        [$sun, $local, $ambient] = $this->renderMrt($h, $fog);

        foreach ([[16, 16], [3, 3], [28, 20], [10, 25]] as [$x, $y]) {
            $want = $h->samplePixel($forward, $x, $y);
            $s = $h->samplePixel($sun, $x, $y);
            $l = $h->samplePixel($local, $x, $y);
            $a = $h->samplePixel($ambient, $x, $y);
            foreach (['r', 'g', 'b'] as $c => $label) {
                $this->assertEqualsWithDelta(
                    $want[$c],
                    $s[$c] + $l[$c] + $a[$c],
                    self::TOLERANCE,
                    sprintf('%s at (%d,%d): forward %.3f vs sun %.3f + local %.3f + ambient %.3f', $label, $x, $y, $want[$c], $s[$c], $l[$c], $a[$c]),
                );
            }
            $this->assertEqualsWithDelta($want[3], $s[3], 0.02, 'alpha rides on every attachment');
        }
        // Sanity: the forward image is not black (the comparison means something).
        [$r, $g, $b] = $h->samplePixel($forward, 16, 16);
        $this->assertGreaterThan(0.1, $r + $g + $b);
    }

    /**
     * Render the fullscreen quad through the MRT mesh shader with neutral
     * uniforms (+ the caller's overrides) and return the four attachments.
     *
     * @param callable(HeadlessShaderHarness): void $override
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function renderMrt(HeadlessShaderHarness $h, callable $override): array
    {
        $shader = $h->compileShaderFromFiles('vio/mesh3d.vert.glsl', 'vio/mesh3d.frag.glsl', ['PHPOLYGON_MRT']);
        $pipe = $h->createTargetPipeline($shader, self::mrt());
        $identity = [1, 0, 0, 0,  0, 1, 0, 0,  0, 0, 1, 0,  0, 0, 0, 1];
        /** @var array{0: string, 1: string, 2: string, 3: string} $out */
        $out = $h->renderToTargetAndRead($pipe, $h->fullscreenQuad(), function (HeadlessShaderHarness $h) use ($override, $identity): void {
            self::setNeutralMeshUniforms($h);
            $h->setUniform('u_gbuffer_view', $identity);
            $h->setUniform('u_gbuffer_write', 1);
            $override($h);
        }, self::mrt());
        return $out;
    }

    /**
     * The neutral mesh-shader state (mirrors MeshShaderHeadlessTest), with
     * u_linear_output = 1 so both variants emit linear colour and no screen-space
     * AO maps (the composite's job on the MRT path).
     */
    private static function setNeutralMeshUniforms(HeadlessShaderHarness $h): void
    {
        $h->bindDummyShadowSamplers();
        $identity = [1, 0, 0, 0,  0, 1, 0, 0,  0, 0, 1, 0,  0, 0, 0, 1];
        $h->setUniform('u_model', $identity);
        $h->setUniform('u_view', $identity);
        $h->setUniform('u_projection', $identity);
        $h->setUniform('u_normal_matrix', [1, 0, 0,  0, 1, 0,  0, 0, 1]);
        $h->setUniform('u_use_instancing', 0);
        $h->setUniform('u_light_space_matrix', $identity);
        $h->setUniform('u_vertex_anim', 0);
        $h->setUniform('u_wave_amplitude', 0.0);
        $h->setUniform('u_wave_frequency', 0.0);
        $h->setUniform('u_wave_phase', 0.0);
        $h->setUniform('u_time', 0.0);
        $h->setUniform('u_cloth', 0);
        $h->setUniform('u_cloth_strength', 0.0);
        $h->setUniform('u_cloth_frequency', 0.0);
        $h->setUniform('u_cloth_phase', 0.0);
        $h->setUniform('u_cloth_anchor_top', 1);
        $h->setUniform('u_wind_direction', [0.0, 0.0, 1.0]);
        $h->setUniform('u_wind_intensity', 0.0);
        $h->setUniform('u_mesh_local_aabb_min', [-1.0, -1.0, -1.0]);
        $h->setUniform('u_mesh_local_aabb_max', [1.0, 1.0, 1.0]);

        $h->setUniform('u_albedo', [0.8, 0.7, 0.6]);
        $h->setUniform('u_emission', [0.0, 0.0, 0.0]);
        $h->setUniform('u_roughness', 0.5);
        $h->setUniform('u_metallic', 0.0);
        $h->setUniform('u_alpha', 1.0);
        $h->setUniform('u_clearcoat', 0.0);
        $h->setUniform('u_clearcoat_roughness', 0.05);
        $h->setUniform('u_flakes', 0.0);
        $h->setUniform('u_normal_intensity', 1.0);
        $h->setUniform('u_use_environment_map', 0);
        $h->setUniform('u_has_environment_map', 0);
        $h->setUniform('u_normal_pattern', 0);
        $h->setUniform('u_normal_scale', 1.0);
        $h->setUniform('u_surface_pattern', 0);
        $h->setUniform('u_surface_scale', 1.0);
        $h->setUniform('u_surface_intensity', 0.0);
        $h->setUniform('u_wetness', 0.0);
        $h->setUniform('u_ssr_intensity', 0.0);
        $h->setUniform('u_subsurface_color', [1.0, 0.35, 0.25]);
        $h->setUniform('u_subsurface_strength', 0.0);
        $h->setUniform('u_proc_mode', 0);
        $h->setUniform('u_has_albedo_texture', 0);
        $h->setUniform('u_albedo_texture', 0);
        $h->setUniform('u_season_tint', [1.0, 1.0, 1.0]);

        $h->setUniform('u_ambient_color', [0.5, 0.5, 0.5]);
        $h->setUniform('u_ambient_intensity', 1.0);
        $h->setUniform('u_dir_light_count', 1);
        $h->setUniform('u_dir_lights[0].direction', [0.0, 0.0, -1.0]);
        $h->setUniform('u_dir_lights[0].color', [1.0, 1.0, 1.0]);
        $h->setUniform('u_dir_lights[0].intensity', 1.0);
        $h->setUniform('u_point_light_count', 0);
        $h->setUniform('u_spot_light_count', 0);
        $h->setUniform('u_camera_pos', [0.0, 0.0, 5.0]);

        $h->setUniform('u_has_shadow_map', 0);
        $h->setUniform('u_csm_count', 0);
        $h->setUniform('u_csm_far_0', 0.0);
        $h->setUniform('u_csm_far_1', 0.0);
        $h->setUniform('u_csm_far_2', 0.0);

        $h->setUniform('u_sky_color', [0.55, 0.70, 0.85]);
        $h->setUniform('u_horizon_color', [0.85, 0.88, 0.92]);
        $h->setUniform('u_fog_color', [0.0, 0.0, 0.0]);
        $h->setUniform('u_fog_near', 1.0e5);
        $h->setUniform('u_fog_far', 2.0e5);
        $h->setUniform('u_volumetric_fog', 0);
        $h->setUniform('u_snow_cover', 0.0);
        $h->setUniform('u_rain_wetness', 0.0);
        $h->setUniform('u_moon_phase', 0.0);
        $h->setUniform('u_ao_strength', 0.4);
        $h->setUniform('u_ssao_enabled', 0);
        $h->setUniform('u_sdf_ao_enabled', 0.0);
        $h->setUniform('u_probe_enabled', 0);
        $h->setUniform('u_ft_intensity', 0.0);
        $h->setUniform('u_grade_lift', [0.0, 0.0, 0.0]);
        $h->setUniform('u_grade_gamma', [1.0, 1.0, 1.0]);
        $h->setUniform('u_grade_gain', [1.0, 1.0, 1.0]);
        $h->setUniform('u_grade_saturation', 1.0);
        $h->setUniform('u_vignette_intensity', 0.0);
        $h->setUniform('u_viewport_size', [(float) $h->width, (float) $h->height]);
        $h->setUniform('u_linear_output', 1);
    }
}
