<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Bit masks naming what a draw is, for the passes that choose by kind: a planar mirror
 * ({@see Command\SetPlanarMirror::$layerMask}) draws only the layers it asks for.
 * The main camera, the shadow pass and the water reflection ignore layers: a draw
 * lands in them by its own flags ({@see Command\DrawMesh::$shadowOnly},
 * `castShadows`). The meaning of a bit beyond these constants is the game's.
 */
final class RenderLayers
{
    /** Ordinary world geometry: the default of every draw. */
    public const int WORLD = 1;

    /** A figure's body seen from outside (a first-person player's, hidden from the main camera). */
    public const int BODY = 2;

    /** The mirror surface itself: a mirror never draws its own panel into its own image. */
    public const int MIRROR_SURFACE = 4;

    public const int ALL = 0x7FFFFFFF;

    /** What a mirror draws unless told otherwise: everything but mirror panels. */
    public const int MIRROR_DEFAULT = self::ALL & ~self::MIRROR_SURFACE;

    private function __construct() {}
}
