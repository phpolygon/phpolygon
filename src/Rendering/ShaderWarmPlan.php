<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * The programs VioRenderer3D::warmShaders() compiles, in compile order, known
 * before the first one is compiled: the total a progress display counts against.
 *
 * It names the programs, it does not compile them: the compile sequence itself
 * stays in VioRenderer3D::initShaders(). A program named here that is skipped or
 * fails there is still reported as a finished step by {@see ShaderWarmProgress};
 * one compiled there but missing here is not counted. ShaderWarmPlanTest reads
 * initShaders() and fails when the two drift apart.
 *
 * Not listed: the tessellation twins ('<program>#tess') of tessellated materials,
 * which are built from an already compiled program.
 */
final class ShaderWarmPlan
{
    /** Compiled on every backend; the optional ones (baked light, froxel fog) may fail and are left out then. */
    private const array CORE = [
        'default', 'unlit', 'shadow', 'depth', 'normals', 'skybox',
        'gbuffer', 'ssao', 'sdf_ao', 'sdf_ao_blur', 'baked_light', 'ssao_blur', 'ssao_temporal',
        'ssr', 'ssr_composite',
        'sky_gradient', 'sky_sun', 'sky_moon', 'sky_stars', 'sky_clouds', 'sky_haze', 'sky_cloud_cache',
        'froxel_inject', 'froxel_integrate', 'froxel_apply',
    ];

    /** Only on a backend with a stencil buffer. */
    private const array OUTLINE = ['outline'];

    /** Only on a backend with multiple render targets. */
    private const array MRT = [
        'default_mrt', 'composite', 'skybox_mrt',
        'sky_gradient_mrt', 'sky_sun_mrt', 'sky_moon_mrt', 'sky_stars_mrt', 'sky_clouds_mrt', 'sky_haze_mrt', 'sky_cloud_cache_mrt',
        'froxel_apply_mrt',
    ];

    /**
     * @param bool $outline the backend draws outlines (stencil buffer)
     * @param bool $mrt the backend has the MRT scene path
     * @param list<string> $variants shader variants compiled up front, in compile order; the base
     *                               variant and repeats are ignored
     * @return list<string> program ids, each once
     */
    public static function programs(bool $outline, bool $mrt, array $variants = []): array
    {
        $programs = self::CORE;
        if ($outline) {
            $programs = [...$programs, ...self::OUTLINE];
        }
        if ($mrt) {
            $programs = [...$programs, ...self::MRT];
        }
        foreach ($variants as $variant) {
            if ($variant === ProcModeShaderRegistry::BASE_VARIANT) {
                continue;
            }
            $programs[] = 'default@' . $variant;
            if ($mrt) {
                $programs[] = 'default_mrt@' . $variant;
            }
        }
        return array_values(array_unique($programs));
    }
}
