<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Defines the rendering order for composited scene layers.
 *
 * Layers are rendered in enum value order (lowest first).
 * Each layer can optionally clear the depth buffer before rendering
 * so that 2D overlays always appear on top of 3D content.
 */
enum RenderLayer: int
{
    /** Skybox, distant background geometry. */
    case Background3D = 0;

    /** Main 3D world - entities, terrain, characters. */
    case World3D = 100;

    /** 2D overlays rendered in 3D space (billboards, indicators). */
    case Overlay2D = 200;

    /** Screen-space HUD - health bars, menus, debug text. */
    case HUD2D = 300;
}
