<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Reflection;

use PHPolygon\Rendering\ProcModeShaderRegistry;

/**
 * Planar mirrors for any plane (walls, doors, lift cars, bathroom mirrors): while the
 * game has registered them and a {@see \PHPolygon\Rendering\Command\SetPlanarMirror}
 * reaches the renderer, the scene is drawn once more, mirrored at the rectangle, into
 * a texture the mirror's material samples. For a horizontal sea or pool see
 * {@see PlanarReflection}; the two may be registered together (two snippet samplers).
 *
 * Register once, before the renderer builds its programs:
 *
 *     PlanarMirrors::register($programs);        // sampler, uniforms, GLSL library
 *     PlanarMirrors::registerMode(7);            // a ready-made proc mode for the panel
 *
 * or call `mirrorSample()` from the game's own proc mode:
 *
 *   vec3 mirrorSample(vec3 worldPos, vec3 N, float blurScale, vec3 fallback)
 *
 * It projects the fragment's world position with the view-projection of the
 * mirror pass (a stale texture stays geometrically right), blurs by the command's
 * `blur` times `blurScale` over nine taps, and returns `fallback` where the mirror
 * holds no geometry or no mirror was drawn this frame. One snippet sampler of each
 * listed program's budget (slot 12 and up, never above 15 on a 16-unit backend,
 * see {@see ProcModeShaderRegistry::declareSampler()}).
 *
 * One mirror image per frame; everything else costs nothing: without
 * register(), or without a command, no pass, target or shader text exists and the
 * frame is the one without mirrors byte for byte. Vio family only.
 */
final class PlanarMirrors
{
    public const string SAMPLER = 'u_mirror_texture';

    /** mat4: view-projection of the mirror pass (oblique near plane included). */
    public const string VIEWPROJ = 'u_mirror_viewproj';

    /** vec4: x = 1 while an image is bound, y = 1 to flip the texture's v, zw = blur radius in uv. */
    public const string INFO = 'u_mirror_info';

    private const string LIBRARY = 'phpolygon_planar_mirror';

    private static bool $registered = false;

    /** @param list<string>|null $programs programs that get the library and the sampler (null = all) */
    public static function register(?array $programs = null): void
    {
        ProcModeShaderRegistry::declareSampler(self::SAMPLER, $programs);
        ProcModeShaderRegistry::declareUniform(self::VIEWPROJ, 'mat4');
        ProcModeShaderRegistry::declareUniform(self::INFO, 'vec4');
        $path = dirname(__DIR__, 3) . '/resources/shaders/lib/reflection/planar_mirror.glsl';
        $glsl = @file_get_contents($path);
        if ($glsl === false) {
            throw new \RuntimeException("Planar mirror library missing: {$path}");
        }
        ProcModeShaderRegistry::registerLibrary(self::LIBRARY, $glsl, null, $programs);
        self::$registered = true;
    }

    public static function registered(): bool
    {
        return self::$registered;
    }

    public static function clear(): void
    {
        self::$registered = false;
    }

    /**
     * A proc mode for a mirror panel: the sampled image times the material's albedo
     * (a tint), the albedo at 20 % where the mirror holds nothing. Unlit (the image
     * already carries the mirrored scene's lighting) and without fog. Returns
     * nothing; the material maps to $mode with ProcModeRegistry::map().
     * Needs {@see register()} to have run.
     */
    public static function registerMode(int $mode): void
    {
        $branch = "    if (u_proc_mode == {$mode}) {\n"
            . "        vec3 mirrorColor = mirrorSample(v_worldPos, N, 1.0, u_albedo * 0.2) * u_albedo;\n"
            . "        frag_color = outputColor(mirrorColor, 1.0);\n"
            . "        return;\n"
            . "    } else";
        ProcModeShaderRegistry::registerGlsl($mode, '', $branch);
    }
}

