<?php

declare(strict_types=1);

namespace PHPolygon\Testing\Shader;

use PHPolygon\Math\Vec3;

/**
 * Uniform inputs consumed by {@see MeshShaderEvalCpu}. Defaults reproduce
 * the engine's neutral / "no extra processing" state: white albedo,
 * non-metallic, mid-roughness, no emission, no fog reach, neutral grading,
 * vignette off, ACES tone-map on.
 *
 * Each field corresponds 1:1 to a uniform name in mesh3d.frag.glsl so tests
 * can reason about shader behaviour without owning the GPU pipeline.
 */
final readonly class FragmentUniforms
{
    /**
     * @param array{0: float, 1: float} $viewportSize  pixels (W, H)
     * @param array{0: float, 1: float} $fragCoord     fragment pixel position
     */
    public function __construct(
        // Material
        public Vec3 $albedo = new Vec3(0.8, 0.8, 0.8),
        public float $roughness = 0.5,
        public float $metallic = 0.0,
        public Vec3 $emission = new Vec3(0.0, 0.0, 0.0),
        public float $alpha = 1.0,

        // Ambient
        public Vec3 $ambientColor = new Vec3(0.2, 0.2, 0.2),
        public float $ambientIntensity = 1.0,

        // Single directional light (point lights / multi-dir-light not yet covered)
        public Vec3 $dirLightDirection = new Vec3(0.0, -1.0, 0.0),
        public Vec3 $dirLightColor = new Vec3(1.0, 1.0, 1.0),
        public float $dirLightIntensity = 1.0,

        // Fog — defaults pushed far enough that fog does not tint the test scene
        public Vec3 $fogColor = new Vec3(0.0, 0.0, 0.0),
        public float $fogNear = 1.0e5,
        public float $fogFar = 2.0e5,

        // Grading / tonemap (mirrors `u_grade_*` + `u_linear_output`)
        public bool $linearOutput = false,
        public Vec3 $gradeLift = new Vec3(0.0, 0.0, 0.0),
        public Vec3 $gradeGamma = new Vec3(1.0, 1.0, 1.0),
        public Vec3 $gradeGain = new Vec3(1.0, 1.0, 1.0),
        public float $gradeSaturation = 1.0,

        // Vignette
        public float $vignetteIntensity = 0.0,
        public array $viewportSize = [1280.0, 720.0],
        public array $fragCoord = [640.0, 360.0],
    ) {}
}
