<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

/**
 * Silhouette family of a generated plant. Every form shares the same
 * generator, LOD ladder, growth stages, wind and instancing; the form only
 * decides how trunk, branches and foliage are laid out.
 */
enum PlantForm: string
{
    /** Broadleaf tree with a round crown of foliage clumps. */
    case Round = 'round';
    /** Broadleaf tree with a wide, flat crown high on the trunk. */
    case Umbrella = 'umbrella';
    /** Narrow, tall crown (poplar-like) from low on the trunk. */
    case Column = 'column';
    /** Round crown whose outer clumps hang below the branch tips. */
    case Weeping = 'weeping';
    /** Needle tree: stacked cones around a straight trunk. */
    case Conifer = 'conifer';
    /** Palm: curved ringed trunk, crown of drooping frond bands. */
    case Palm = 'palm';
    /** Free-standing bush of foliage clumps on the ground. */
    case Bush = 'bush';
    /** Hedge: a row of box-like foliage clumps along local X. */
    case Hedge = 'hedge';

    /** Forms that grow from a trunk (and therefore may carry a trunk collider). */
    public function hasTrunk(): bool
    {
        return match ($this) {
            self::Bush, self::Hedge => false,
            default => true,
        };
    }

    /** Broadleaf forms built from trunk, swept branches and foliage clumps. */
    public function isBroadleaf(): bool
    {
        return match ($this) {
            self::Round, self::Umbrella, self::Column, self::Weeping => true,
            default => false,
        };
    }
}
