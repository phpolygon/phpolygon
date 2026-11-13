<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;

/**
 * Cheap light contribution of emissive meshes (neon tubes, signs, lit panels): a point light at the centre of
 * each emissive draw, in the emission's colour, sized by the draw's bounding sphere. It is an approximation of an
 * area light (the light of a tube is that of a point at its middle, a little too concentrated close by), enough to
 * make a neon sign light the wet street and the wall beside it without the game placing a light per sign. Pure: it
 * returns commands; the caller adds them to the frame (and, with SetLightFalloff physical, the intensity is the
 * radiance at 1 m, so $gain is in those units).
 */
final class EmissiveLights
{
    private function __construct() {}

    /**
     * @param iterable<object> $commands the frame's draw commands (DrawMesh are read, others ignored)
     * @param float $gain         scales the light: intensity = emission luminance x gain x sqrt(sphere area)
     * @param float $reach        light radius = bounding radius x $reach, at least $minRadius
     * @param float $threshold    emission luminance below which a draw does not light anything
     * @param int   $max          at most this many lights, the strongest first (the Light Grid handles many, but not unboundedly)
     * @return list<AddPointLight>
     */
    public static function fromDraws(iterable $commands, float $gain = 1.0, float $reach = 6.0, float $threshold = 0.5, float $minRadius = 3.0, int $max = 64): array
    {
        $candidates = [];
        foreach ($commands as $cmd) {
            if (!$cmd instanceof DrawMesh) {
                continue;
            }
            $material = MaterialRegistry::get($cmd->materialId);
            if ($material === null) {
                continue;
            }
            $e = $material->emission;
            $luma = 0.2126 * $e->r + 0.7152 * $e->g + 0.0722 * $e->b;
            if ($luma < $threshold) {
                continue;
            }
            $sphere = MeshBounds::worldSphere($cmd->meshId, $cmd->modelMatrix);
            if ($sphere === null) {
                continue;
            }
            $size = max(0.05, $sphere['radius']);
            $strength = $luma * $gain * sqrt($size);
            $peak = max($e->r, $e->g, $e->b, 1e-6);
            $candidates[] = [$strength, new AddPointLight(
                new \PHPolygon\Math\Vec3($sphere['cx'], $sphere['cy'], $sphere['cz']),
                new Color($e->r / $peak, $e->g / $peak, $e->b / $peak),
                $strength,
                max($minRadius, $size * $reach),
            )];
        }
        usort($candidates, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_map(static fn (array $c): AddPointLight => $c[1], array_slice($candidates, 0, max(0, $max)));
    }
}
