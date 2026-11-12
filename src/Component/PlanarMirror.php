<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Rendering\RenderLayers;

/**
 * A planar mirror on an entity: a rectangle `$width` x `$height` metres centred on the
 * entity's Transform3D, reflecting toward its local +Z (so a wall mirror is a plane
 * mesh whose face looks along +Z). {@see \PHPolygon\System\PlanarMirrorSystem} picks the
 * best mirror of the frame and hands it to the renderer; only that one is drawn
 * (one mirror image per frame), the others keep their material's fallback.
 * The mirror MATERIAL is the game's (a proc mode calling `mirrorSample()`, see
 * {@see \PHPolygon\Rendering\Reflection\PlanarMirrors}); this component is the
 * budget and the geometry of the reflection. Put the panel's own MeshRenderer
 * on {@see RenderLayers::MIRROR_SURFACE} so the mirror never draws itself.
 */
#[Serializable]
#[Category('Rendering')]
class PlanarMirror extends AbstractComponent
{
    /** Width of the reflecting rectangle in metres (local X, times the entity's world scale). */
    #[Property]
    #[Range(0.05, 100.0)]
    public float $width = 2.0;

    /** Height in metres (local Y). */
    #[Property]
    #[Range(0.05, 100.0)]
    public float $height = 2.0;

    /** Off: never chosen. */
    #[Property]
    public bool $enabled = true;

    /** Size of the mirror texture relative to the scene target. */
    #[Property]
    #[Range(0.1, 1.0)]
    public float $resolutionScale = 0.5;

    /** Farther than this from the eye the mirror is not drawn (its material shows the fallback); 0 = no limit. */
    #[Property]
    #[Range(0.0, 500.0)]
    public float $maxDistance = 12.0;

    /** Redraw the image every n-th frame while this mirror stays the chosen one. */
    #[Property]
    #[Range(1, 60)]
    public int $updateEvery = 1;

    /** {@see RenderLayers} the image contains. The default leaves out mirror panels; BODY is in. */
    #[Property]
    public int $layerMask = RenderLayers::MIRROR_DEFAULT;

    /** Blur of the lookup in mirror-texture pixels (a rough mirror); 0 = sharp. */
    #[Property]
    #[Range(0.0, 16.0)]
    public float $blur = 0.0;

    public function __construct(
        float $width = 2.0,
        float $height = 2.0,
        float $resolutionScale = 0.5,
        float $maxDistance = 12.0,
        int $updateEvery = 1,
        int $layerMask = RenderLayers::MIRROR_DEFAULT,
        float $blur = 0.0,
    ) {
        $this->width = $width;
        $this->height = $height;
        $this->resolutionScale = $resolutionScale;
        $this->maxDistance = $maxDistance;
        $this->updateEvery = $updateEvery;
        $this->layerMask = $layerMask;
        $this->blur = $blur;
    }
}
