<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Effects\EffectDefinition;
use PHPolygon\Rendering\Effects\EffectFallback;
use PHPolygon\Rendering\Effects\EffectRegistry;
use PHPolygon\Rendering\Effects\EffectTier;
use PHPolygon\Rendering\Effects\FlashClass;
use PHPolygon\Rendering\Effects\ReducedMotion;

/**
 * Geometry and register entry of the visible spot-light beams
 * ({@see Command\DrawLightCone}). Pure maths, no GPU: the renderer draws one unit
 * cone mesh per beam, placed by {@see modelMatrix()}; the shader finds where the view
 * ray runs inside the exact cone and adds the light scattered along that stretch.
 */
final class LightCones
{
    /** Effect setting and register entry: `GraphicsSettings::$effects['light_cone'] = false` switches every beam off. */
    public const string EFFECT = 'light_cone';

    /** Sides of the cone mesh. The mesh circumscribes the exact cone, so no beam pixel is lost at its rim. */
    public const int SEGMENTS = 24;

    /** Widest cone drawn (half-angle): beyond it the cone is too flat for a beam. */
    public const float MAX_ANGLE = 1.4;

    private function __construct() {}

    /**
     * The unit cone: apex at the origin, axis +Z, base disc of radius 1 at z = 1,
     * closed and wound CCW seen from outside (the transparent pass's convention).
     */
    public static function mesh(int $segments = self::SEGMENTS): MeshData
    {
        $vertices = [0.0, 0.0, 0.0];
        $normals = [0.0, 0.0, -1.0];
        $uvs = [0.5, 0.0];
        // Rim (side normals: perpendicular to the slant of a 45-degree cone).
        for ($i = 0; $i < $segments; $i++) {
            $a = 2.0 * M_PI * $i / $segments;
            $c = cos($a);
            $s = sin($a);
            array_push($vertices, $c, $s, 1.0);
            array_push($normals, $c * M_SQRT1_2, $s * M_SQRT1_2, -M_SQRT1_2);
            array_push($uvs, $i / $segments, 1.0);
        }
        $centre = $segments + 1;
        array_push($vertices, 0.0, 0.0, 1.0);
        array_push($normals, 0.0, 0.0, 1.0);
        array_push($uvs, 0.5, 1.0);
        $indices = [];
        for ($i = 0; $i < $segments; $i++) {
            $r0 = 1 + $i;
            $r1 = 1 + ($i + 1) % $segments;
            array_push($indices, 0, $r1, $r0);          // side
            array_push($indices, $centre, $r0, $r1);    // base
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }

    /**
     * Places the unit cone: apex at $apex, axis along $direction, $length along it,
     * base radius widened so the polygon circumscribes the exact cone. Right-handed
     * (no mirroring), so the winding of {@see mesh()} keeps facing outward.
     */
    public static function modelMatrix(Vec3 $apex, Vec3 $direction, float $angle, float $length, int $segments = self::SEGMENTS): Mat4
    {
        $d = self::unit($direction);
        [$t1, $t2] = self::basis($d);
        $radius = self::baseRadius($angle, $length) / cos(M_PI / $segments);

        return new Mat4([
            $t1->x * $radius, $t1->y * $radius, $t1->z * $radius, 0.0,
            $t2->x * $radius, $t2->y * $radius, $t2->z * $radius, 0.0,
            $d->x * $length, $d->y * $length, $d->z * $length, 0.0,
            $apex->x, $apex->y, $apex->z, 1.0,
        ]);
    }

    /** Radius of the exact cone at its base. */
    public static function baseRadius(float $angle, float $length): float
    {
        return $length * tan(min(max($angle, 0.0), self::MAX_ANGLE));
    }

    /**
     * Smallest sphere around the cone, for culling: centre and radius.
     *
     * @return array{0: Vec3, 1: float}
     */
    public static function boundingSphere(Vec3 $apex, Vec3 $direction, float $angle, float $length): array
    {
        $d = self::unit($direction);
        $rb = self::baseRadius($angle, $length);
        if ($rb >= $length) {
            // Wide cone: the base disc is the widest part.
            return [$apex->add($d->mul($length)), $rb];
        }
        // Narrow cone: the sphere through the apex and the rim.
        $c = ($length * $length + $rb * $rb) / (2.0 * $length);

        return [$apex->add($d->mul($c)), $c];
    }

    /**
     * Whether $point lies inside the drawn cone, widened by $margin metres (the near
     * plane cuts away the front faces of a cone the camera stands at the edge of).
     */
    public static function contains(Vec3 $apex, Vec3 $direction, float $angle, float $length, Vec3 $point, float $margin = 0.0): bool
    {
        $d = self::unit($direction);
        $p = $point->sub($apex);
        $h = $p->dot($d);
        if ($h < -$margin || $h > $length + $margin) {
            return false;
        }
        $radial = $p->sub($d->mul($h))->length();

        return $radial <= max($h, 0.0) * tan(min(max($angle, 0.0), self::MAX_ANGLE)) + $margin;
    }

    /**
     * The register entry. Additive and unlit, so ShaderQuality::Unlit shows it as it is;
     * the php-glfw renderer draws no beams. It does not move by itself, and changes its
     * brightness only with its light, which is where a flicker would have to be limited.
     * Cost: null until measured.
     */
    public static function definition(): EffectDefinition
    {
        return new EffectDefinition(self::EFFECT, EffectTier::Low, null, EffectFallback::Same, EffectFallback::Off, ReducedMotion::Static, FlashClass::None);
    }

    /** Adds the entry when the register does not hold one yet (a game entry of the same name wins). */
    public static function register(): void
    {
        if (EffectRegistry::get(self::EFFECT) === null) {
            EffectRegistry::register(self::definition());
        }
    }

    private static function unit(Vec3 $v): Vec3
    {
        $len = $v->length();

        return $len > 1e-9 ? $v->div($len) : new Vec3(0.0, -1.0, 0.0);
    }

    /**
     * Two unit vectors perpendicular to $d with t1 x t2 = d.
     *
     * @return array{0: Vec3, 1: Vec3}
     */
    private static function basis(Vec3 $d): array
    {
        $helper = abs($d->y) < 0.9 ? new Vec3(0.0, 1.0, 0.0) : new Vec3(1.0, 0.0, 0.0);
        $t1 = $helper->cross($d)->normalize();
        $t2 = $d->cross($t1);

        return [$t1, $t2];
    }
}
