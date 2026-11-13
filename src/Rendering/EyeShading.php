<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * A procedural eye for the surface hook ({@see ProcModeShaderRegistry::registerSurfaceGlsl()}), no texture: iris with
 * radial fibres, collarette and limbal ring, a pupil that follows a size parameter, an off-white veined sclera,
 * a wet smooth cornea, and the iris seen THROUGH the cornea (the view ray refracts, index 1.336, to an iris plane
 * behind the apex), so the iris has depth and shifts with the viewing angle. The cornea's roughness is low, so a
 * point or sun light leaves a small sharp highlight instead of a quad. The eye mesh is a sphere around its local
 * origin with the pupil axis along local +Z; the view in local space is rebuilt from screen derivatives, so no model
 * matrix is needed and instanced eyes work.
 *
 * Use: prepend {@see glsl()} to the hook helpers and call from the hook (gate it by a proc mode):
 *
 *     ProcModeShaderRegistry::registerSurfaceGlsl(
 *         EyeShading::glsl(),
 *         "if (u_proc_mode == 77) {\n"
 *         . "    EyeSurface e = eye_surface(v_localPos, v_worldPos, u_camera_pos, vec3(0.12, 0.28, 0.45), vec4(0.5, 0.35, 0.18, 0.12), 0.0);\n"
 *         . "    albedo = e.albedo; roughness = e.roughness; metallic = 0.0; surfaceEmission += e.emission;\n"
 *         . "}\n");
 *
 * Inputs: iris colour (linear), `p` = (iris angular radius in radians ~0.5, pupil radius as a share of the iris
 * ~0.2 bright .. 0.55 dark, iris plane depth in eyeball radii ~0.18, limbal ring width ~0.12), a seed per eye.
 * The outputs are opaque (the eye is one closed surface: no separate transparent cornea shell needed).
 */
final class EyeShading
{
    private function __construct() {}

    private static ?string $glsl = null;

    public static function glsl(): string
    {
        return self::$glsl ??= (string) file_get_contents(dirname(__DIR__, 2) . '/resources/shaders/lib/eye.glsl');
    }
}
