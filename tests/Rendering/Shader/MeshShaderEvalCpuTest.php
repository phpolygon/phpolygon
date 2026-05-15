<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Shader;

use PHPolygon\Math\Vec3;
use PHPolygon\Testing\Shader\FragmentInputs;
use PHPolygon\Testing\Shader\FragmentUniforms;
use PHPolygon\Testing\Shader\MeshShaderEvalCpu;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the PHP composition of the mesh3d fragment shader.
 * Where MeshShaderMathTest pins individual helpers, this suite asserts that
 * the wiring between ambient → dir-light → emission → fog → outputColor
 * stays sane.
 */
class MeshShaderEvalCpuTest extends TestCase
{
    /**
     * Regression: the gamma=0 bug surfaced as "every fragment is black even
     * though albedo, ambient and dir-light are non-zero". With the guard in
     * place this scenario must produce visible output. The same assertion
     * exists at the math-helper level; pinning it here too means a future
     * refactor that bypasses applyColorGrading still trips the alarm.
     */
    public function testNeutralUniformsProduceNonBlackOutput(): void
    {
        $rgba = MeshShaderEvalCpu::evalFragment(
            new FragmentInputs(
                normal:    new Vec3(0.0, 1.0, 0.0),
                worldPos:  new Vec3(0.0, 0.0, 0.0),
                cameraPos: new Vec3(0.0, 0.0, 5.0),
            ),
            new FragmentUniforms(
                // Light pointing straight down onto the up-facing fragment.
                dirLightDirection: new Vec3(0.0, -1.0, 0.0),
            ),
        );
        $this->assertGreaterThan(0.0, $rgba[0] + $rgba[1] + $rgba[2],
            'neutral uniforms must produce non-zero output');
        $this->assertEqualsWithDelta(1.0, $rgba[3], 1e-6);
    }

    /**
     * Regression: u_grade_gamma == 0 used to drive pow(x, 1.0/0.0) which on
     * the GPU is pow(x, +inf) → 0 for sub-1 values and undefined for x >= 1
     * (often NaN). The guard clamps gamma to >= 1e-3, which dodges the
     * NaN/Inf path. The resulting output is still effectively zero because
     * pow(0.5, 1000) underflows - the guard's job is to prevent NaN
     * propagation, not to recover meaningful colour. This test pins both:
     * no NaN, and predictable underflow rather than wild garbage values.
     */
    public function testGammaZeroDoesNotProduceNaN(): void
    {
        $rgba = MeshShaderEvalCpu::evalFragment(
            new FragmentInputs(
                normal:    new Vec3(0.0, 1.0, 0.0),
                worldPos:  new Vec3(0.0, 0.0, 0.0),
                cameraPos: new Vec3(0.0, 0.0, 5.0),
            ),
            new FragmentUniforms(
                dirLightDirection: new Vec3(0.0, -1.0, 0.0),
                gradeGamma: new Vec3(0.0, 0.0, 0.0),
            ),
        );
        foreach ([$rgba[0], $rgba[1], $rgba[2], $rgba[3]] as $idx => $channel) {
            $this->assertFalse(is_nan($channel), "channel {$idx} is NaN");
            $this->assertFalse(is_infinite($channel), "channel {$idx} is infinite");
        }
        $this->assertEqualsWithDelta(1.0, $rgba[3], 1e-6);
    }

    /** Darker albedo must yield darker final output (modulo tone-map). */
    public function testAlbedoBrightnessChangesOutput(): void
    {
        $base = function (Vec3 $albedo): array {
            return MeshShaderEvalCpu::evalFragment(
                new FragmentInputs(
                    normal:    new Vec3(0.0, 1.0, 0.0),
                    worldPos:  new Vec3(0.0, 0.0, 0.0),
                    cameraPos: new Vec3(0.0, 0.0, 5.0),
                ),
                new FragmentUniforms(
                    albedo: $albedo,
                    dirLightDirection: new Vec3(0.0, -1.0, 0.0),
                ),
            );
        };
        $dark   = $base(new Vec3(0.05, 0.05, 0.05));
        $bright = $base(new Vec3(0.9, 0.9, 0.9));

        $darkSum   = $dark[0]   + $dark[1]   + $dark[2];
        $brightSum = $bright[0] + $bright[1] + $bright[2];
        $this->assertLessThan($brightSum, $darkSum,
            sprintf('dark albedo (sum=%.3f) must be less than bright (sum=%.3f)', $darkSum, $brightSum));
    }

    /** Black surface receiving no light must produce pure black output. */
    public function testNoLightOnBlackSurfaceProducesBlack(): void
    {
        $rgba = MeshShaderEvalCpu::evalFragment(
            new FragmentInputs(
                normal:    new Vec3(0.0, 1.0, 0.0),
                worldPos:  new Vec3(0.0, 0.0, 0.0),
                cameraPos: new Vec3(0.0, 0.0, 5.0),
            ),
            new FragmentUniforms(
                albedo:            new Vec3(0.0, 0.0, 0.0),
                ambientColor:      new Vec3(0.0, 0.0, 0.0),
                dirLightIntensity: 0.0,
            ),
        );
        $this->assertEqualsWithDelta(0.0, $rgba[0], 1e-6);
        $this->assertEqualsWithDelta(0.0, $rgba[1], 1e-6);
        $this->assertEqualsWithDelta(0.0, $rgba[2], 1e-6);
        $this->assertSame(1.0, $rgba[3]);
    }

    /** Pure emissive surface yields output even without external light. */
    public function testEmissionLightsTheFragmentWithoutOtherSources(): void
    {
        $rgba = MeshShaderEvalCpu::evalFragment(
            new FragmentInputs(
                normal:    new Vec3(0.0, 1.0, 0.0),
                worldPos:  new Vec3(0.0, 0.0, 0.0),
                cameraPos: new Vec3(0.0, 0.0, 5.0),
            ),
            new FragmentUniforms(
                albedo:            new Vec3(0.0, 0.0, 0.0),
                emission:          new Vec3(0.5, 0.0, 0.0),
                ambientColor:      new Vec3(0.0, 0.0, 0.0),
                dirLightIntensity: 0.0,
            ),
        );
        $this->assertGreaterThan(0.0, $rgba[0], 'red emission must produce red output');
        $this->assertEqualsWithDelta(0.0, $rgba[1], 0.05);
        $this->assertEqualsWithDelta(0.0, $rgba[2], 0.05);
    }

    /** Light from behind the fragment (NdotL < 0) must not produce direct contribution. */
    public function testBackLitFragmentSkipsDirectLightContribution(): void
    {
        $front = MeshShaderEvalCpu::evalFragment(
            new FragmentInputs(
                normal:    new Vec3(0.0, 1.0, 0.0),
                worldPos:  new Vec3(0.0, 0.0, 0.0),
                cameraPos: new Vec3(0.0, 0.0, 5.0),
            ),
            new FragmentUniforms(
                albedo:            new Vec3(0.8, 0.8, 0.8),
                ambientColor:      new Vec3(0.0, 0.0, 0.0),
                dirLightDirection: new Vec3(0.0, -1.0, 0.0), // shining down onto upward normal
            ),
        );
        $back = MeshShaderEvalCpu::evalFragment(
            new FragmentInputs(
                normal:    new Vec3(0.0, 1.0, 0.0),
                worldPos:  new Vec3(0.0, 0.0, 0.0),
                cameraPos: new Vec3(0.0, 0.0, 5.0),
            ),
            new FragmentUniforms(
                albedo:            new Vec3(0.8, 0.8, 0.8),
                ambientColor:      new Vec3(0.0, 0.0, 0.0),
                dirLightDirection: new Vec3(0.0, 1.0, 0.0), // shining up against upward normal
            ),
        );
        $frontSum = $front[0] + $front[1] + $front[2];
        $backSum  = $back[0]  + $back[1]  + $back[2];
        $this->assertGreaterThan($backSum, $frontSum,
            'front-lit fragment must be brighter than back-lit fragment');
    }
}
