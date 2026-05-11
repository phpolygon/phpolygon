<?php

/**
 * A coffee mug built from LatheMesh (body) + SweepMesh (handle).
 *
 * Run:  bin/preview-mesh examples/meshes/mug.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPolygon\Geometry\LatheMesh;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\SweepMesh;
use PHPolygon\Math\Vec2;
use PHPolygon\Math\Vec3;

// Body profile (Vec2 = radius, height). Trace the outside up, across
// the rim, down the inside, and across the inner floor back to the axis.
//
//   0.40  ┐ rim  ┌ 0.40
//         │      │
//   0.36  ┘      └─ inner wall
//                   ↓
//                   inner floor at y = 0.06
//   ──────●────────  base outer at y = 0
//         ↑
//        axis (x = 0)
$bodyProfile = [
    new Vec2(0.00, 0.00),  // base centre
    new Vec2(0.40, 0.00),  // base outer corner (sharp)
    new Vec2(0.40, 0.00),  // duplicate -> hard crease at the bottom edge
    new Vec2(0.40, 0.95),  // outer wall up
    new Vec2(0.40, 1.00),  // top outer rim
    new Vec2(0.36, 1.00),  // top inner rim (across)
    new Vec2(0.36, 0.95),  // inside wall down
    new Vec2(0.36, 0.06),  // inside floor edge
    new Vec2(0.00, 0.06),  // inside centre
];
$body = LatheMesh::generate($bodyProfile, segments: 48);

// Handle: a circular tube swept along an arc on the +X side of the mug.
// Path goes from the upper attachment point (~0.85 height) outward in
// +X then back in to the lower attachment (~0.25 height).
$handlePath = [
    new Vec3(0.40, 0.85, 0.0),
    new Vec3(0.55, 0.90, 0.0),
    new Vec3(0.68, 0.75, 0.0),
    new Vec3(0.72, 0.55, 0.0),
    new Vec3(0.68, 0.35, 0.0),
    new Vec3(0.55, 0.22, 0.0),
    new Vec3(0.40, 0.25, 0.0),
];
$handle = SweepMesh::tube(radius: 0.045, sides: 12, path: $handlePath, capEnds: true);

return MeshData::merge($body, $handle);
