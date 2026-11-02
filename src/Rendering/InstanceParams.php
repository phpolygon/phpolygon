<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat4;

/**
 * Three free floats per instance of an instanced draw, in the unused m[0..2].w of its
 * matrix: no new vertex layout, no second buffer, and a batch that changes stays one
 * draw. Read as `v_instance_params` by snippets after
 * {@see ProcModeShaderRegistry::enableInstanceParams()}. A matrix carrying them is
 * only good for an instanced draw: a single draw takes {@see Command\DrawMesh::$params}.
 */
final class InstanceParams
{
    private function __construct() {}

    public static function matrix(Mat4 $matrix, float $a, float $b = 0.0, float $c = 0.0): Mat4
    {
        $values = $matrix->toArray();
        $values[3] = $a;
        $values[7] = $b;
        $values[11] = $c;

        return new Mat4($values);
    }

    /**
     * Write them into a flat matrix buffer ({@see Command\DrawMeshInstanced::flat()}).
     *
     * @param array<int, float> $flat
     */
    public static function write(array &$flat, int $instance, float $a, float $b = 0.0, float $c = 0.0): void
    {
        $offset = $instance * 16;
        $flat[$offset + 3] = $a;
        $flat[$offset + 7] = $b;
        $flat[$offset + 11] = $c;
    }
}
