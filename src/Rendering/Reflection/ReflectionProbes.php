<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Reflection;

use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\Command\SetShaderTexture;
use PHPolygon\Rendering\ProcModeShaderRegistry;

/**
 * Local reflection probes for a game's snippets. The game captures cubemaps (any way
 * it likes, e.g. {@see \PHPolygon\Fieldtracing\GpuReflectionBaker} from an SDF),
 * registers them in the CubemapRegistry and lists them here as {@see ReflectionProbe}s;
 * {@see \PHPolygon\System\ReflectionProbeSystem} binds the {@see SLOTS} probes nearest
 * to the camera every frame.
 *
 * GLSL (library, programs passed to {@see register()}):
 *   vec3 reflectionProbeSample(vec3 worldPos, vec3 R, vec3 fallback)
 * returns the box-projected reflection of the probe holding worldPos (the nearer one
 * on top where two overlap) and $fallback outside every bound probe, blended across
 * each box's edge. Each slot is a samplerCube of the program's sampler budget, so the
 * library suits a program without probe GI (a water variant), not the base program.
 *
 * Vio family only: the native-GL renderer binds no snippet samplers, so a look that
 * calls it keeps a GL branch without it.
 */
final class ReflectionProbes
{
    /** Probes bound at a time. */
    public const int SLOTS = 2;

    private const string LIBRARY = 'phpolygon_reflection_probes';

    /** @var list<ReflectionProbe> */
    private static array $probes = [];

    private static bool $registered = false;

    /**
     * @param list<string>|null $programs programs that get the library and the samplers
     *        (variant names, ProcModeShaderRegistry::BASE_VARIANT for the base program)
     */
    public static function register(?array $programs = null): void
    {
        for ($k = 0; $k < self::SLOTS; $k++) {
            ProcModeShaderRegistry::declareSampler(self::samplerName($k), $programs, cube: true);
            ProcModeShaderRegistry::declareUniform(self::boxMinUniform($k), 'vec4');
            ProcModeShaderRegistry::declareUniform(self::boxMaxUniform($k), 'vec4');
            ProcModeShaderRegistry::declareUniform(self::positionUniform($k), 'vec4');
        }
        $path = dirname(__DIR__, 3) . '/resources/shaders/lib/reflection/reflection_probes.glsl';
        $glsl = @file_get_contents($path);
        if ($glsl === false) {
            throw new \RuntimeException("Reflection probe library missing: {$path}");
        }
        ProcModeShaderRegistry::registerLibrary(self::LIBRARY, $glsl, null, $programs);
        self::$registered = true;
    }

    public static function isRegistered(): bool
    {
        return self::$registered;
    }

    /** @param list<ReflectionProbe> $probes */
    public static function set(array $probes): void
    {
        self::$probes = $probes;
    }

    public static function add(ReflectionProbe $probe): void
    {
        self::$probes[] = $probe;
    }

    /** @return list<ReflectionProbe> */
    public static function all(): array
    {
        return self::$probes;
    }

    public static function clear(): void
    {
        self::$probes = [];
        self::$registered = false;
    }

    /**
     * The probes to bind for an eye at $eye, nearest first: distance to the box (0
     * inside), the smaller box first where both hold the eye, then registration order.
     *
     * @return list<ReflectionProbe>
     */
    public static function nearest(Vec3 $eye, int $count = self::SLOTS): array
    {
        $ranked = [];
        foreach (self::$probes as $i => $probe) {
            $ranked[] = [$probe->distanceTo($eye), $probe->volume(), $i, $probe];
        }
        usort($ranked, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(static fn (array $r): ReflectionProbe => $r[3], array_slice($ranked, 0, $count));
    }

    /**
     * The sticky commands that bind the probes for $eye. An unused slot gets
     * w = 0 in its box, which the shader reads as "no probe".
     *
     * @return list<SetShaderTexture|SetShaderParameter>
     */
    public static function commandsFor(Vec3 $eye): array
    {
        $chosen = self::nearest($eye);
        $out = [];
        for ($k = 0; $k < self::SLOTS; $k++) {
            $probe = $chosen[$k] ?? null;
            if ($probe === null) {
                $out[] = new SetShaderTexture(self::samplerName($k), null);
                $out[] = new SetShaderParameter(self::boxMinUniform($k), [0.0, 0.0, 0.0, 0.0]);
                $out[] = new SetShaderParameter(self::boxMaxUniform($k), [0.0, 0.0, 0.0, 0.0]);
                $out[] = new SetShaderParameter(self::positionUniform($k), [0.0, 0.0, 0.0, 0.0]);
                continue;
            }
            $out[] = new SetShaderTexture(self::samplerName($k), $probe->cubemapId);
            $out[] = new SetShaderParameter(self::boxMinUniform($k), [$probe->min->x, $probe->min->y, $probe->min->z, 1.0]);
            $out[] = new SetShaderParameter(self::boxMaxUniform($k), [$probe->max->x, $probe->max->y, $probe->max->z, $probe->blend]);
            $out[] = new SetShaderParameter(self::positionUniform($k), [$probe->position->x, $probe->position->y, $probe->position->z, 0.0]);
        }

        return $out;
    }

    public static function samplerName(int $k): string
    {
        return 'u_refl_probe_' . $k;
    }

    public static function boxMinUniform(int $k): string
    {
        return 'u_refl_probe_min_' . $k;
    }

    public static function boxMaxUniform(int $k): string
    {
        return 'u_refl_probe_max_' . $k;
    }

    public static function positionUniform(int $k): string
    {
        return 'u_refl_probe_pos_' . $k;
    }
}
