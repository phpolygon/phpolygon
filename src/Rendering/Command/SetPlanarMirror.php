<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\RenderLayers;

/**
 * The one planar mirror this frame renders (see {@see \PHPolygon\Rendering\Reflection\PlanarMirrors}):
 * the scene mirrored at the rectangle, drawn into a texture a mirror material samples.
 * One per frame - {@see \PHPolygon\System\PlanarMirrorSystem} picks it among the
 * {@see \PHPolygon\Component\PlanarMirror} components; a game without that system
 * may add the command itself. Without the command, or while the mirrors are not
 * registered, the frame is the one without mirrors byte for byte. Vio family only.
 */
readonly class SetPlanarMirror
{
    public Vec3 $normal;
    public Vec3 $right;

    /**
     * @param Vec3  $center          Middle of the mirror rectangle, world space.
     * @param Vec3  $normal          Normal of the reflecting side (toward the viewers); normalised here.
     * @param Vec3  $right           In-plane direction of the rectangle's width; orthogonalised against the normal.
     * @param float $halfWidth       Half the rectangle's width, m.
     * @param float $halfHeight      Half its height, m.
     * @param float $resolutionScale Size of the mirror texture as a fraction of the scene target (0.1..1).
     * @param float $maxDistance     Beyond this distance from the eye to the centre nothing is drawn; 0 = no limit.
     * @param int    $updateEvery    Redraw the texture every n-th frame (1 = every frame); the lookup is projective,
     *                               so a stale texture stays geometrically right, only the content lags.
     * @param int    $layerMask      {@see RenderLayers} the mirror draws.
     * @param float  $blur           Blur radius of the lookup in mirror-texture pixels (roughness); 0 = one tap.
     * @param string $id             Identity: a texture of another mirror is redrawn at once.
     * @param float  $clipBias       The clip plane lies this far in front of the mirror, m, so a panel
     *                               on the plane and slivers hugging it are not drawn into the image.
     */
    public function __construct(
        public Vec3 $center,
        Vec3 $normal,
        Vec3 $right,
        public float $halfWidth,
        public float $halfHeight,
        public float $resolutionScale = 0.5,
        public float $maxDistance = 0.0,
        public int $updateEvery = 1,
        public int $layerMask = RenderLayers::MIRROR_DEFAULT,
        public float $blur = 0.0,
        public string $id = '',
        public float $clipBias = 0.01,
    ) {
        $n = $normal->normalize();
        $r = $right->sub($n->mul($right->dot($n)));
        if ($r->lengthSquared() < 1e-12) {
            // Degenerate hint: any direction in the plane.
            $r = abs($n->y) < 0.9 ? new Vec3(0.0, 1.0, 0.0)->cross($n) : new Vec3(1.0, 0.0, 0.0)->cross($n);
        }
        $this->normal = $n;
        $this->right = $r->normalize();
    }

    /** Up direction of the rectangle (normal x right). */
    public function up(): Vec3
    {
        return $this->normal->cross($this->right);
    }

    /** Radius of the sphere round the rectangle. */
    public function boundingRadius(): float
    {
        return sqrt($this->halfWidth * $this->halfWidth + $this->halfHeight * $this->halfHeight);
    }
}
