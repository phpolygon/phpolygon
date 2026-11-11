<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;

/**
 * The four side planes (no near/far - a tall caster toward the sun always
 * stays in) of the light-space ortho box a sun shadow cascade would cover:
 * the same light position/view/projection math as VioRenderer3D's private
 * computeLightProjView() (texel-snapped centre, headroom backoff, up-vector
 * switch near the poles), extracted here as pure math with no renderer
 * state so VisibilityService can classify shadow casters with the identical
 * volume the renderer will actually render into - see VioRenderer3D::sunCasterVolume(),
 * which now delegates here (same values as before this extraction; covered
 * by {@see \PHPolygon\Tests\Rendering\SunCasterVolumeParityTest}).
 */
final class SunCasterVolume
{
    private function __construct()
    {
    }

    /**
     * Default largest-cascade half-extent and shadow-map resolution, mirroring
     * VioRenderer3D's own `CASCADE_ORTHO_SIZES`/`SHADOW_MAP_RESOLUTION` as of
     * this writing. VisibilityService uses these when a caller does not know
     * (or does not care about) the renderer's actual shadow settings; pass the
     * real values through beginFrame()/beginFrameFrom() to stay exact if the
     * renderer's shadow quality ever changes independently of this constant.
     */
    public const float DEFAULT_ORTHO_SIZE = 150.0;
    public const int DEFAULT_RESOLUTION = 2048;

    /**
     * @return list<array{0:float,1:float,2:float,3:float}>|null null when
     *         $lightDir has (near) zero length - nothing to test against.
     */
    public static function planes(
        Vec3 $lightDir,
        ?Vec3 $shadowCenter,
        float $orthoSize = self::DEFAULT_ORTHO_SIZE,
        int $resolution = self::DEFAULT_RESOLUTION,
    ): ?array {
        $len = sqrt($lightDir->x ** 2 + $lightDir->y ** 2 + $lightDir->z ** 2);
        if ($len < 0.001) {
            return null;
        }
        $dx = $lightDir->x / $len;
        $dy = $lightDir->y / $len;
        $dz = $lightDir->z / $len;

        $center = $shadowCenter ?? Vec3::zero();
        $s = $orthoSize;
        if ($shadowCenter !== null) {
            $worldUnitsPerTexel = (2.0 * $s) / $resolution;
            $center = new Vec3(
                round($center->x / $worldUnitsPerTexel) * $worldUnitsPerTexel,
                round($center->y / $worldUnitsPerTexel) * $worldUnitsPerTexel,
                round($center->z / $worldUnitsPerTexel) * $worldUnitsPerTexel,
            );
        }

        // Same headroom/backoff as computeLightProjView(): room for tall
        // casters above the box, light pulled back far enough that its own
        // near plane does not clip them.
        $casterHeadroom = max(30.0, $s);
        $backoff = $s + $casterHeadroom;
        $lightPos = new Vec3(
            $center->x - $dx * $backoff,
            $center->y - $dy * $backoff,
            $center->z - $dz * $backoff,
        );

        $up = abs($dy) > 0.999
            ? new Vec3(0.0, 0.0, 1.0)
            : new Vec3(0.0, 1.0, 0.0);

        $lightView = Mat4::lookAt($lightPos, $center, $up);
        $nearPlane = 0.5;
        $farPlane = $backoff + $s + 5.0;
        $lightProj = Mat4::orthographic(-$s, $s, -$s, $s, $nearPlane, $farPlane);

        return array_slice(Frustum::planes($lightProj, $lightView), 0, 4);
    }
}
