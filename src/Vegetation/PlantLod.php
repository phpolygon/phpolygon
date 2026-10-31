<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

/**
 * Level of detail of a generated plant. Budgets for a broadleaf tree:
 * Lod0 about 1500 triangles, Lod1 about 600, Lod2 about 140 and the
 * crossed-plane impostor a few dozen. Distances live on
 * {@see \PHPolygon\Component\FoliageInstances}. With a {@see PlantCanopy} the
 * budgets stay and the ladder reads: Lod0 branches and all tufts, Lod1 the same
 * wood and a third of the tufts, Lod2 trunk, three branches and a tuft
 * silhouette, impostor one jagged draw.
 */
enum PlantLod: int
{
    case Lod0 = 0;
    case Lod1 = 1;
    case Lod2 = 2;
    case Impostor = 3;
}
