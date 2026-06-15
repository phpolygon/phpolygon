<?php

declare(strict_types=1);

namespace PHPolygon\Fieldtracing\Sdf;

use PHPolygon\Math\Vec3;

/**
 * Boolean / smooth combination of two SDFs, the PHP twin of the GLSL combine
 * operators (Quilez). Composites are themselves {@see SdfPrimitive}, so they
 * nest: a whole procedural world reduces to one composite tree, mirroring how
 * the engine composes geometry from primitives.
 *
 * Operators:
 *   union     = min(a, b)         intersect      = max(a, b)
 *   subtract  = max(a, -b)        (a with b carved out)
 *   smooth*   = the k-blended variants for soft transitions
 *
 * Build via the static factories; the constructor is private so an instance is
 * always a well-formed node.
 */
final readonly class SdfComposite implements SdfPrimitive
{
    private const UNION = 'union';
    private const INTERSECT = 'intersect';
    private const SUBTRACT = 'subtract';
    private const SMOOTH_UNION = 'smooth_union';
    private const SMOOTH_INTERSECT = 'smooth_intersect';
    private const SMOOTH_SUBTRACT = 'smooth_subtract';

    private function __construct(
        private SdfPrimitive $left,
        private SdfPrimitive $right,
        private string $op,
        private float $k,
    ) {}

    public static function union(SdfPrimitive $a, SdfPrimitive $b): self
    {
        return new self($a, $b, self::UNION, 0.0);
    }

    public static function intersect(SdfPrimitive $a, SdfPrimitive $b): self
    {
        return new self($a, $b, self::INTERSECT, 0.0);
    }

    /** $a with $b carved out of it. */
    public static function subtract(SdfPrimitive $a, SdfPrimitive $b): self
    {
        return new self($a, $b, self::SUBTRACT, 0.0);
    }

    public static function smoothUnion(SdfPrimitive $a, SdfPrimitive $b, float $k): self
    {
        return new self($a, $b, self::SMOOTH_UNION, max($k, 0.0));
    }

    public static function smoothIntersect(SdfPrimitive $a, SdfPrimitive $b, float $k): self
    {
        return new self($a, $b, self::SMOOTH_INTERSECT, max($k, 0.0));
    }

    /** $a with $b carved out, blended over radius $k. */
    public static function smoothSubtract(SdfPrimitive $a, SdfPrimitive $b, float $k): self
    {
        return new self($a, $b, self::SMOOTH_SUBTRACT, max($k, 0.0));
    }

    /** Fold any number of primitives into a left-associated union tree. */
    public static function unionAll(SdfPrimitive ...$prims): SdfPrimitive
    {
        if ($prims === []) {
            throw new \InvalidArgumentException('unionAll() requires at least one primitive.');
        }
        $acc = array_shift($prims);
        foreach ($prims as $p) {
            $acc = self::union($acc, $p);
        }
        return $acc;
    }

    public function distance(Vec3 $p): float
    {
        $a = $this->left->distance($p);
        $b = $this->right->distance($p);
        $k = $this->k;

        return match ($this->op) {
            self::UNION => min($a, $b),
            self::INTERSECT => max($a, $b),
            self::SUBTRACT => max($a, -$b),
            self::SMOOTH_UNION => $k <= 0.0 ? min($a, $b) : self::smoothUnionValue($a, $b, $k),
            self::SMOOTH_INTERSECT => $k <= 0.0 ? max($a, $b) : self::smoothIntersectValue($a, $b, $k),
            self::SMOOTH_SUBTRACT => $k <= 0.0 ? max($a, -$b) : self::smoothSubtractValue($a, $b, $k),
            default => min($a, $b),
        };
    }

    public function bounds(): ?array
    {
        $lb = $this->left->bounds();
        $rb = $this->right->bounds();

        return match ($this->op) {
            // Result is a subset of $left; carving never grows the extent.
            self::SUBTRACT, self::SMOOTH_SUBTRACT => $lb,
            self::INTERSECT, self::SMOOTH_INTERSECT => self::intersectBounds($lb, $rb),
            // union: enclose both, padded by the smooth radius.
            default => self::expand(self::unionBounds($lb, $rb), $this->k),
        };
    }

    private static function smoothUnionValue(float $a, float $b, float $k): float
    {
        $h = self::clamp01(0.5 + 0.5 * ($b - $a) / $k);
        return self::mix($b, $a, $h) - $k * $h * (1.0 - $h);
    }

    private static function smoothIntersectValue(float $a, float $b, float $k): float
    {
        $h = self::clamp01(0.5 - 0.5 * ($b - $a) / $k);
        return self::mix($b, $a, $h) + $k * $h * (1.0 - $h);
    }

    /** $a minus $b, smoothed (iq opSmoothSubtraction with d1=b, d2=a). */
    private static function smoothSubtractValue(float $a, float $b, float $k): float
    {
        $h = self::clamp01(0.5 - 0.5 * ($a + $b) / $k);
        return self::mix($a, -$b, $h) + $k * $h * (1.0 - $h);
    }

    private static function mix(float $x, float $y, float $h): float
    {
        return $x + ($y - $x) * $h;
    }

    private static function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }

    /**
     * @param array{0: Vec3, 1: Vec3}|null $a
     * @param array{0: Vec3, 1: Vec3}|null $b
     * @return array{0: Vec3, 1: Vec3}|null
     */
    private static function unionBounds(?array $a, ?array $b): ?array
    {
        if ($a === null || $b === null) {
            return null; // either child unbounded => union unbounded
        }
        return [
            new Vec3(min($a[0]->x, $b[0]->x), min($a[0]->y, $b[0]->y), min($a[0]->z, $b[0]->z)),
            new Vec3(max($a[1]->x, $b[1]->x), max($a[1]->y, $b[1]->y), max($a[1]->z, $b[1]->z)),
        ];
    }

    /**
     * @param array{0: Vec3, 1: Vec3}|null $a
     * @param array{0: Vec3, 1: Vec3}|null $b
     * @return array{0: Vec3, 1: Vec3}|null
     */
    private static function intersectBounds(?array $a, ?array $b): ?array
    {
        // Intersection is contained in each operand; an unbounded operand
        // contributes no constraint, so fall back to the other's extent.
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        return [
            new Vec3(max($a[0]->x, $b[0]->x), max($a[0]->y, $b[0]->y), max($a[0]->z, $b[0]->z)),
            new Vec3(min($a[1]->x, $b[1]->x), min($a[1]->y, $b[1]->y), min($a[1]->z, $b[1]->z)),
        ];
    }

    /**
     * @param array{0: Vec3, 1: Vec3}|null $b
     * @return array{0: Vec3, 1: Vec3}|null
     */
    private static function expand(?array $b, float $k): ?array
    {
        if ($b === null || $k <= 0.0) {
            return $b;
        }
        $pad = new Vec3($k, $k, $k);
        return [$b[0]->sub($pad), $b[1]->add($pad)];
    }
}
