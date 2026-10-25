<?php

declare(strict_types=1);

/**
 * Conforming surfaces: a path over a hill, a side path, a plaza with a border
 * band and a junction inlay, all built with ConformingSurfaceSet on a
 * procedural terrain and rendered headless (php-vio) into PNGs for review –
 * the path flat from the side (no slab edge, no gap), the plaza hem at eye
 * level, the junction, an overview from above – plus a SurfaceFit table.
 *
 *   php examples/conforming_surfaces.php [output-dir] [width] [height]
 *
 * Needs the vio extension with a 3D pipeline. Colours are neutral demo values.
 */

use PHPolygon\Geometry\ConformingSurfaceSet;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\RegionShape;
use PHPolygon\Geometry\SurfaceFit;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

require __DIR__ . '/../vendor/autoload.php';

$out = rtrim($argv[1] ?? __DIR__ . '/../build/conforming-surfaces', '\\/');
$w = (int) ($argv[2] ?? 1280);
$h = (int) ($argv[3] ?? 720);
if (!is_dir($out)) {
    mkdir($out, 0o775, true);
}

// ---------------------------------------------------------------- terrain
$ground = static function (float $x, float $z): float {
    $y = 0.25 * sin($x * 0.21) * cos($z * 0.17) + 0.08 * sin($x * 0.9 + $z * 0.7);
    $y += 4.0 * exp(-((($x - 4.0) ** 2) + (($z + 2.0) ** 2)) / 90.0); // the hill the path climbs
    return $y;
};
$terrain = static function (float $x0, float $z0, float $size, float $step, callable $ground): MeshData {
    $n = (int) round($size / $step);
    $v = [];
    $nr = [];
    $uv = [];
    $idx = [];
    for ($i = 0; $i <= $n; $i++) {
        for ($j = 0; $j <= $n; $j++) {
            $x = $x0 + $i * $step;
            $z = $z0 + $j * $step;
            $dx = $ground($x + 0.1, $z) - $ground($x - 0.1, $z);
            $dz = $ground($x, $z + 0.1) - $ground($x, $z - 0.1);
            $nn = (new Vec3(-$dx / 0.2, 1.0, -$dz / 0.2))->normalize();
            array_push($v, $x, $ground($x, $z), $z);
            array_push($nr, $nn->x, $nn->y, $nn->z);
            array_push($uv, $x * 0.25, $z * 0.25);
        }
    }
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            $a = $i * ($n + 1) + $j;
            $b = $a + $n + 1;
            array_push($idx, $a, $a + 1, $b, $b, $a + 1, $b + 1);
        }
    }
    return new MeshData($v, $nr, $uv, $idx);
};
// The render terrain is denser than the surfaces' grid: what you see is the
// ground the surfaces sample, not a coarser stand-in.
MeshRegistry::register('cs_ground', $terrain(-40.0, -40.0, 80.0, 0.25, $ground));

// ---------------------------------------------------------------- surfaces
$main = [[-32.0, 4.0], [-14.0, 1.0], [0.0, -1.0], [10.0, -3.0], [22.0, -8.0], [34.0, -10.0]];
$side = [[0.0, -1.0], [-2.0, 8.0], [-6.0, 16.0]];
$plaza = [-6.0, 20.0, 5.5];
$t0 = hrtime(true);
$set = (new ConformingSurfaceSet($ground, cell: 0.5, lift: 0.025, hemWidth: 0.35, hemSink: 0.05, uvScale: 0.5))
    ->add('paving', RegionShape::polyline($main, 1.5))
    ->add('paving', RegionShape::circle(0.0, -1.0, 2.6)) // junction widening
    ->add('plaza', RegionShape::circle($plaza[0], $plaza[1], $plaza[2]))
    ->add('gravel', RegionShape::polyline($side, 0.9))
    ->border('plaza', 0.45, 'plaza_border')
    ->inlay('marker', RegionShape::circle(0.0, -1.0, 0.75))
    ->inlay('marker_ring', RegionShape::circle(0.0, -1.0, 0.95), layer: 1);
$parts = $set->build();
$buildMs = (hrtime(true) - $t0) / 1e6;

$materials = [
    'paving' => ['#B9AE98', 'paving'],
    'paving.hem' => ['#8C8578', 'paving.hem'],
    'plaza' => ['#C9C2B4', 'plaza'],
    'plaza_border' => ['#7D7A73', 'plaza_border'],
    'plaza.hem' => ['#7D7A73', 'plaza.hem'],
    'gravel' => ['#A49880', 'gravel'],
    'gravel.hem' => ['#A49880', 'gravel.hem'],
    'marker' => ['#C46A2B', 'marker'],
    'marker_ring' => ['#7D7A73', 'marker_ring'],
];
// One mesh per material, as a game would do it.
$byMaterial = [];
foreach ($parts as $part => $mesh) {
    [$hex] = $materials[$part] ?? ['#FF00FF'];
    $byMaterial[$hex][] = $mesh;
}
$draws = [];
foreach ($byMaterial as $hex => $meshes) {
    $id = 'cs_' . strtolower(ltrim($hex, '#'));
    MeshRegistry::register($id, count($meshes) === 1 ? $meshes[0] : MeshData::merge(...$meshes));
    MaterialRegistry::register($id, new Material(albedo: Color::srgb($hex), roughness: 0.9, useEnvironmentMap: false));
    $draws[] = $id;
}
MaterialRegistry::register('cs_ground', new Material(albedo: Color::srgb('#4E6B2E'), roughness: 0.95, useEnvironmentMap: false));

// ---------------------------------------------------------------- measure
$g = $set->ground();
fwrite(STDERR, sprintf("built in %.0f ms, %d parts, %d draws\n", $buildMs, count($parts), count($draws)));
fwrite(STDERR, sprintf("%-14s %6s %9s %9s %9s\n", 'part', 'tris', 'lift max', 'lift min', 'edge max'));
foreach ($parts as $part => $mesh) {
    $fit = SurfaceFit::measure($mesh, $g);
    fwrite(STDERR, sprintf("%-14s %6d %8.1fcm %8.1fcm %8.1fcm\n", $part, $fit->triangles, $fit->liftMax * 100, $fit->liftMin * 100, $fit->openEdgeMax * 100));
}
$all = SurfaceFit::measure(MeshData::merge(...array_values($parts)), $g);
fwrite(STDERR, sprintf("%-14s %6d %8.1fcm %8.1fcm %8.1fcm  (open edges of the whole network: %d)\n", 'all together', $all->triangles, $all->liftMax * 100, $all->liftMin * 100, $all->openEdgeMax * 100, $all->openEdges));

// ---------------------------------------------------------------- shoot
$ctx = @vio_create('auto', ['width' => $w, 'height' => $h, 'headless' => true, 'headless_hardware' => true, 'vsync' => false]);
if ($ctx === false || !vio_supports_feature($ctx, VIO_FEATURE_3D_PIPELINE)) {
    fwrite(STDERR, "vio headless 3D unavailable\n");
    exit(1);
}
fwrite(STDERR, 'backend: ' . vio_backend_name($ctx) . "\n");

$shoot = static function (string $name, Vec3 $eye, Vec3 $target, float $fov) use ($ctx, $w, $h, $out, $draws): void {
    $renderer = new VioRenderer3D($ctx, $w, $h);
    $renderer->applySettings(new GraphicsSettings(
        shadowQuality: ShadowQuality::High,
        shadowDistance: 60.0,
        antiAliasing: AntiAliasing::Fxaa,
        ambientOcclusion: ScreenSpaceAO::Off,
        bloom: false,
    ));
    $list = new RenderCommandList();
    $list->add(new SetCamera(Mat4::lookAt($eye, $target, new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad($fov), $w / $h, 0.05, 1000.0)));
    $list->add(new SetAmbientLight(new Color(0.75, 0.82, 0.95), 0.4));
    $list->add(new SetDirectionalLight((new Vec3(-0.5, -0.65, -0.4))->normalize(), new Color(1.0, 0.95, 0.86), 1.6));
    $list->add(new SetFog(new Color(0.7, 0.79, 0.9), 120.0, 600.0));
    $list->add(new DrawMesh('cs_ground', 'cs_ground', Mat4::identity()));
    foreach ($draws as $id) {
        $list->add(new DrawMesh($id, $id, Mat4::identity()));
    }
    $rgba = $renderer->renderToImage($list, $w, $h, new Color(0.62, 0.74, 0.88));
    $img = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $o = ($y * $w + $x) * 4;
            imagesetpixel($img, $x, $y, (ord($rgba[$o]) << 16) | (ord($rgba[$o + 1]) << 8) | ord($rgba[$o + 2]));
        }
    }
    imagepng($img, "{$out}/{$name}.png");
    fwrite(STDERR, "ok {$out}/{$name}.png\n");
};

$at = static fn (float $x, float $z, float $up): Vec3 => new Vec3($x, $ground($x, $z) + $up, $z);
// The path over the hill, flat from the side: it follows the crest, no slab edge, no gap.
$shoot('path-hill-side', $at(8.0, 3.5, 0.9), $at(1.0, -1.0, 0.0), 60.0);
// Closer and lower: the path's edge against the grass, no slab, no gap.
$shoot('path-edge-close', $at(12.0, -6.6, 0.25), $at(9.0, -3.5, 0.0), 55.0);
// The plaza with its border and hem at eye level.
$shoot('plaza-hem-eye', $at(-15.0, 22.0, 1.6), $at(-6.0, 20.0, 0.0), 55.0);
// The junction: widening, inlay marker, side path.
$shoot('junction', $at(-6.0, -6.5, 1.7), $at(0.0, -1.0, 0.0), 60.0);
// Overview from above.
$shoot('network-from-above', new Vec3(-4.0, 34.0, -10.0), new Vec3(-2.0, 0.0, 6.0), 60.0);
