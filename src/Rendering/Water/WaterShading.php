<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Water;

use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\Command\SetShaderTexture;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Terrain\CoastTexture;

/**
 * Building blocks for a game's water look: GLSL functions its proc-mode snippets
 * call, and the uniforms behind them. The engine draws no water itself; the game's
 * snippet decides colours, strengths and which blocks it uses.
 *
 * - Coast ({@see register()} with `coast`): `waterCoast(xz)` reads a baked
 *   {@see CoastTexture} (shoreline distance, depth), `waterShoreFoam()` and
 *   `waterShoreWetness()` build on it. One sampler of each listed program's budget.
 * - Waves (always): `waterFootprint()`, `waterLayerFade()`, `waterDetailSlope()`
 *   (detail layers that fade out under a few pixels), `waterCrestFoam()`.
 * - Small looks (always): `waterBubble()` for an air bubble on a sphere, `waterJet()`
 *   for a velocity-stretched jet particle.
 * - Disturbances (`disturbances`): `waterWake(xz, look)` and `waterRipples(xz, look)`
 *   from the trail points and rings {@see \PHPolygon\System\WaterDisturbanceSystem}
 *   uploads, no render target.
 *
 * Vio family only: the native-GL renderer gets the uniforms but not the library,
 * so a look that calls it keeps a GL branch without it. Call before the renderer
 * warms its shaders, like every snippet registration.
 */
final class WaterShading
{
    public const string COAST_SAMPLER = 'u_water_coast';
    public const string COAST_MAP = 'u_water_coast_map';
    public const string COAST_RANGE = 'u_water_coast_range';

    /** Trail points the shader reads: four mat4 of four points. */
    public const int WAKE_POINTS = 16;

    /** Rings the shader reads: two mat4 of four rings. */
    public const int RIPPLES = 8;

    private const string LIBRARY = 'phpolygon_water';

    /**
     * @param list<string>|null $programs the programs that get the library and the
     *        coast sampler (variant names, ProcModeShaderRegistry::BASE_VARIANT for the
     *        base program); null = every program
     */
    public static function register(bool $coast = true, bool $disturbances = true, ?array $programs = null): void
    {
        $dir = dirname(__DIR__, 3) . '/resources/shaders/source/vio/water';
        $glsl = '';
        if ($coast) {
            ProcModeShaderRegistry::declareSampler(self::COAST_SAMPLER, $programs);
            ProcModeShaderRegistry::declareUniform(self::COAST_MAP, 'vec4');
            ProcModeShaderRegistry::declareUniform(self::COAST_RANGE, 'vec4');
            $glsl .= self::read($dir . '/water_coast.glsl');
        }
        $glsl .= self::read($dir . '/water_waves.glsl');
        $glsl .= self::read($dir . '/water_fx.glsl');
        if ($disturbances) {
            for ($k = 0; $k < self::WAKE_POINTS / 4; $k++) {
                ProcModeShaderRegistry::declareUniform(self::wakeUniform($k), 'mat4');
            }
            for ($k = 0; $k < self::RIPPLES / 4; $k++) {
                ProcModeShaderRegistry::declareUniform(self::rippleUniform($k), 'mat4');
            }
            $glsl .= self::read($dir . '/water_disturbances.glsl');
        }
        ProcModeShaderRegistry::registerLibrary(self::LIBRARY, $glsl, null, $programs);
    }

    /** Uniform of trail points 4k..4k+3. */
    public static function wakeUniform(int $k): string
    {
        return 'u_water_wake_' . $k;
    }

    /** Uniform of rings 4k..4k+3. */
    public static function rippleUniform(int $k): string
    {
        return 'u_water_ripple_' . $k;
    }

    /**
     * The commands that hand a coast to the shader: its texture (loaded under
     * $textureId, e.g. with `VioTextureManager::loadRgba()`) and its mapping. Sticky,
     * so once after loading is enough.
     *
     * @return list<SetShaderTexture|SetShaderParameter>
     */
    public static function coastCommands(CoastTexture $coast, string $textureId): array
    {
        return [
            new SetShaderTexture(self::COAST_SAMPLER, $textureId),
            new SetShaderParameter(self::COAST_MAP, $coast->mapUniform()),
            new SetShaderParameter(self::COAST_RANGE, $coast->rangeUniform()),
        ];
    }

    private static function read(string $path): string
    {
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("Water shader library missing: {$path}");
        }

        return $source;
    }
}
