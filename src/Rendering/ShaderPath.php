<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * A costly code path of vio/mesh3d.frag that a program can be compiled without
 * (`#ifndef PHPOLYGON_OMIT_<value>`). Leaving a path out is a promise: no material
 * drawn with that program needs it. {@see ProcModeShaderRegistry::omissionViolations()}
 * checks the promise against the registered materials.
 *
 * The renderer also leaves out, from every program, each {@see automatic()} path that
 * no registered material needs: every feature of the übershader costs registers and so
 * occupancy on every pixel, used or not. When a material that needs one is registered
 * later, the programs are rebuilt once with it.
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
    /** Splatted ground (Material::$splatLayers). */
    case Splat = 'SPLAT';
    /** Triplanar albedo (Material::$triplanar). */
    case Triplanar = 'TRIPLANAR';
    /** Flipbook frames and streak fade (Material::$flipbook, $streakFade). */
    case Flipbook = 'FLIPBOOK';
    /** Fur shells (Material::$furLength). */
    case Fur = 'FUR';
    /** Strand hair shading (Material::$hair). */
    case Hair = 'HAIR';
    /** Per-material wetness surrogate (Material::$wetness). */
    case Wetness = 'WETNESS';
    /**
     * Weathering (Material::$weathering, on by default): aging zones and the salt crust
     * at the waterline are frame state, so it is never left out automatically.
     */
    case Weathering = 'WEATHERING';
    /** Glass fresnel and dirt (Material::$glass). */
    case Glass = 'GLASS';
    /** Cloth sheen, brushed metal, glitter (Material::$sheen, $brushed, $glitter). */
    case MaterialLook = 'MATERIAL_LOOK';
    /** Rim light and hair shine of figures (Material::$rimLight, $hairShine). */
    case FigureLook = 'FIGURE_LOOK';
    /** Lit windows and neon tubes (Material::$windowLights, $neonTube). */
    case EmissiveLook = 'EMISSIVE_LOOK';
    /** Soft particles (Material::$softParticle). */
    case SoftParticle = 'SOFT_PARTICLE';
    /** Refraction and frosted glass (Material::$refraction). */
    case Refraction = 'REFRACTION';
    /** Kit patterns: NormalPattern codes 13+ and SurfacePattern codes 8+ (formwork, aggregate, planks, ...). */
    case KitPatterns = 'KIT_PATTERNS';

    public function define(): string
    {
        return 'PHPOLYGON_OMIT_' . $this->value;
    }

    /** Whether $material needs this path. Paths driven by frame state, not by a material, answer false. */
    public function neededBy(Material $material): bool
    {
        return match ($this) {
            self::NormalPatterns => $material->normalPattern !== null || self::splatPatterned($material),
            self::SurfacePatterns => $material->surfacePattern !== null,
            self::Grass => $material->grass,
            self::Subsurface => $material->subsurfaceStrength > 0.0,
            self::Clearcoat => $material->clearcoat > 0.0,
            self::Snow, self::Volumetric, self::CloudShadow, self::ProbeGi => false,
            self::Splat => $material->splatLayers !== [],
            self::Triplanar => $material->triplanar > 0.0,
            self::Flipbook => ($material->flipbook[0] > 0 && $material->flipbook[1] > 0) || $material->streakFade,
            self::Fur => $material->furLength > 0.0,
            self::Hair => $material->hair,
            self::Wetness => $material->wetness > 0.0,
            self::Weathering => $material->weathering > 0.0,
            self::Glass => $material->glass > 0.0,
            self::MaterialLook => $material->sheen > 0.0 || $material->brushed > 0.0 || $material->glitter > 0.0,
            self::FigureLook => $material->rimLight > 0.0 || $material->hairShine > 0.0,
            self::EmissiveLook => $material->windowLights > 0.0 || $material->neonTube,
            self::SoftParticle => $material->softParticle > 0.0,
            self::Refraction => $material->refraction > 0.0,
            self::KitPatterns => NormalPattern::codeFor($material->normalPattern) >= 13
                || SurfacePattern::codeFor($material->surfacePattern) >= 8
                || self::splatKit($material),
        };
    }

    /**
     * The paths the materials alone decide: left out of every program while no
     * registered material needs them. Frame-driven paths never are.
     *
     * @return list<self>
     */
    public static function automatic(): array
    {
        $frameDriven = [self::Snow, self::Volumetric, self::CloudShadow, self::ProbeGi, self::Weathering];
        return array_values(array_filter(self::cases(), static fn (self $p): bool => !in_array($p, $frameDriven, true)));
    }

    private static function splatKit(Material $material): bool
    {
        foreach ($material->splatLayers as $layer) {
            if (NormalPattern::codeFor($layer->pattern) >= 13) {
                return true;
            }
        }
        return false;
    }

    private static function splatPatterned(Material $material): bool
    {
        foreach ($material->splatLayers as $layer) {
            if ($layer->pattern !== null) {
                return true;
            }
        }
        return false;
    }
}
