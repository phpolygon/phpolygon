<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * A costly code path of vio/mesh3d.frag that a program can be compiled without
 * (`#ifndef PHPOLYGON_OMIT_<value>`). Leaving a path out is a promise: no material
 * drawn with that program needs it. {@see ProcModeShaderRegistry::omissionViolations()}
 * checks the promise against the registered materials.
 */
enum ShaderPath: string
{
    /** Normal patterns, their height fields, cavity and parallax. By far the largest part of a compile. */
    case NormalPatterns = 'NORMAL_PATTERNS';
    case SurfacePatterns = 'SURFACE_PATTERNS';
    case Grass = 'GRASS';
    case Subsurface = 'SUBSURFACE';
    case Clearcoat = 'CLEARCOAT';
    case Snow = 'SNOW';
    case Volumetric = 'VOLUMETRIC';
    case CloudShadow = 'CLOUD_SHADOW';
    case ProbeGi = 'PROBE_GI';

    public function define(): string
    {
        return 'PHPOLYGON_OMIT_' . $this->value;
    }

    /** Whether $material needs this path. Paths driven by frame state, not by a material, answer false. */
    public function neededBy(Material $material): bool
    {
        return match ($this) {
            self::NormalPatterns => $material->normalPattern !== null,
            self::SurfacePatterns => $material->surfacePattern !== null,
            self::Grass => $material->grass,
            self::Subsurface => $material->subsurfaceStrength > 0.0,
            self::Clearcoat => $material->clearcoat > 0.0,
            self::Snow, self::Volumetric, self::CloudShadow, self::ProbeGi => false,
        };
    }
}
