<?php

declare(strict_types=1);

namespace PHPolygon\Prefab;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\SurfaceText;
use PHPolygon\Component\Transform3D;
use PHPolygon\Geometry\QuadMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Text\SurfaceTextBaker;
use PHPolygon\Scene\EntityDeclaration;
use PHPolygon\Scene\SceneBuilder;

/**
 * Places text on a surface: {@see SurfaceText} on a quad facing $normal.
 *
 * The carrier (a wooden arm, a wall plate) is the caller's. The quad floats
 * $standoff in front of the point given, so pass a point ON the carrier's face.
 * The back is a second quad turned around the up axis, thickness behind the
 * front, and reads correctly from that side.
 *
 * The opaque pass culls no back faces, so a quad with nothing behind it shows
 * its text mirrored from behind. A plate that stands free needs $backAt or
 * $blankBack; on a carrier the carrier hides it.
 *
 * Each surface is one entity, shadowless and batched: equal texts of equal size
 * share an instanced draw.
 */
final class TextPlate
{
    /**
     * Clear of the carrier's depth out to 200 m (near plane 0.3, 24-bit depth;
     * 100 m at near 0.1), too small to see the gap.
     */
    public const float STANDOFF = 0.02;

    private function __construct() {}

    /**
     * @param Vec3      $center    middle of the face in the parent's space
     * @param Vec3      $normal    direction the text faces, towards the reader
     * @param ?float    $backAt    distance from the front face to the back face to
     *                             put the text on the back as well; null = front only
     * @param ?Vec3     $up        reading "up"; default world up (+Z for a flat sign)
     * @param ?EntityDeclaration $parent attach under this entity instead of at the root
     * @param bool      $blankBack the back shows the plate without text ($backAt or
     *                             0 behind the front), for a free-standing plate
     * @return list<EntityDeclaration> front, then back when asked for
     */
    public static function place(
        SceneBuilder $builder,
        string $name,
        Vec3 $center,
        Vec3 $normal,
        SurfaceText $text,
        ?float $backAt = null,
        ?Vec3 $up = null,
        float $standoff = self::STANDOFF,
        ?EntityDeclaration $parent = null,
        bool $blankBack = false,
    ): array {
        $normal = $normal->normalize();
        $rotation = self::facing($normal, $up);
        $declarations = [self::surface($builder, $name, $center->add($normal->mul($standoff)), $rotation, $text, $parent)];

        if ($backAt !== null || $blankBack) {
            $behind = $center->sub($normal->mul(($backAt ?? 0.0) + $standoff));
            $turned = Quaternion::fromAxisAngle($rotation->rotateVec3(new Vec3(0.0, 1.0, 0.0)), M_PI)->multiply($rotation);
            $declarations[] = self::surface($builder, $name . '_Back', $behind, $turned, clone $text, $parent, $blankBack);
        }

        return $declarations;
    }

    /**
     * The rotation that turns the quad (+Z front, +Y up) to face $normal with its
     * top towards $up.
     */
    public static function facing(Vec3 $normal, ?Vec3 $up = null): Quaternion
    {
        $z = $normal->normalize();
        $up ??= abs($z->y) > 0.999 ? new Vec3(0.0, 0.0, $z->y > 0.0 ? -1.0 : 1.0) : new Vec3(0.0, 1.0, 0.0);
        $x = $up->cross($z);
        if ($x->length() < 1e-6) {
            throw new \InvalidArgumentException('up must not be parallel to the normal');
        }
        $x = $x->normalize();
        $y = $z->cross($x);

        return Quaternion::fromRotationMatrix(new Mat4([
            $x->x, $x->y, $x->z, 0.0,
            $y->x, $y->y, $y->z, 0.0,
            $z->x, $z->y, $z->z, 0.0,
            0.0, 0.0, 0.0, 1.0,
        ]));
    }

    private static function surface(
        SceneBuilder $builder,
        string $name,
        Vec3 $position,
        Quaternion $rotation,
        SurfaceText $text,
        ?EntityDeclaration $parent,
        bool $blank = false,
    ): EntityDeclaration {
        $entity = $parent !== null ? $parent->child($name) : $builder->entity($name);
        $entity
            ->with(new Transform3D(position: $position, rotation: $rotation, scale: new Vec3($text->width, $text->height, 1.0)))
            ->with(new MeshRenderer(
                meshId: QuadMesh::unit(),
                materialId: SurfaceTextBaker::plainMaterial($text),
                castShadows: false,
                batchable: true,
            ));

        return $blank ? $entity : $entity->with($text);
    }
}
