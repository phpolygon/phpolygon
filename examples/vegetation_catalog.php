<?php

declare(strict_types=1);

/**
 * Vegetation catalogue: renders the plant family headless (php-vio) into PNGs
 * for review - every form, the LOD ladder with the impostor, the five growth
 * stages, the two-sided foliage seen from above against the sun (old
 * one-sided material beside it), an instanced field through
 * FoliageInstanceSystem, glowing accents at night and two wind frames.
 *
 *   php examples/vegetation_catalog.php [output-dir] [width] [height]
 *
 * Needs the vio extension with a 3D pipeline. Colours here are neutral demo
 * values; a game passes its own palette.
 */

use PHPolygon\Component\FoliageInstances;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\CylinderMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetWind;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\System\FoliageInstanceSystem;
use PHPolygon\Vegetation\GrowthStage;
use PHPolygon\Vegetation\PlantForm;
use PHPolygon\Vegetation\PlantGenerator;
use PHPolygon\Vegetation\PlantLibrary;
use PHPolygon\Vegetation\PlantLod;
use PHPolygon\Vegetation\PlantParts;
use PHPolygon\Vegetation\PlantPlacement;
use PHPolygon\Vegetation\PlantSpec;
use PHPolygon\Vegetation\PlantVariant;

require __DIR__ . '/../vendor/autoload.php';

$out = rtrim($argv[1] ?? __DIR__ . '/../build/vegetation-catalog', '\\/');
$w = (int) ($argv[2] ?? 1280);
$h = (int) ($argv[3] ?? 720);
if (!is_dir($out)) {
    mkdir($out, 0o775, true);
}

$ctx = @vio_create('auto', ['width' => $w, 'height' => $h, 'headless' => true, 'headless_hardware' => true, 'vsync' => false]);
if ($ctx === false || !vio_supports_feature($ctx, VIO_FEATURE_3D_PIPELINE)) {
    fwrite(STDERR, "vio headless 3D unavailable\n");
    exit(1);
}
fwrite(STDERR, 'backend: ' . vio_backend_name($ctx) . "\n");

// ---------------------------------------------------------------- materials
$mat = static function (string $id, Material $m): void {
    MaterialRegistry::register($id, $m);
};
$mat('bark', Material::bark(Color::srgb('#6B4A32')));
$mat('bark_dark', Material::bark(Color::srgb('#463729')));
$mat('bark_light', Material::bark(Color::srgb('#B9AE9C')));
$mat('palm_bark', Material::bark(Color::srgb('#7A5A3A')));
$mat('leaf', Material::foliage(Color::srgb('#5E9E3A')));
$mat('leaf_light', Material::foliage(Color::srgb('#7DB548')));
$mat('leaf_dark', Material::foliage(Color::srgb('#2E8B57')));
$mat('leaf_autumn', Material::foliage(Color::srgb('#C0662B')));
$mat('leaf_coast', Material::foliage(Color::srgb('#6E9C7A')));
$mat('needles', Material::foliage(Color::srgb('#2F5D3A'), flutter: 0.02));
$mat('frond', Material::foliage(Color::srgb('#4F9A3A'), flutter: 0.22));
$mat('hedge', Material::foliage(Color::srgb('#4C7F35'), flutter: 0.02));
$mat('fresh', Material::foliage(Color::srgb('#9BC75A')));
$mat('blossom', new Material(albedo: Color::srgb('#F2C230'), roughness: 0.7, useEnvironmentMap: false));
$mat('glow', new Material(albedo: Color::srgb('#E040C0'), roughness: 0.6, useEnvironmentMap: false));
$mat('coconut', Material::bark(Color::srgb('#5A3D22'), wind: true));
// The pre-family look: same green, lit one-sided (back faces unlit).
$mat('frond_one_sided', new Material(albedo: Color::srgb('#4F9A3A'), roughness: 0.8, useEnvironmentMap: false));
$mat('floor', new Material(albedo: Color::srgb('#7F8A6E'), roughness: 0.95, useEnvironmentMap: false));
$mat('ref', new Material(albedo: Color::srgb('#9AA3AD'), roughness: 0.6, useEnvironmentMap: false));
MeshRegistry::register('cat_floor', BoxMesh::generate(1200.0, 0.2, 1200.0));
MeshRegistry::register('cat_ref', CylinderMesh::generate(0.22, 1.8, 12));
MeshRegistry::register('cat_head', SphereMesh::generate(0.15, 8, 10));

// ---------------------------------------------------------------- helpers
$meshN = 0;
$put = static function (array &$draws, PlantParts $p, string $wood, string $leaf, ?string $accent, Vec3 $at, float $yaw = 0.0) use (&$meshN): void {
    $m = Mat4::translation($at->x, $at->y, $at->z)->multiply(Mat4::rotationY($yaw));
    foreach ([[$p->wood, $wood], [$p->leaf, $leaf], [$p->accent, $accent]] as [$mesh, $material]) {
        if ($material === null || $mesh->indices === []) {
            continue;
        }
        $id = 'cat_mesh_' . (++$meshN);
        MeshRegistry::register($id, $mesh);
        $draws[] = new DrawMesh($id, $material, $m);
    }
};
$figure = static function (array &$draws, Vec3 $at): void {
    $draws[] = new DrawMesh('cat_ref', 'ref', Mat4::translation($at->x, $at->y + 0.9, $at->z));
    $draws[] = new DrawMesh('cat_head', 'ref', Mat4::translation($at->x, $at->y + 1.95, $at->z));
};

/**
 * @param list<object> $draws
 * @param array{0: Vec3, 1: Vec3, 2: float} $cam eye, target, fov
 * @param list<array{0: int, 1: int, 2: string}> $labels
 */
$shoot = static function (string $name, array $draws, array $cam, array $labels = [], array $light = [], float $time = 0.0, ?SetWind $wind = null) use ($ctx, $w, $h, $out): string {
    $renderer = new VioRenderer3D($ctx, $w, $h);
    $renderer->applySettings(new GraphicsSettings(
        shadowQuality: ShadowQuality::High,
        shadowDistance: 120.0,
        antiAliasing: AntiAliasing::Fxaa,
        ambientOcclusion: ScreenSpaceAO::Off,
        bloom: ($light['bloom'] ?? false) === true,
    ));
    $renderer->setAnimationTime($time);
    [$eye, $target, $fov] = $cam;
    $list = new RenderCommandList();
    $list->add(new SetCamera(Mat4::lookAt($eye, $target, new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad($fov), $w / $h, 0.1, 2000.0)));
    $sun = $light['sun'] ?? new Vec3(-0.45, -0.75, -0.5);
    $list->add(new SetAmbientLight($light['ambientColor'] ?? new Color(0.75, 0.82, 0.95), $light['ambient'] ?? 0.35));
    $list->add(new SetDirectionalLight($sun->normalize(), $light['sunColor'] ?? new Color(1.0, 0.96, 0.9), $light['sunIntensity'] ?? 1.6));
    $list->add(new SetFog($light['fog'] ?? new Color(0.72, 0.8, 0.9), 400.0, 1600.0));
    $list->add($wind ?? new SetWind(new Vec3(1.0, 0.0, 0.3), 0.0));
    $list->add(new DrawMesh('cat_floor', 'floor', Mat4::translation(0.0, -0.1, 0.0)));
    foreach ($draws as $d) {
        $list->add($d);
    }
    $clear = $light['clear'] ?? new Color(0.62, 0.74, 0.88);
    $rgba = $renderer->renderToImage($list, $w, $h, $clear);
    $img = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $o = ($y * $w + $x) * 4;
            imagesetpixel($img, $x, $y, (ord($rgba[$o]) << 16) | (ord($rgba[$o + 1]) << 8) | ord($rgba[$o + 2]));
        }
    }
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    foreach ($labels as [$lx, $ly, $text]) {
        imagestring($img, 5, $lx + 1, $ly + 1, $text, $black);
        imagestring($img, 5, $lx, $ly, $text, $white);
    }
    $path = "{$out}/{$name}.png";
    imagepng($img, $path);
    fwrite(STDERR, "ok {$path}\n");
    return $path;
};

$project = static function (Vec3 $p, array $cam) use ($w, $h): array {
    [$eye, $target, $fov] = $cam;
    $vp = Mat4::perspective(deg2rad($fov), $w / $h, 0.1, 2000.0)->multiply(Mat4::lookAt($eye, $target, new Vec3(0.0, 1.0, 0.0)));
    $c = $vp->multiplyVec4(new \PHPolygon\Math\Vec4($p->x, $p->y, $p->z, 1.0));
    return [(int) (($c->x / $c->w * 0.5 + 0.5) * $w), (int) ((1.0 - ($c->y / $c->w * 0.5 + 0.5)) * $h)];
};

$species = [
    'round 9m' => [PlantSpec::tree(PlantForm::Round, 9.0, 3.2, 13), 'bark', 'leaf', 'blossom'],
    'umbrella 8m' => [PlantSpec::tree(PlantForm::Umbrella, 8.0, 4.2, 22), 'bark_dark', 'leaf_dark', 'glow'],
    'column 11m' => [PlantSpec::tree(PlantForm::Column, 11.0, 1.9, 55), 'bark_dark', 'leaf_light', 'blossom'],
    'weeping 8m' => [PlantSpec::tree(PlantForm::Weeping, 8.0, 3.4, 7), 'bark', 'leaf_coast', 'blossom'],
    'autumn 9m' => [PlantSpec::tree(PlantForm::Round, 9.0, 3.4, 33), 'bark_dark', 'leaf_autumn', 'blossom'],
    'conifer 10m' => [PlantSpec::tree(PlantForm::Conifer, 10.0, 2.4, 88), 'bark_dark', 'needles', 'coconut'],
    'palm 9m' => [PlantSpec::palm(9.0, 5), 'palm_bark', 'frond', 'coconut'],
    'bush 1.2m' => [PlantSpec::bush(1.2, 1.4, 11), 'bark', 'leaf', 'blossom'],
    'hedge 3m' => [PlantSpec::hedge(3.0, 1.1, 0.9, 33), 'bark', 'hedge', 'blossom'],
];

// ---------------------------------------------------------------- 1 forms
$draws = [];
$labels = [];
$cam = [new Vec3(32.0, 4.5, 34.0), new Vec3(32.0, 4.8, 0.0), 62.0];
$i = 0;
foreach ($species as $label => [$spec, $wood, $leaf]) {
    $at = new Vec3($i * 8.0, 0.0, 0.0);
    $p = PlantGenerator::generate($spec);
    $put($draws, $p, $wood, $leaf, null, $at);
    [$lx, $ly] = $project(new Vec3($at->x, -0.4, 0.0), $cam);
    $labels[] = [$lx - 40, $ly + 6, $label];
    $labels[] = [$lx - 40, $ly + 22, $p->triangleCount() . ' tris'];
    fwrite(STDERR, sprintf("%-12s wood %4d leaf %4d\n", $label, $p->wood->triangleCount(), $p->leaf->triangleCount()));
    $i++;
}
$figure($draws, new Vec3(-4.0, 0.0, 2.0));
$shoot('01-arten', $draws, $cam, $labels);

// ---------------------------------------------------------------- 2 LOD ladder
$draws = [];
$labels = [];
$cam = [new Vec3(15.0, 5.0, 30.0), new Vec3(15.0, 4.5, 0.0), 52.0];
foreach ([['round 9m', 0.0], ['palm 9m', 13.0]] as [$name, $z]) {
    [$spec, $wood, $leaf] = $species[$name];
    foreach (PlantLod::cases() as $k => $lod) {
        $at = new Vec3($k * 10.0, 0.0, -$z);
        $p = PlantGenerator::generate($spec, GrowthStage::Full, $lod);
        $put($draws, $p, $wood, $leaf, null, $at);
        if ($z === 0.0) {
            [$lx, $ly] = $project(new Vec3($at->x, -0.4, 0.0), $cam);
            $labels[] = [$lx - 40, $ly + 6, $lod->name];
        }
        [$lx, $ly] = $project(new Vec3($at->x, -0.4, -$z), $cam);
        $labels[] = [$lx - 40, $ly + ($z === 0.0 ? 22 : 6), $p->triangleCount() . ' tris'];
    }
}
$shoot('02-lod-impostor', $draws, $cam, $labels);

// ---------------------------------------------------------------- 3 growth stages
$draws = [];
$labels = [];
$cam = [new Vec3(20.0, 4.5, 30.0), new Vec3(20.0, 4.0, 0.0), 58.0];
foreach ([['round 9m', 0.0, 'fresh'], ['palm 9m', 14.0, 'frond']] as [$name, $z, $fresh]) {
    [$spec, $wood, $leaf, $accent] = $species[$name];
    foreach (GrowthStage::cases() as $k => $stage) {
        $at = new Vec3($k * 10.0, 0.0, -$z);
        $p = PlantGenerator::generate($spec, $stage);
        $put($draws, $p, $wood, $stage === GrowthStage::Sprouting ? $fresh : $leaf, $accent, $at);
        if ($z === 0.0) {
            [$lx, $ly] = $project(new Vec3($at->x, -0.4, 0.0), $cam);
            $labels[] = [$lx - 30, $ly + 6, $k . ' ' . $stage->name];
        }
    }
}
$figure($draws, new Vec3(-4.0, 0.0, 2.0));
$shoot('03-wachstum', $draws, $cam, $labels);

// ---------------------------------------------------------------- 4 seen from above, against the sun
$draws = [];
$labels = [];
$cam = [new Vec3(8.0, 22.0, 16.0), new Vec3(8.0, 5.0, -2.0), 55.0];
$palm = PlantGenerator::generate($species['palm 9m'][0]);
$tree = PlantGenerator::generate($species['round 9m'][0]);
// Imported fronds often carry downward normals: flip the generated ones to
// reproduce that, then draw them one-sided (left) and two-sided (right).
$n = $palm->leaf->normals;
foreach ($n as $k => $v) {
    $n[$k] = -$v;
}
$idx = $palm->leaf->indices;
for ($k = 0, $c = count($idx); $k + 2 < $c; $k += 3) {
    [$idx[$k + 1], $idx[$k + 2]] = [$idx[$k + 2], $idx[$k + 1]];
}
$flippedPalm = new PlantParts($palm->wood, new \PHPolygon\Geometry\MeshData($palm->leaf->vertices, $n, $palm->leaf->uvs, $idx), $palm->accent, $palm->height, $palm->crownCenter, $palm->crownRadius, $palm->trunkRadius, $palm->trunkHeight);
$put($draws, $flippedPalm, 'palm_bark', 'frond_one_sided', null, new Vec3(0.0, 0.0, 0.0));
$put($draws, $flippedPalm, 'palm_bark', 'frond', null, new Vec3(14.0, 0.0, 0.0));
$put($draws, $tree, 'bark', 'leaf', null, new Vec3(7.0, 0.0, -10.0));
[$lx, $ly] = $project(new Vec3(0.0, 0.0, 4.5), $cam);
$labels[] = [$lx - 110, $ly, 'down normals, one-sided (old)'];
[$lx, $ly] = $project(new Vec3(14.0, 0.0, 4.5), $cam);
$labels[] = [$lx - 110, $ly, 'down normals, twoSided foliage'];
$backlight = ['sun' => new Vec3(0.2, -0.45, 0.85), 'sunIntensity' => 1.8];
$shoot('04-von-oben-gegenlicht', $draws, $cam, $labels, $backlight);
$cam2 = [new Vec3(7.0, 1.6, 12.0), new Vec3(7.0, 7.0, -3.0), 70.0];
$shoot('05-von-unten', $draws, $cam2, [[20, 20, 'left: one-sided (old)   right: twoSided']], $backlight);

// ---------------------------------------------------------------- 6 instanced field
PlantLibrary::register(new PlantVariant('cat_round', $species['round 9m'][0], 'bark', 'leaf', 'blossom', ['leaf_light']));
PlantLibrary::register(new PlantVariant('cat_palm', $species['palm 9m'][0], 'palm_bark', 'frond', 'coconut'));
PlantLibrary::register(new PlantVariant('cat_bush', $species['bush 1.2m'][0], 'bark', 'leaf', 'blossom'));
$world = new World();
$avenue = new FoliageInstances('cat_round');
PlantPlacement::addTo($avenue, PlantPlacement::avenue([new Vec3(0.0, 0.0, 10.0), new Vec3(0.0, 0.0, -600.0)], 12.0, 7.0, seed: 3));
$palms = new FoliageInstances('cat_palm');
PlantPlacement::addTo($palms, PlantPlacement::cluster(new Vec3(-28.0, 0.0, -45.0), 10.0, 6, 4.0, 5));
PlantPlacement::addTo($palms, PlantPlacement::cluster(new Vec3(30.0, 0.0, -160.0), 14.0, 7, 4.0, 6));
$bushes = new FoliageInstances('cat_bush');
PlantPlacement::addTo($bushes, PlantPlacement::avenue([new Vec3(0.0, 0.0, 10.0), new Vec3(0.0, 0.0, -150.0)], 4.0, 4.2, seed: 9));
foreach ([$avenue, $palms, $bushes] as $c) {
    $world->createEntity()->attach($c);
}
$cam = [new Vec3(0.0, 2.2, 14.0), new Vec3(0.0, 3.0, -60.0), 70.0];
$list = new RenderCommandList();
$list->add(new SetCamera(Mat4::lookAt($cam[0], $cam[1], new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad($cam[2]), $w / $h, 0.1, 2000.0)));
$system = new FoliageInstanceSystem($list);
$t0 = hrtime(true);
$system->render($world);
$t1 = hrtime(true);
$system->render($world);
$t2 = hrtime(true);
$draws = [];
$tris = 0;
foreach ($list->getCommands() as $cmd) {
    if ($cmd instanceof DrawMeshInstanced) {
        $draws[] = $cmd;
        $tris += (MeshRegistry::get($cmd->meshId)?->triangleCount() ?? 0) * $cmd->instanceCount;
    }
}
$draws = array_slice($draws, 0, intdiv(count($draws), 2)); // the second render re-sent the same batches
$plants = $avenue->count() + $palms->count() + $bushes->count();
$info = sprintf('%d plants, %d instanced draws, %d tris, build %.2f ms, resend %.3f ms', $plants, count($draws), intdiv($tris, 2), ($t1 - $t0) / 1e6, ($t2 - $t1) / 1e6);
fwrite(STDERR, $info . "\n");
$shoot('06-instanzen-allee', $draws, $cam, [[20, 20, $info]]);

// ---------------------------------------------------------------- 7 glow at night
$glowVariant = PlantLibrary::register(new PlantVariant('cat_glow', $species['umbrella 8m'][0], 'bark_dark', 'leaf_dark', 'glow', accentGlow: new Color(2.4, 0.25, 1.9)));
$world = new World();
$glowing = new FoliageInstances('cat_glow');
$glowing->add(new Vec3(-6.0, 0.0, -8.0), 0.3, 1.0, GrowthStage::Bloom);
$glowing->add(new Vec3(6.0, 0.0, -10.0), 1.9, 1.0, GrowthStage::Bloom);
$glowing->add(new Vec3(0.0, 0.0, -22.0), 2.9, 1.1, GrowthStage::Full);
$glowing->glow = 1.0;
$world->createEntity()->attach($glowing);
$cam = [new Vec3(0.0, 2.0, 10.0), new Vec3(0.0, 5.0, -10.0), 65.0];
$list = new RenderCommandList();
$list->add(new SetCamera(Mat4::lookAt($cam[0], $cam[1], new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad($cam[2]), $w / $h, 0.1, 2000.0)));
(new FoliageInstanceSystem($list))->render($world);
$draws = array_values(array_filter($list->getCommands(), static fn ($c): bool => $c instanceof DrawMeshInstanced));
$night = ['sun' => new Vec3(0.3, -0.6, -0.4), 'sunColor' => new Color(0.55, 0.65, 1.0), 'sunIntensity' => 0.12, 'ambient' => 0.12, 'ambientColor' => new Color(0.3, 0.38, 0.6), 'clear' => new Color(0.02, 0.03, 0.07), 'fog' => new Color(0.02, 0.03, 0.07), 'bloom' => true];
$shoot('07-nacht-leuchtbluete', $draws, $cam, [[20, 20, 'night: accent glow 1.0 (front two in Bloom, back tree Full)']], $night);

// ---------------------------------------------------------------- 8 wind frames
$draws = [];
$cam = [new Vec3(0.0, 4.0, 16.0), new Vec3(0.0, 5.0, 0.0), 55.0];
$put($draws, $palm, 'palm_bark', 'frond', null, new Vec3(-5.0, 0.0, 0.0));
$put($draws, $tree, 'bark', 'leaf', null, new Vec3(5.0, 0.0, 0.0));
$strong = new SetWind(new Vec3(1.0, 0.0, 0.2), 2.0);
foreach ([0.0, 0.7, 1.4] as $k => $t) {
    $shoot("08-wind-{$k}", $draws, $cam, [[20, 20, sprintf('wind 2.0 (storm), t = %.1f s', $t)]], [], $t, $strong);
}
$shoot('08-wind-still', $draws, $cam, [[20, 20, 'wind 2.0, foliageMotion 0 (reduced motion off-switch)']], [], 0.7, new SetWind(new Vec3(1.0, 0.0, 0.2), 2.0, foliageMotion: 0.0));

vio_destroy($ctx);
