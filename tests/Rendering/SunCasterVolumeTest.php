<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Frustum;
use PHPolygon\Rendering\SunCasterVolume;

/**
 * SunCasterVolume::planes() is an extraction of VioRenderer3D's private
 * computeLightProjView() + sunCasterVolume() (see that class - it now
 * delegates here with its own CASCADE_ORTHO_SIZES/SHADOW_MAP_RESOLUTION, so
 * the two are the same code path by construction). What this test actually
 * guards against is a transcription error in the extraction: every assertion
 * re-derives the expected light position/view/projection independently from
 * the same formula documented in the class, for sun directions that exercise
 * both up-vector branches (near-vertical and not).
 */
final class SunCasterVolumeTest extends TestCase
{
    public function testNullForAZeroLengthDirection(): void
    {
        self::assertNull(SunCasterVolume::planes(new Vec3(0.0, 0.0, 0.0), null));
        self::assertNull(SunCasterVolume::planes(new Vec3(0.0005, 0.0, 0.0), null));
    }

    public function testFourPlanesNoNearOrFar(): void
    {
        $planes = SunCasterVolume::planes(new Vec3(0.0, -1.0, 0.0), null, 150.0, 2048);
        self::assertNotNull($planes);
        self::assertCount(4, $planes, 'near/far are deliberately excluded - a tall caster toward the sun always stays in');
    }

    /**
     * Straight-down sun (the common case, no texel snap - $shadowCenter is
     * null): hand-derive the light position/view/projection from the exact
     * same formula as the class and compare the resulting planes.
     */
    public function testStraightDownSunMatchesHandDerivedPlanes(): void
    {
        $lightDir = new Vec3(0.0, -1.0, 0.0);
        $orthoSize = 150.0;

        $center = Vec3::zero();
        $casterHeadroom = max(30.0, $orthoSize);
        $backoff = $orthoSize + $casterHeadroom;
        $lightPos = new Vec3(0.0, $backoff, 0.0); // -(-1)*backoff on Y
        $up = new Vec3(0.0, 0.0, 1.0); // |dy|=1 > 0.999 -> z-up branch
        $expectedView = Mat4::lookAt($lightPos, $center, $up);
        $expectedProj = Mat4::orthographic(-$orthoSize, $orthoSize, -$orthoSize, $orthoSize, 0.5, $backoff + $orthoSize + 5.0);
        $expected = array_slice(Frustum::planes($expectedProj, $expectedView), 0, 4);

        $actual = SunCasterVolume::planes($lightDir, null, $orthoSize, 2048);

        self::assertNotNull($actual);
        self::assertCount(4, $actual);
        foreach ($expected as $i => $plane) {
            foreach ($plane as $j => $component) {
                self::assertEqualsWithDelta($component, $actual[$i][$j], 1e-9, "plane {$i} component {$j}");
            }
        }
    }

    /**
     * A near-horizontal sun must take the y-up branch (not the z-up one a
     * near-vertical sun needs) - distinguishing the two confirms the
     * `abs(dy) > 0.999` switch survived the extraction.
     */
    public function testLowSunUsesTheYUpBranch(): void
    {
        $lightDir = new Vec3(-1.0, -0.2, 0.0);
        $orthoSize = 150.0;
        $len = sqrt($lightDir->x ** 2 + $lightDir->y ** 2 + $lightDir->z ** 2);
        $dir = new Vec3($lightDir->x / $len, $lightDir->y / $len, $lightDir->z / $len);

        $casterHeadroom = max(30.0, $orthoSize);
        $backoff = $orthoSize + $casterHeadroom;
        $lightPos = new Vec3(-$dir->x * $backoff, -$dir->y * $backoff, -$dir->z * $backoff);
        $up = new Vec3(0.0, 1.0, 0.0); // |dy| well below 0.999
        $expectedView = Mat4::lookAt($lightPos, Vec3::zero(), $up);
        $expectedProj = Mat4::orthographic(-$orthoSize, $orthoSize, -$orthoSize, $orthoSize, 0.5, $backoff + $orthoSize + 5.0);
        $expected = array_slice(Frustum::planes($expectedProj, $expectedView), 0, 4);

        $actual = SunCasterVolume::planes($lightDir, null, $orthoSize, 2048);

        self::assertNotNull($actual);
        foreach ($expected as $i => $plane) {
            foreach ($plane as $j => $component) {
                self::assertEqualsWithDelta($component, $actual[$i][$j], 1e-9, "plane {$i} component {$j}");
            }
        }
    }

    public function testShadowCenterIsSnappedToTheTexelGrid(): void
    {
        $orthoSize = 150.0;
        $resolution = 2048;
        $worldUnitsPerTexel = (2.0 * $orthoSize) / $resolution;
        // An off-grid centre must round to the nearest texel multiple - test
        // this indirectly: two centres within the same texel cell produce
        // IDENTICAL planes (the snap collapses them to the same light position).
        $a = SunCasterVolume::planes(new Vec3(0.0, -1.0, 0.0), new Vec3(1000.0, 0.0, 1000.0), $orthoSize, $resolution);
        $b = SunCasterVolume::planes(new Vec3(0.0, -1.0, 0.0), new Vec3(1000.0 + $worldUnitsPerTexel * 0.1, 0.0, 1000.0), $orthoSize, $resolution);
        self::assertSame($a, $b, 'both centres fall in the same texel cell after snapping');
    }

    public function testDefaultsMatchVioRenderer3DsCurrentConstants(): void
    {
        // Documented (not enforced by code) parity with VioRenderer3D's
        // private CASCADE_ORTHO_SIZES/SHADOW_MAP_RESOLUTION as of this
        // writing - see the class docblock on why these can't be shared
        // constants without exposing renderer internals.
        self::assertSame(150.0, SunCasterVolume::DEFAULT_ORTHO_SIZE);
        self::assertSame(2048, SunCasterVolume::DEFAULT_RESOLUTION);
    }
}
