<?php

declare(strict_types=1);

namespace PHPolygon\Testing\Shader;

use PHPolygon\Math\Vec3;

/**
 * PHP composition of the standard PBR path in mesh3d.frag.glsl `main()`:
 *
 *     ambient + Σ directional lights + emission + fog → outputColor()
 *
 * Mirrors the call order of the shader so a single fragment can be
 * evaluated CPU-side for tests. The point of this class is to catch
 * regressions in how the helpers from {@see MeshShaderMath} are wired
 * together, not to substitute for a real rasterizer.
 *
 * Out of scope (intentional simplifications vs. the full shader):
 *   - Procedural modes (water, sand, rock, palm, wood, thatch, moon, carpaint)
 *   - Procedural normal / surface patterns (require dFdx/dFdy)
 *   - Cascade shadow sampling (requires sampler2DShadow)
 *   - Texture sampling
 *   - Clearcoat lobe
 *   - Snow cover / wetness
 *   - Multiple point lights (single directional light suffices for tests)
 *   - Volumetric scatter
 *
 * Tests use this to assert "given inputs X, the composed fragment shader
 * does not produce vec4(0)" and similar invariants that GPU silently
 * violates without surfacing a signal.
 */
final class MeshShaderEvalCpu
{
    /**
     * Evaluate one fragment.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} RGBA in [0, 1]
     */
    public static function evalFragment(
        FragmentInputs $in,
        FragmentUniforms $u,
    ): array {
        $N = $in->normal->normalize();
        $V = $in->cameraPos->sub($in->worldPos)->normalize();
        $L = self::negate($u->dirLightDirection)->normalize();

        $roughness = max(0.04, min(1.0, $u->roughness));
        $metallic  = max(0.0, min(1.0, $u->metallic));
        $albedo    = $u->albedo;

        // F0: 0.04 for dielectrics, albedo for metals.
        $F0 = new Vec3(
            self::lerp(0.04, $albedo->x, $metallic),
            self::lerp(0.04, $albedo->y, $metallic),
            self::lerp(0.04, $albedo->z, $metallic),
        );

        $NdotV = max(0.001, $N->dot($V));

        // ─── Ambient ──────────────────────────────────────────────────
        $F_amb = MeshShaderMath::fresnelSchlick($NdotV, $F0);
        $kD_amb = new Vec3(
            (1.0 - $F_amb->x) * (1.0 - $metallic),
            (1.0 - $F_amb->y) * (1.0 - $metallic),
            (1.0 - $F_amb->z) * (1.0 - $metallic),
        );
        $ambientScale = $u->ambientIntensity;
        $color = new Vec3(
            $u->ambientColor->x * $ambientScale * $albedo->x * $kD_amb->x,
            $u->ambientColor->y * $ambientScale * $albedo->y * $kD_amb->y,
            $u->ambientColor->z * $ambientScale * $albedo->z * $kD_amb->z,
        );

        // ─── Directional light ────────────────────────────────────────
        $NdotL = max(0.0, $N->dot($L));
        if ($NdotL > 0.0) {
            $spec = MeshShaderMath::cookTorranceSpecular($N, $V, $L, $roughness, $F0);
            $H = $V->add($L)->normalize();
            $HdotV = max(0.0, $H->dot($V));
            $F = MeshShaderMath::fresnelSchlick($HdotV, $F0);
            $kD = new Vec3(
                (1.0 - $F->x) * (1.0 - $metallic),
                (1.0 - $F->y) * (1.0 - $metallic),
                (1.0 - $F->z) * (1.0 - $metallic),
            );

            $radiance = new Vec3(
                $u->dirLightColor->x * $u->dirLightIntensity,
                $u->dirLightColor->y * $u->dirLightIntensity,
                $u->dirLightColor->z * $u->dirLightIntensity,
            );

            // (kD * albedo / pi + spec) * radiance * NdotL
            $contrib = new Vec3(
                ($kD->x * $albedo->x / M_PI + $spec->x) * $radiance->x * $NdotL,
                ($kD->y * $albedo->y / M_PI + $spec->y) * $radiance->y * $NdotL,
                ($kD->z * $albedo->z / M_PI + $spec->z) * $radiance->z * $NdotL,
            );
            $color = $color->add($contrib);
        }

        // ─── Emission ─────────────────────────────────────────────────
        $color = $color->add($u->emission);

        // ─── Fog ──────────────────────────────────────────────────────
        $fogDist = $in->worldPos->sub($in->cameraPos)->length();
        $fogFactor = max(0.0, min(1.0,
            ($fogDist - $u->fogNear) / ($u->fogFar - $u->fogNear)
        ));
        $fogMix = 1.0 - exp(-$fogFactor * $fogFactor * 3.0);
        $color = new Vec3(
            self::lerp($color->x, $u->fogColor->x, $fogMix),
            self::lerp($color->y, $u->fogColor->y, $fogMix),
            self::lerp($color->z, $u->fogColor->z, $fogMix),
        );

        // ─── Output composition (grading + tonemap + gamma + vignette) ─
        return MeshShaderMath::outputColor(
            color: $color,
            alpha: $u->alpha,
            linearOutput: $u->linearOutput,
            gradeLift: $u->gradeLift,
            gradeGamma: $u->gradeGamma,
            gradeGain: $u->gradeGain,
            gradeSaturation: $u->gradeSaturation,
            vignetteIntensity: $u->vignetteIntensity,
            viewportSize: $u->viewportSize,
            fragCoord: $u->fragCoord,
        );
    }

    private static function lerp(float $a, float $b, float $t): float
    {
        return $a + ($b - $a) * $t;
    }

    private static function negate(Vec3 $v): Vec3
    {
        return new Vec3(-$v->x, -$v->y, -$v->z);
    }
}
