<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Shader;

use PHPolygon\Math\Vec3;
use PHPolygon\Testing\Shader\MeshShaderMath;
use PHPUnit\Framework\TestCase;

/**
 * Pin invariants on the PHP port of mesh3d.frag.glsl helpers. Each shader
 * function gets at least one regression case and one neutral-input case
 * so future refactors can't silently zero the output again.
 */
class MeshShaderMathTest extends TestCase
{
    // ─── applyColorGrading ──────────────────────────────────────────────

    /**
     * Regression: u_grade_gamma == 0 used to make pow(x, 1/0) = pow(x, +inf)
     * which collapses every fragment to 0 → full-black-screen bug. The shader
     * now clamps gamma to >= 1e-3; this asserts the PHP port mirrors that.
     */
    public function testColorGradingSurvivesZeroGamma(): void
    {
        $out = MeshShaderMath::applyColorGrading(
            new Vec3(0.5, 0.5, 0.5),
            new Vec3(0.0, 0.0, 0.0),
            new Vec3(0.0, 0.0, 0.0),   // gamma == 0 (the bug input)
            new Vec3(1.0, 1.0, 1.0),
            1.0,
        );
        $this->assertGreaterThan(0.0, $out->x + $out->y + $out->z,
            'gamma=0 must not zero the output');
        $this->assertFalse(is_nan($out->x) || is_nan($out->y) || is_nan($out->z),
            'gamma=0 must not produce NaN');
    }

    public function testColorGradingNeutralReturnsInput(): void
    {
        $out = MeshShaderMath::applyColorGrading(
            new Vec3(0.4, 0.6, 0.8),
            new Vec3(0.0, 0.0, 0.0),   // no lift
            new Vec3(1.0, 1.0, 1.0),   // neutral gamma
            new Vec3(1.0, 1.0, 1.0),   // neutral gain
            1.0,                       // full saturation
        );
        $this->assertEqualsWithDelta(0.4, $out->x, 1e-6);
        $this->assertEqualsWithDelta(0.6, $out->y, 1e-6);
        $this->assertEqualsWithDelta(0.8, $out->z, 1e-6);
    }

    public function testColorGradingZeroSaturationCollapsesToLuminance(): void
    {
        $out = MeshShaderMath::applyColorGrading(
            new Vec3(1.0, 0.0, 0.0),
            new Vec3(0.0, 0.0, 0.0),
            new Vec3(1.0, 1.0, 1.0),
            new Vec3(1.0, 1.0, 1.0),
            0.0,                       // sat=0 → grey
        );
        // luma of pure red = 0.2126
        $this->assertEqualsWithDelta(0.2126, $out->x, 1e-6);
        $this->assertEqualsWithDelta(0.2126, $out->y, 1e-6);
        $this->assertEqualsWithDelta(0.2126, $out->z, 1e-6);
    }

    // ─── outputColor (composed pipeline) ────────────────────────────────

    /**
     * Regression: with all-default uniforms (lift=0, gamma=0, gain=0, sat=0,
     * vignette=0) the engine produced a fully black frame even for non-zero
     * input colour. Since gamma is now clamped, the only remaining zero is
     * `gain=0`, which legitimately zeros the output. Pin the "neutral" case
     * (gain=1) and assert it survives.
     */
    public function testOutputColorWithNeutralUniformsPreservesNonZeroAlbedo(): void
    {
        $rgba = MeshShaderMath::outputColor(
            color: new Vec3(0.5, 0.5, 0.5),
            alpha: 1.0,
            linearOutput: false,
            gradeLift: new Vec3(0.0, 0.0, 0.0),
            gradeGamma: new Vec3(1.0, 1.0, 1.0),
            gradeGain: new Vec3(1.0, 1.0, 1.0),
            gradeSaturation: 1.0,
            vignetteIntensity: 0.0,
            viewportSize: [1280.0, 720.0],
            fragCoord: [640.0, 360.0],
        );
        $this->assertGreaterThan(0.0, $rgba[0] + $rgba[1] + $rgba[2],
            'neutral uniforms must not produce black output');
        $this->assertSame(1.0, $rgba[3]);
    }

    public function testOutputColorLinearOutputBypassesGradingAndTonemap(): void
    {
        $rgba = MeshShaderMath::outputColor(
            color: new Vec3(0.3, 0.6, 0.9),
            alpha: 0.8,
            linearOutput: true,        // HDR path: pass-through
            gradeLift: new Vec3(0.0, 0.0, 0.0),
            gradeGamma: new Vec3(0.0, 0.0, 0.0),  // would otherwise blow up
            gradeGain: new Vec3(0.0, 0.0, 0.0),
            gradeSaturation: 0.0,
            vignetteIntensity: 1.0,
            viewportSize: [1.0, 1.0],
            fragCoord: [0.5, 0.5],
        );
        $this->assertEqualsWithDelta(0.3, $rgba[0], 1e-6);
        $this->assertEqualsWithDelta(0.6, $rgba[1], 1e-6);
        $this->assertEqualsWithDelta(0.9, $rgba[2], 1e-6);
        $this->assertEqualsWithDelta(0.8, $rgba[3], 1e-6);
    }

    // ─── toneMapACES ────────────────────────────────────────────────────

    public function testToneMapACESBlackStaysBlack(): void
    {
        $out = MeshShaderMath::toneMapACES(new Vec3(0.0, 0.0, 0.0));
        $this->assertEqualsWithDelta(0.0, $out->x, 1e-6);
        $this->assertEqualsWithDelta(0.0, $out->y, 1e-6);
        $this->assertEqualsWithDelta(0.0, $out->z, 1e-6);
    }

    public function testToneMapACESClampsToOne(): void
    {
        $out = MeshShaderMath::toneMapACES(new Vec3(100.0, 50.0, 1000.0));
        $this->assertLessThanOrEqual(1.0, $out->x);
        $this->assertLessThanOrEqual(1.0, $out->y);
        $this->assertLessThanOrEqual(1.0, $out->z);
    }

    public function testToneMapACESMonotonic(): void
    {
        $low  = MeshShaderMath::toneMapACES(new Vec3(0.2, 0.2, 0.2));
        $mid  = MeshShaderMath::toneMapACES(new Vec3(0.5, 0.5, 0.5));
        $high = MeshShaderMath::toneMapACES(new Vec3(1.5, 1.5, 1.5));
        $this->assertLessThan($mid->x, $low->x);
        $this->assertLessThan($high->x, $mid->x);
    }

    // ─── fresnelSchlick ─────────────────────────────────────────────────

    public function testFresnelGrazingAngleReturnsOne(): void
    {
        // cosTheta = 0  → factor = 1^5 = 1 → result = F0 + (1 - F0) = (1, 1, 1)
        $out = MeshShaderMath::fresnelSchlick(0.0, new Vec3(0.04, 0.04, 0.04));
        $this->assertEqualsWithDelta(1.0, $out->x, 1e-6);
        $this->assertEqualsWithDelta(1.0, $out->y, 1e-6);
        $this->assertEqualsWithDelta(1.0, $out->z, 1e-6);
    }

    public function testFresnelNormalIncidenceReturnsF0(): void
    {
        // cosTheta = 1  → factor = 0 → result = F0
        $out = MeshShaderMath::fresnelSchlick(1.0, new Vec3(0.04, 0.10, 0.85));
        $this->assertEqualsWithDelta(0.04, $out->x, 1e-6);
        $this->assertEqualsWithDelta(0.10, $out->y, 1e-6);
        $this->assertEqualsWithDelta(0.85, $out->z, 1e-6);
    }

    // ─── distributionGGX ────────────────────────────────────────────────

    public function testDistributionGGXAtCenterPeaksForLowRoughness(): void
    {
        // At NdotH = 1 (perfect mirror reflection), D should be large for
        // low roughness (sharp specular).
        $smooth = MeshShaderMath::distributionGGX(1.0, 1e-6); // a2 ~ 0
        $rough  = MeshShaderMath::distributionGGX(1.0, 1.0);  // a2 = 1
        $this->assertGreaterThan($rough, $smooth);
    }

    public function testDistributionGGXFiniteForNdotHZero(): void
    {
        $d = MeshShaderMath::distributionGGX(0.0, 0.5);
        $this->assertFalse(is_nan($d));
        $this->assertGreaterThanOrEqual(0.0, $d);
    }

    // ─── geometrySmith ──────────────────────────────────────────────────

    public function testGeometrySmithBoundedZeroToOne(): void
    {
        // For sensible (NdotV, NdotL) inputs in [0, 1] and a2 > 0, output
        // should stay within [0, 1].
        $g = MeshShaderMath::geometrySmith(0.5, 0.5, 0.25);
        $this->assertGreaterThanOrEqual(0.0, $g);
        $this->assertLessThanOrEqual(1.0, $g);
    }

    // ─── cookTorranceSpecular ───────────────────────────────────────────

    public function testCookTorranceFiniteForGrazingLight(): void
    {
        // NdotL approaches 0; the function divides by NdotL but clamps the
        // denominator to 0.001 so result must stay finite.
        $N = new Vec3(0.0, 1.0, 0.0);
        $V = new Vec3(0.0, 1.0, 0.0);
        $L = new Vec3(1.0, 0.001, 0.0);   // nearly perpendicular to N
        $spec = MeshShaderMath::cookTorranceSpecular($N, $V, $L->normalize(), 0.3, new Vec3(0.04, 0.04, 0.04));
        $this->assertFalse(is_nan($spec->x) || is_nan($spec->y) || is_nan($spec->z));
        $this->assertGreaterThanOrEqual(0.0, $spec->x);
    }

    // ─── smoothstep ─────────────────────────────────────────────────────

    public function testSmoothstepClampsOutsideEdges(): void
    {
        $this->assertSame(0.0, MeshShaderMath::smoothstep(0.2, 0.8, 0.0));
        $this->assertSame(1.0, MeshShaderMath::smoothstep(0.2, 0.8, 1.0));
    }

    public function testSmoothstepIsHalfAtMidpoint(): void
    {
        // Hermite interpolation: at exactly the midpoint, t=0.5 and
        // result = 0.5*0.5*(3 - 1) = 0.5.
        $v = MeshShaderMath::smoothstep(0.0, 1.0, 0.5);
        $this->assertEqualsWithDelta(0.5, $v, 1e-6);
    }
}
