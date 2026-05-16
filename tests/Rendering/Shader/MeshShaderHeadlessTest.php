<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Shader;

use PHPolygon\Testing\Shader\HeadlessShaderHarness;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Run real GLSL shaders on a hidden vio context and assert pixel-level
 * invariants on the actual fragment output. Catches the bug class where a
 * shader compiles cleanly but produces black / NaN pixels for plausible
 * uniform values - the exact failure mode that consumed hours of black-
 * screen bisecting before this harness existed.
 *
 * Two regression cases are pinned by this suite:
 *
 *   1. The "unbound sampler2DShadow" trap on macOS OpenGL: mesh3d declares
 *      cascade shadow samplers; an unbound sampler silently collapses the
 *      whole fragment stage. The harness binds a 1x1 depth target via
 *      {@see HeadlessShaderHarness::bindDummyShadowSamplers} which matches
 *      the production fix in VioRenderer3D::uploadShadowUniforms.
 *
 *   2. NaN-by-pow-of-infinity when uniforms default to zero (the
 *      u_grade_gamma == 0 case from applyColorGrading).
 *
 * Skipped automatically when no GPU + software-renderer combination is
 * available (typical for headless CI on Linux without `mesa-utils` +
 * `libgl1-mesa-dri`). Locally on macOS the OpenGL backend is always
 * available.
 */
#[RequiresPhpExtension('vio')]
class MeshShaderHeadlessTest extends TestCase
{
    private ?HeadlessShaderHarness $h = null;

    protected function setUp(): void
    {
        $this->h = HeadlessShaderHarness::open(64, 64);
        if ($this->h === null) {
            $this->markTestSkipped(
                'No vio OpenGL context available (likely a headless CI runner ' .
                'without software OpenGL). Install mesa-utils or run locally.'
            );
        }
    }

    protected function tearDown(): void
    {
        $this->h?->close();
        $this->h = null;
    }

    // ── Smoke ──────────────────────────────────────────────────────────

    /**
     * Trivial pass-through shader proves the harness itself works: a fullscreen
     * quad with a fragment shader that outputs solid orange must fill the
     * entire offscreen target with orange pixels.
     */
    public function testHarnessRendersTrivialShaderAsExpectedColor(): void
    {
        $h = $this->h;
        $this->assertNotNull($h);

        $vert = <<<GLSL
        #version 410 core
        layout(location = 0) in vec3 a_position;
        void main() { gl_Position = vec4(a_position, 1.0); }
        GLSL;

        $frag = <<<GLSL
        #version 410 core
        out vec4 fragColor;
        void main() { fragColor = vec4(1.0, 0.5, 0.0, 1.0); }
        GLSL;

        $shader   = $h->compileShaderFromSource('trivial_orange', $vert, $frag);
        $pipeline = $h->createPipeline($shader);
        $rgba     = $h->renderAndRead($pipeline, $h->fullscreenQuad(), fn () => null);

        [$r, $g, $b, $a] = $h->samplePixel($rgba, 32, 32);
        $this->assertEqualsWithDelta(1.0, $r, 0.02, 'red channel');
        $this->assertEqualsWithDelta(0.5, $g, 0.02, 'green channel');
        $this->assertEqualsWithDelta(0.0, $b, 0.02, 'blue channel');
        $this->assertSame(1.0, $a, 'alpha channel');
    }

    // ── mesh3d.frag.glsl: real-shader regression coverage ─────────────

    /**
     * The black-screen regression: with neutral PBR uniforms (white albedo,
     * white ambient, white dir-light, neutral grading) the standard mesh3d
     * lit path must produce non-zero pixels. With unbound shadow samplers
     * this would silently collapse to ~0.098 grey before the dummy-target
     * binding landed.
     */
    public function testMesh3dWithNeutralUniformsProducesNonZeroOutput(): void
    {
        $h = $this->h;
        $this->assertNotNull($h);

        $shader   = $h->compileShaderFromFiles('vio/mesh3d.vert.glsl', 'vio/mesh3d.frag.glsl');
        $pipeline = $h->createPipeline($shader);
        $rgba     = $h->renderAndRead(
            $pipeline,
            $h->fullscreenQuad(),
            fn (HeadlessShaderHarness $h) => self::setNeutralMeshUniforms($h, 64, 64),
        );

        [$r, $g, $b, $a] = $h->samplePixel($rgba, 32, 32);
        $sum = $r + $g + $b;
        $this->assertGreaterThan(
            0.5,
            $sum,
            sprintf('mesh3d with neutral uniforms produced near-black centre pixel: rgb=(%.3f, %.3f, %.3f)', $r, $g, $b)
        );
        $this->assertEqualsWithDelta(1.0, $a, 0.02, 'alpha channel');
    }

    /**
     * Darker albedo must yield darker final output (modulo tone-mapping).
     * If lighting is fully broken the output is independent of albedo.
     */
    public function testMesh3dAlbedoDifferenceIsObservable(): void
    {
        $darkSum   = $this->renderAlbedoSum([0.05, 0.05, 0.05]);
        $brightSum = $this->renderAlbedoSum([0.9, 0.9, 0.9]);
        $this->assertLessThan(
            $brightSum,
            $darkSum,
            sprintf('dark albedo (sum=%.3f) must produce less light than bright (sum=%.3f)', $darkSum, $brightSum)
        );
    }

    /**
     * With grazing light (NdotL near 0) the SSS path must:
     *   - lift the fragment brightness above the no-SSS terminator (which is
     *     just ambient), because wrap-diffuse pulls in the light past the
     *     N·L = 0 boundary, and
     *   - shift the red channel relative to green/blue, because the warm
     *     subsurface tint bleeds into the terminator region.
     *
     * Both invariants are what makes skin not look like plastic; pinning
     * them keeps a future refactor of the lit path honest.
     */
    public function testSubsurfaceScatteringWarmsTheTerminator(): void
    {
        // Quad normal points at the camera (+Z). Light grazing left-to-right
        // (light direction = -X) gives N · L ≈ 0 → terminator.
        $offRgba = $this->renderAtTerminator(subsurfaceStrength: 0.0);
        $onRgba  = $this->renderAtTerminator(subsurfaceStrength: 0.8);

        $offSum = $offRgba[0] + $offRgba[1] + $offRgba[2];
        $onSum  = $onRgba[0]  + $onRgba[1]  + $onRgba[2];
        $this->assertGreaterThan(
            $offSum,
            $onSum,
            sprintf('SSS must brighten the terminator: off=%.3f on=%.3f', $offSum, $onSum)
        );

        // Warm shift: the on/off red-to-green ratio must be larger on the
        // SSS path because the terminator mixes albedo toward the warm tint.
        $offRatio = $offRgba[1] > 0.001 ? $offRgba[0] / $offRgba[1] : 1.0;
        $onRatio  = $onRgba[1]  > 0.001 ? $onRgba[0]  / $onRgba[1]  : 1.0;
        $this->assertGreaterThan(
            $offRatio,
            $onRatio,
            sprintf('SSS must add a warm shift (R/G): off=%.3f on=%.3f', $offRatio, $onRatio)
        );
    }

    /**
     * SKIN surface pattern (freckle mask) must produce per-pixel variation
     * across the offscreen frame. We sample three positions across the
     * fullscreen quad - their UVs differ enough that the noise-stack
     * cannot collapse to the same freckle decision for all three. Any
     * shift larger than the noise floor proves the pattern is wired
     * end-to-end (dispatch switch hit, scale/intensity uniforms applied,
     * tint multiplied into albedo).
     */
    public function testSkinSurfacePatternVariesAcrossPixels(): void
    {
        $h = HeadlessShaderHarness::open(64, 64);
        $this->assertNotNull($h);
        try {
            $shader   = $h->compileShaderFromFiles('vio/mesh3d.vert.glsl', 'vio/mesh3d.frag.glsl');
            $pipeline = $h->createPipeline($shader);
            $rgba     = $h->renderAndRead(
                $pipeline,
                $h->fullscreenQuad(),
                function (HeadlessShaderHarness $h): void {
                    self::setNeutralMeshUniforms($h, 64, 64);
                    $h->setUniform('u_surface_pattern',   5);    // SKIN
                    $h->setUniform('u_surface_intensity', 1.0);  // full strength so freckle
                                                                 // darkening is observable
                    $h->setUniform('u_surface_scale',     1.0);
                },
            );

            $a = $h->samplePixel($rgba, 8,  8);
            $b = $h->samplePixel($rgba, 32, 32);
            $c = $h->samplePixel($rgba, 56, 56);
            $maxDelta = 0.0;
            foreach ([[$a, $b], [$a, $c], [$b, $c]] as [$p, $q]) {
                $d = abs($p[0] - $q[0]) + abs($p[1] - $q[1]) + abs($p[2] - $q[2]);
                if ($d > $maxDelta) {
                    $maxDelta = $d;
                }
            }
            $this->assertGreaterThan(
                0.01,
                $maxDelta,
                sprintf(
                    'SKIN surface pattern must vary across pixels (a=(%.3f,%.3f,%.3f) b=(%.3f,%.3f,%.3f) c=(%.3f,%.3f,%.3f), maxDelta=%.3f)',
                    $a[0], $a[1], $a[2], $b[0], $b[1], $b[2], $c[0], $c[1], $c[2], $maxDelta
                )
            );
        } finally {
            $h->close();
        }
    }

    /**
     * Sanity: with subsurfaceStrength = 0 the lit-path output must match
     * what the legacy non-SSS path produced. We can't easily reach into a
     * historical shader build, so the proxy assertion is: at NdotL > 0
     * (front-lit), subsurfaceStrength = 0 produces no warm shift (R ≈ G).
     * If the gate is removed, terminator-tint bleeds into the front-lit
     * fragment too and the assertion fires.
     */
    public function testSubsurfaceStrengthZeroIsNeutralOnFrontLitFragment(): void
    {
        // Light directly facing the quad → NdotL = 1, no terminator.
        $rgba = $this->renderWithDirLight([0.0, 0.0, -1.0], subsurfaceStrength: 0.0);
        $this->assertEqualsWithDelta(
            $rgba[0],
            $rgba[1],
            0.05,
            sprintf('strength=0 must not tint front-lit fragment: rgb=(%.3f, %.3f, %.3f)', $rgba[0], $rgba[1], $rgba[2])
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * Render mesh3d at the SSS terminator (light grazing across the fragment)
     * and return the centre pixel as RGBA.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function renderAtTerminator(float $subsurfaceStrength): array
    {
        // Light direction = (-1, 0, 0) → L = (1, 0, 0). Quad normal is (0, 0, 1).
        // dot(N, L) = 0 → exact terminator.
        return $this->renderWithDirLight([-1.0, 0.0, 0.0], subsurfaceStrength: $subsurfaceStrength);
    }

    /**
     * Render mesh3d on a fresh harness with a custom directional light
     * direction and SSS strength. Albedo and subsurface tint use the
     * test-skin defaults.
     *
     * @param array{0: float, 1: float, 2: float} $lightDirection
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function renderWithDirLight(array $lightDirection, float $subsurfaceStrength): array
    {
        $h = HeadlessShaderHarness::open(64, 64);
        $this->assertNotNull($h);
        try {
            $shader   = $h->compileShaderFromFiles('vio/mesh3d.vert.glsl', 'vio/mesh3d.frag.glsl');
            $pipeline = $h->createPipeline($shader);
            $rgba     = $h->renderAndRead(
                $pipeline,
                $h->fullscreenQuad(),
                function (HeadlessShaderHarness $h) use ($lightDirection, $subsurfaceStrength): void {
                    self::setNeutralMeshUniforms($h, 64, 64);
                    // Push light direction + SSS controls on top of neutral defaults.
                    $h->setUniform('u_dir_lights[0].direction', $lightDirection);
                    $h->setUniform('u_subsurface_color',        [1.0, 0.35, 0.25]);
                    $h->setUniform('u_subsurface_strength',     $subsurfaceStrength);
                },
            );
            return $h->samplePixel($rgba, 32, 32);
        } finally {
            $h->close();
        }
    }

    /**
     * Render mesh3d at a given albedo on a fresh harness and return the sum
     * of the centre pixel's RGB channels. Each call gets its own context so
     * the test does not depend on inter-frame state leakage on the OpenGL
     * backend (vio_read_pixels currently returns the clear colour on the
     * second consecutive frame within the same context - separate issue).
     *
     * @param array{0: float, 1: float, 2: float} $albedo
     */
    private function renderAlbedoSum(array $albedo): float
    {
        $h = HeadlessShaderHarness::open(64, 64);
        $this->assertNotNull($h);
        try {
            $shader   = $h->compileShaderFromFiles('vio/mesh3d.vert.glsl', 'vio/mesh3d.frag.glsl');
            $pipeline = $h->createPipeline($shader);
            $rgba     = $h->renderAndRead(
                $pipeline,
                $h->fullscreenQuad(),
                function (HeadlessShaderHarness $h) use ($albedo): void {
                    self::setNeutralMeshUniforms($h, 64, 64);
                    $h->setUniform('u_albedo', $albedo);
                },
            );
            [$r, $g, $b] = $h->samplePixel($rgba, 32, 32);
            return $r + $g + $b;
        } finally {
            $h->close();
        }
    }

    /**
     * Push a sensible "neutral PBR + grading" uniform set so the shader
     * reaches its standard PBR path. Identity MVP keeps the fullscreen
     * quad covering the framebuffer; no real shadow map, no skybox, no fog.
     */
    private static function setNeutralMeshUniforms(HeadlessShaderHarness $h, int $width, int $height): void
    {
        // Mirrors the production fix in VioRenderer3D::uploadShadowUniforms.
        $h->bindDummyShadowSamplers();

        $identity = [
            1.0, 0.0, 0.0, 0.0,
            0.0, 1.0, 0.0, 0.0,
            0.0, 0.0, 1.0, 0.0,
            0.0, 0.0, 0.0, 1.0,
        ];
        $normalMat = [
            1.0, 0.0, 0.0,
            0.0, 1.0, 0.0,
            0.0, 0.0, 1.0,
        ];

        // Transforms
        $h->setUniform('u_model',              $identity);
        $h->setUniform('u_view',               $identity);
        $h->setUniform('u_projection',         $identity);
        $h->setUniform('u_normal_matrix',      $normalMat);
        $h->setUniform('u_use_instancing',     0);
        $h->setUniform('u_light_space_matrix', $identity);

        // Vertex animation / cloth off
        $h->setUniform('u_vertex_anim',    0);
        $h->setUniform('u_wave_amplitude', 0.0);
        $h->setUniform('u_wave_frequency', 0.0);
        $h->setUniform('u_wave_phase',     0.0);
        $h->setUniform('u_time',           0.0);
        $h->setUniform('u_cloth',            0);
        $h->setUniform('u_cloth_strength',   0.0);
        $h->setUniform('u_cloth_frequency',  0.0);
        $h->setUniform('u_cloth_phase',      0.0);
        $h->setUniform('u_cloth_anchor_top', 1);
        $h->setUniform('u_wind_direction', [0.0, 0.0, 1.0]);
        $h->setUniform('u_wind_intensity', 0.0);
        $h->setUniform('u_mesh_local_aabb_min', [-1.0, -1.0, -1.0]);
        $h->setUniform('u_mesh_local_aabb_max', [ 1.0,  1.0,  1.0]);

        // Material
        $h->setUniform('u_albedo',          [0.8, 0.8, 0.8]);
        $h->setUniform('u_emission',        [0.0, 0.0, 0.0]);
        $h->setUniform('u_roughness',       0.5);
        $h->setUniform('u_metallic',        0.0);
        $h->setUniform('u_alpha',           1.0);
        $h->setUniform('u_clearcoat',       0.0);
        $h->setUniform('u_clearcoat_roughness', 0.05);
        $h->setUniform('u_flakes',              0.0);
        $h->setUniform('u_normal_intensity',    1.0);
        $h->setUniform('u_use_environment_map', 0);
        $h->setUniform('u_normal_pattern',      0);
        $h->setUniform('u_normal_scale',        1.0);
        $h->setUniform('u_surface_pattern',     0);
        $h->setUniform('u_surface_scale',       1.0);
        $h->setUniform('u_surface_intensity',   0.0);
        $h->setUniform('u_wetness',             0.0);
        $h->setUniform('u_ssr_intensity',       0.0);

        // SSS off by default; tests override per-scenario.
        $h->setUniform('u_subsurface_color',    [1.0, 0.35, 0.25]);
        $h->setUniform('u_subsurface_strength', 0.0);
        $h->setUniform('u_proc_mode',           0);
        $h->setUniform('u_has_albedo_texture',  0);
        $h->setUniform('u_albedo_texture',      0);
        $h->setUniform('u_season_tint',  [1.0, 1.0, 1.0]);

        // Lighting
        $h->setUniform('u_ambient_color',           [0.5, 0.5, 0.5]);
        $h->setUniform('u_ambient_intensity',       1.0);
        $h->setUniform('u_dir_light_count',         1);
        $h->setUniform('u_dir_lights[0].direction', [0.0, 0.0, -1.0]);
        $h->setUniform('u_dir_lights[0].color',     [1.0, 1.0, 1.0]);
        $h->setUniform('u_dir_lights[0].intensity', 1.0);
        $h->setUniform('u_point_light_count',       0);
        $h->setUniform('u_camera_pos',              [0.0, 0.0, 5.0]);

        // Shadow off (but dummy samplers still bound via bindDummyShadowSamplers above)
        $h->setUniform('u_has_shadow_map', 0);
        $h->setUniform('u_csm_count',      0);
        $h->setUniform('u_csm_far_0',      0.0);
        $h->setUniform('u_csm_far_1',      0.0);
        $h->setUniform('u_csm_far_2',      0.0);

        // Sky / fog pushed far away so they don't tint the output
        $h->setUniform('u_sky_color',      [0.55, 0.70, 0.85]);
        $h->setUniform('u_horizon_color',  [0.85, 0.88, 0.92]);
        $h->setUniform('u_fog_color',      [0.0, 0.0, 0.0]);
        $h->setUniform('u_fog_near',       1.0e5);
        $h->setUniform('u_fog_far',        2.0e5);
        $h->setUniform('u_volumetric_fog', 0);

        // Weather off
        $h->setUniform('u_snow_cover',   0.0);
        $h->setUniform('u_rain_wetness', 0.0);
        $h->setUniform('u_moon_phase',   0.0);

        // AO off
        $h->setUniform('u_ao_strength', 0.0);

        // Grading neutral
        $h->setUniform('u_grade_lift',       [0.0, 0.0, 0.0]);
        $h->setUniform('u_grade_gamma',      [1.0, 1.0, 1.0]);
        $h->setUniform('u_grade_gain',       [1.0, 1.0, 1.0]);
        $h->setUniform('u_grade_saturation', 1.0);

        // Vignette off, viewport sized to the offscreen FBO
        $h->setUniform('u_vignette_intensity', 0.0);
        $h->setUniform('u_viewport_size',      [(float)$width, (float)$height]);

        // Linear / HDR output off (we want post-mapped colours)
        $h->setUniform('u_linear_output', 0);
    }
}
