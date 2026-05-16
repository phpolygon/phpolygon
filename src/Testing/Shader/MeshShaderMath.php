<?php

declare(strict_types=1);

namespace PHPolygon\Testing\Shader;

use PHPolygon\Math\Vec3;

/**
 * Pure-PHP port of the math helpers from resources/shaders/source/vio/mesh3d.frag.glsl
 * (and the parallel OpenGL/Metal copies). Each function mirrors its GLSL
 * counterpart line-for-line so unit tests can assert numerical equivalence
 * and pin invariants that the shader silently relies on (no div-by-zero,
 * non-negative output, neutral defaults produce neutral colour, etc.).
 *
 * Why this exists:
 *   - Shaders fail silently on the GPU: a broken expression produces
 *     black/NaN pixels with no log entry, no exception, no test signal.
 *   - The `u_grade_gamma == 0` regression that prompted this module
 *     would have been caught by a `testNeutralUniformsProduceNonZeroOutput`
 *     assertion on `applyColorGrading()`.
 *   - GLSL's intrinsics (`pow`, `mix`, `clamp`, `smoothstep`) have
 *     well-defined edge cases that are easy to violate; tests on this
 *     port act as a regression net for those cases.
 *
 * What this is NOT:
 *   - A rasterizer. Vertex transform, triangle setup, depth test and
 *     fragment coverage are out of scope. See `MeshShaderEvalCpu` for
 *     the composed `main()` of the fragment shader.
 *   - A GPU emulator. We don't handle dFdx/dFdy, texture sampling, or
 *     anything that depends on the screen-space neighbourhood.
 */
final class MeshShaderMath
{
    /**
     * Schlick's Fresnel approximation: returns the reflectance at the given
     * incident angle, given the base reflectance F0 (3-component because
     * metals have wavelength-dependent reflectance).
     *
     * Mirrors GLSL: `F0 + (1.0 - F0) * pow(clamp(1.0 - cosTheta, 0.0, 1.0), 5.0)`.
     */
    public static function fresnelSchlick(float $cosTheta, Vec3 $F0): Vec3
    {
        $factor = max(0.0, min(1.0, 1.0 - $cosTheta)) ** 5.0;
        return new Vec3(
            $F0->x + (1.0 - $F0->x) * $factor,
            $F0->y + (1.0 - $F0->y) * $factor,
            $F0->z + (1.0 - $F0->z) * $factor,
        );
    }

    /** GGX normal distribution function. `a2 = roughness^4`. */
    public static function distributionGGX(float $NdotH, float $a2): float
    {
        $denom = $NdotH * $NdotH * ($a2 - 1.0) + 1.0;
        return $a2 / (M_PI * $denom * $denom);
    }

    /** Smith's geometry term using Schlick-GGX (k = a^2 / 2). */
    public static function geometrySmith(float $NdotV, float $NdotL, float $a2): float
    {
        $k = $a2 * 0.5;
        $ggxV = $NdotV / ($NdotV * (1.0 - $k) + $k);
        $ggxL = $NdotL / ($NdotL * (1.0 - $k) + $k);
        return $ggxV * $ggxL;
    }

    /**
     * Cook-Torrance microfacet specular BRDF.
     * @param Vec3 $N world-space normal (unit)
     * @param Vec3 $V world-space view direction toward camera (unit)
     * @param Vec3 $L world-space light direction toward source (unit)
     */
    public static function cookTorranceSpecular(Vec3 $N, Vec3 $V, Vec3 $L, float $roughness, Vec3 $F0): Vec3
    {
        $H = $V->add($L)->normalize();
        $NdotH = max(0.0, $N->dot($H));
        $NdotV = max(0.001, $N->dot($V));
        $NdotL = max(0.0, $N->dot($L));
        $HdotV = max(0.0, $H->dot($V));

        $a = $roughness * $roughness;
        $a2 = $a * $a;

        $D = self::distributionGGX($NdotH, $a2);
        $G = self::geometrySmith($NdotV, $NdotL, $a2);
        $F = self::fresnelSchlick($HdotV, $F0);

        $denom = max(0.001, 4.0 * $NdotV * $NdotL);
        $scale = ($D * $G) / $denom;
        return new Vec3($F->x * $scale, $F->y * $scale, $F->z * $scale);
    }

    /**
     * ACES filmic tone-map (Narkowicz). Output clamped to [0, 1].
     * Constants kept verbatim with the shader source.
     */
    public static function toneMapACES(Vec3 $x): Vec3
    {
        $a = 2.51;
        $b = 0.03;
        $c = 2.43;
        $d = 0.59;
        $e = 0.14;
        return new Vec3(
            self::aces($x->x, $a, $b, $c, $d, $e),
            self::aces($x->y, $a, $b, $c, $d, $e),
            self::aces($x->z, $a, $b, $c, $d, $e),
        );
    }

    private static function aces(float $x, float $a, float $b, float $c, float $d, float $e): float
    {
        $num = $x * ($a * $x + $b);
        $den = $x * ($c * $x + $d) + $e;
        return max(0.0, min(1.0, $num / $den));
    }

    /**
     * Lift / Gamma / Gain colour grading with saturation control.
     * Mirrors the shader applyColorGrading, including the gamma=0 guard
     * (`gammaSafe = max(gamma, 1e-3)`) that prevents the previous full-
     * black-screen regression.
     */
    public static function applyColorGrading(
        Vec3 $color,
        Vec3 $lift,
        Vec3 $gamma,
        Vec3 $gain,
        float $saturation,
    ): Vec3 {
        $c = $color->add($lift);
        $gammaSafe = new Vec3(
            max($gamma->x, 1e-3),
            max($gamma->y, 1e-3),
            max($gamma->z, 1e-3),
        );
        $c = new Vec3(
            self::pow(max(0.0, $c->x), 1.0 / $gammaSafe->x),
            self::pow(max(0.0, $c->y), 1.0 / $gammaSafe->y),
            self::pow(max(0.0, $c->z), 1.0 / $gammaSafe->z),
        );
        $c = new Vec3($c->x * $gain->x, $c->y * $gain->y, $c->z * $gain->z);
        $luma = 0.2126 * $c->x + 0.7152 * $c->y + 0.0722 * $c->z;
        // mix(vec3(luma), color, saturation)
        return new Vec3(
            $luma + ($c->x - $luma) * $saturation,
            $luma + ($c->y - $luma) * $saturation,
            $luma + ($c->z - $luma) * $saturation,
        );
    }

    /**
     * Radial darkening from the screen centre.
     * @param array{0: float, 1: float} $viewportSize  Active draw viewport in pixels.
     * @param array{0: float, 1: float} $fragCoord     Fragment position in pixels.
     */
    public static function applyVignette(
        Vec3 $color,
        float $intensity,
        array $viewportSize,
        array $fragCoord,
    ): Vec3 {
        if ($intensity <= 0.0 || $viewportSize[0] <= 0.0) {
            return $color;
        }
        $uvX = $fragCoord[0] / $viewportSize[0];
        $uvY = $fragCoord[1] / $viewportSize[1];
        $dx = $uvX - 0.5;
        $dy = $uvY - 0.5;
        $r = sqrt($dx * $dx + $dy * $dy);
        $v = self::smoothstep(0.45, 0.85, $r);
        $factor = 1.0 - $v * $intensity;
        return new Vec3($color->x * $factor, $color->y * $factor, $color->z * $factor);
    }

    /**
     * Final fragment composition: lift/gamma/gain grading, ACES tone-map,
     * gamma 2.2 encode and optional vignette. Output is in [0, 1].
     *
     * Mirrors outputColor() in the shader, including the
     * `u_linear_output == 0` branch (when HDR is off we tonemap inline).
     *
     * @param array{0: float, 1: float} $viewportSize
     * @param array{0: float, 1: float} $fragCoord
     * @return array{0: float, 1: float, 2: float, 3: float} RGBA
     */
    public static function outputColor(
        Vec3 $color,
        float $alpha,
        bool $linearOutput,
        Vec3 $gradeLift,
        Vec3 $gradeGamma,
        Vec3 $gradeGain,
        float $gradeSaturation,
        float $vignetteIntensity,
        array $viewportSize,
        array $fragCoord,
    ): array {
        $c = new Vec3(max(0.0, $color->x), max(0.0, $color->y), max(0.0, $color->z));
        if (!$linearOutput) {
            $c = self::applyColorGrading($c, $gradeLift, $gradeGamma, $gradeGain, $gradeSaturation);
            $c = self::toneMapACES($c);
            $c = new Vec3(self::pow($c->x, 1.0 / 2.2), self::pow($c->y, 1.0 / 2.2), self::pow($c->z, 1.0 / 2.2));
            $c = self::applyVignette($c, $vignetteIntensity, $viewportSize, $fragCoord);
        }
        return [$c->x, $c->y, $c->z, $alpha];
    }

    /** GLSL-style smoothstep: 0 below edge0, 1 above edge1, smooth Hermite in between. */
    public static function smoothstep(float $edge0, float $edge1, float $x): float
    {
        if ($edge1 === $edge0) {
            return $x < $edge0 ? 0.0 : 1.0;
        }
        $t = max(0.0, min(1.0, ($x - $edge0) / ($edge1 - $edge0)));
        return $t * $t * (3.0 - 2.0 * $t);
    }

    /** GLSL `pow` semantics: negative base returns 0 (instead of throwing). */
    private static function pow(float $base, float $exp): float
    {
        if ($base <= 0.0) {
            return 0.0;
        }
        return $base ** $exp;
    }
}
