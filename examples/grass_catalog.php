<?php

declare(strict_types=1);

/**
 * Grass catalogue: renders grass blades headless (php-vio) into PNGs for
 * review - a meadow with a path and a paved circle, a slope, eye-level close
 * ups, an overview of the fade into the distance, wind 0 / 1 / 2, night, a
 * figure bending the blades and the light through the blades against the sun,
 * each in several quality levels - and measures draws, tufts, triangles, CPU and
 * GPU milliseconds per level.
 *
 *   php examples/grass_catalog.php [output-dir] [width] [height] [levels]
 *
 * `levels` is a comma list of GrassQuality values (default all but off). Needs
 * the vio extension with a 3D pipeline. Colours are neutral demo values; a game
 * passes its own palette and ground.
 */

use PHPolygon\Component\GrassBender;
use PHPolygon\Component\GrassField;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\CylinderMesh;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetWind;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\GrassQuality;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\System\GrassSystem;
use PHPolygon\Vegetation\GrassMask;

require __DIR__ . '/../vendor/autoload.php';

$out = rtrim($argv[1] ?? __DIR__ . '/../build/grass-catalog', '\\/');
$w = (int) ($argv[2] ?? 1280);
$h = (int) ($argv[3] ?? 720);
$levels = array_map(
    static fn(string $v): GrassQuality => GrassQuality::from(trim($v)),
    explode(',', $argv[4] ?? 'low,medium,high,ultra,realistic'),
);
if (!is_dir($out)) {
    mkdir($out, 0o775, true);
}

$ctx = @vio_create('auto', ['width' => $w, 'height' => $h, 'headless' => true, 'headless_hardware' => true, 'vsync' => false]);
if ($ctx === false || !vio_supports_feature($ctx, VIO_FEATURE_3D_PIPELINE)) {
    fwrite(STDERR, "vio headless 3D unavailable\n");
    exit(1);
}
fwrite(STDERR, 'backend: ' . vio_backend_name($ctx) . "\n");

// ---------------------------------------------------------------- ground
$ground = static function (float $x, float $z): float {
    $y = 0.35 * sin($x * 0.07) * cos($z * 0.05);
    $y += 7.0 * exp(-((($x - 32.0) ** 2) + (($z + 28.0) ** 2)) / 500.0); // a hill
    return $y;
};
$path = [[-60.0, 18.0], [-20.0, 6.0], [0.0, 0.0], [14.0, -8.0], [26.0, -30.0], [30.0, -60.0], [36.0, -90.0]];
$plaza = [-14.0, -22.0, 6.0];

$terrain = static function (float $size, float $step, callable $ground): MeshData {
    $n = (int) round($size / $step);
    $v = [];
    $nr = [];
    $uv = [];
    $idx = [];
    $half = $size / 2.0;
    for ($i = 0; $i <= $n; $i++) {
        for ($j = 0; $j <= $n; $j++) {
            $x = -$half + $i * $step;
            $z = -$half + $j * $step;
            $y = $ground($x, $z);
            $dx = $ground($x + 0.2, $z) - $ground($x - 0.2, $z);
            $dz = $ground($x, $z + 0.2) - $ground($x, $z - 0.2);
            $nn = new Vec3(-$dx / 0.4, 1.0, -$dz / 0.4);
            $nn = $nn->normalize();
            array_push($v, $x, $y, $z);
            array_push($nr, $nn->x, $nn->y, $nn->z);
            array_push($uv, $x * 0.1, $z * 0.1);
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
$strip = static function (array $pts, float $half, callable $ground): MeshData {
    $v = [];
    $nr = [];
    $uv = [];
    $idx = [];
    $dense = [];
    for ($i = 0; $i + 1 < count($pts); $i++) {
        [$ax, $az] = $pts[$i];
        [$bx, $bz] = $pts[$i + 1];
        $len = hypot($bx - $ax, $bz - $az);
        $steps = max(1, (int) ceil($len / 0.75));
        for ($s = 0; $s < $steps; $s++) {
            $t = $s / $steps;
            $dense[] = [$ax + ($bx - $ax) * $t, $az + ($bz - $az) * $t, ($bx - $ax) / $len, ($bz - $az) / $len];
        }
    }
    $last = $pts[count($pts) - 1];
    $prev = $dense[count($dense) - 1];
    $dense[] = [$last[0], $last[1], $prev[2], $prev[3]];
    foreach ($dense as $k => [$x, $z, $dx, $dz]) {
        foreach ([-1.0, 1.0] as $side) {
            $px = $x - $dz * $half * $side;
            $pz = $z + $dx * $half * $side;
            array_push($v, $px, $ground($px, $pz) + 0.04, $pz);
            array_push($nr, 0.0, 1.0, 0.0);
            array_push($uv, $side, $k * 0.5);
        }
        if ($k > 0) {
            $a = ($k - 1) * 2;
            array_push($idx, $a, $a + 2, $a + 1, $a + 1, $a + 2, $a + 3);
        }
    }
    return new MeshData($v, $nr, $uv, $idx);
};
$disc = static function (float $cx, float $cz, float $r, callable $ground): MeshData {
    $v = [$cx, $ground($cx, $cz) + 0.05, $cz];
    $nr = [0.0, 1.0, 0.0];
    $uv = [0.5, 0.5];
    $idx = [];
    $seg = 48;
    for ($i = 0; $i <= $seg; $i++) {
        $a = $i / $seg * 2.0 * M_PI;
        $x = $cx + cos($a) * $r;
        $z = $cz + sin($a) * $r;
        array_push($v, $x, $ground($x, $z) + 0.05, $z);
        array_push($nr, 0.0, 1.0, 0.0);
        array_push($uv, 0.5 + cos($a) * 0.5, 0.5 + sin($a) * 0.5);
        if ($i > 0) {
            array_push($idx, 0, $i + 1, $i);
        }
    }
    return new MeshData($v, $nr, $uv, $idx);
};

MeshRegistry::register('gc_ground', $terrain(240.0, 1.0, $ground));
MeshRegistry::register('gc_path', $strip($path, 1.3, $ground));
MeshRegistry::register('gc_plaza', $disc($plaza[0], $plaza[1], $plaza[2], $ground));
MeshRegistry::register('gc_body', CylinderMesh::generate(0.22, 1.8, 12));
MeshRegistry::register('gc_head', SphereMesh::generate(0.15, 8, 10));
MaterialRegistry::register('gc_ground', new Material(albedo: Color::srgb('#36531C'), roughness: 0.95, useEnvironmentMap: false));
MaterialRegistry::register('gc_path', new Material(albedo: Color::srgb('#9C8A6A'), roughness: 0.95, useEnvironmentMap: false));
MaterialRegistry::register('gc_plaza', new Material(albedo: Color::srgb('#8F8C86'), roughness: 0.9, useEnvironmentMap: false));
MaterialRegistry::register('gc_ref', new Material(albedo: Color::srgb('#9AA3AD'), roughness: 0.6, useEnvironmentMap: false));

// ---------------------------------------------------------------- field
$mask = (new GrassMask())
    ->excludePath($path, 1.35, 0.9)
    ->excludeCircle($plaza[0], $plaza[1], $plaza[2] + 0.1, 0.8);
$field = new GrassField(-120.0, -120.0, 120.0, 120.0, $ground, $mask, Color::srgb('#2E4A17'), Color::srgb('#8AA846'), seed: 7);
$world = new World();
$world->createEntity()->attach($field);
$figureAt = new Vec3(7.0, $ground(7.0, 3.0), 3.0);
$world->createEntity()->attach(new Transform3D($figureAt))->attach(new GrassBender(0.9));

// ---------------------------------------------------------------- shoot
/**
 * @param array{0: Vec3, 1: Vec3, 2: float} $cam
 * @param array<string, mixed> $opt
 * @return array{draws: int, tufts: int, tris: int, cpu: float, gen: float}
 */
$shoot = static function (string $name, GrassQuality $q, array $cam, array $opt = []) use ($ctx, $w, $h, $out, $world, $figureAt): array {
    $renderer = new VioRenderer3D($ctx, $w, $h);
    $renderer->applySettings(new GraphicsSettings(
        shadowQuality: ShadowQuality::High,
        shadowDistance: 90.0,
        antiAliasing: AntiAliasing::Fxaa,
        ambientOcclusion: ScreenSpaceAO::Off,
        bloom: false,
    ));
    $renderer->setAnimationTime((float) ($opt['time'] ?? 1.3));
    [$eye, $target, $fov] = $cam;
    $list = new RenderCommandList();
    $list->add(new SetCamera(Mat4::lookAt($eye, $target, new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad($fov), $w / $h, 0.05, 2000.0)));
    $night = ($opt['night'] ?? false) === true;
    $sun = $opt['sun'] ?? new Vec3(-0.45, -0.7, -0.55);
    $list->add(new SetAmbientLight($night ? new Color(0.35, 0.45, 0.75) : new Color(0.75, 0.82, 0.95), $night ? 0.12 : 0.38));
    $list->add(new SetDirectionalLight($sun->normalize(), $night ? new Color(0.55, 0.65, 1.0) : new Color(1.0, 0.95, 0.86), $night ? 0.25 : 1.7));
    $list->add(new SetFog($night ? new Color(0.04, 0.06, 0.12) : new Color(0.7, 0.79, 0.9), 150.0, 900.0));
    $list->add(new SetWind(new Vec3(1.0, 0.0, 0.35), (float) ($opt['wind'] ?? 0.6)));
    $system = new GrassSystem($list, $q);
    $system->prewarm($world, $eye->x, $eye->z);
    $t0 = hrtime(true);
    $system->render($world);
    $cpuRebuild = (hrtime(true) - $t0) / 1e6;
    $stats = ['draws' => $system->lastDrawCount, 'tufts' => $system->lastInstanceCount, 'tris' => $system->lastTriangleCount, 'cpu' => $cpuRebuild, 'gen' => $system->lastGenerateMs];
    $list->add(new DrawMesh('gc_ground', 'gc_ground', Mat4::identity()));
    $list->add(new DrawMesh('gc_path', 'gc_path', Mat4::identity()));
    $list->add(new DrawMesh('gc_plaza', 'gc_plaza', Mat4::identity()));
    $list->add(new DrawMesh('gc_body', 'gc_ref', Mat4::translation($figureAt->x, $figureAt->y + 0.9, $figureAt->z)));
    $list->add(new DrawMesh('gc_head', 'gc_ref', Mat4::translation($figureAt->x, $figureAt->y + 1.95, $figureAt->z)));
    $clear = $night ? new Color(0.03, 0.05, 0.1) : new Color(0.62, 0.74, 0.88);
    $rgba = $renderer->renderToImage($list, $w, $h, $clear);
    $img = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $o = ($y * $w + $x) * 4;
            imagesetpixel($img, $x, $y, (ord($rgba[$o]) << 16) | (ord($rgba[$o + 1]) << 8) | ord($rgba[$o + 2]));
        }
    }
    $label = sprintf('%s  %s  %d draws  %d tufts  %.2fM tris', $name, $q->label(), $stats['draws'], $stats['tufts'], $stats['tris'] / 1e6);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagestring($img, 5, 11, 11, $label, $black);
    imagestring($img, 5, 10, 10, $label, $white);
    imagepng($img, "{$out}/{$name}-{$q->value}.png");
    fwrite(STDERR, "ok {$out}/{$name}-{$q->value}.png  {$label}\n");
    return $stats;
};

// ---------------------------------------------------------------- measure
// `measure` as 5th argument (at the given width x height): steady frames at eye level with and without
// blades - GPU ms (vio timestamps, median), CPU ms of GrassSystem::render() at
// rest and while the camera walks 0.1 m per frame (it rebuilds after 1.5 m).
if (($argv[5] ?? '') === 'measure') {
    $measure = static function (?GrassQuality $q, int $mw, int $mh) use ($ctx, $world, $ground): array {
        $renderer = new VioRenderer3D($ctx, $mw, $mh);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::High,
            shadowDistance: 90.0,
            antiAliasing: AntiAliasing::Fxaa,
            ambientOcclusion: ScreenSpaceAO::Medium,
            bloom: false,
        ));
        $sysList = new RenderCommandList();
        $system = $q !== null ? new GrassSystem($sysList, $q) : null;
        $gpu = [];
        $cpuRest = [];
        $cpuWalk = [];
        $stats = ['tufts' => 0, 'tris' => 0, 'draws' => 0];
        for ($f = 0; $f < 150; $f++) {
            $walk = $f >= 90;
            $ex = -6.0 + ($walk ? ($f - 90) * 0.1 : 0.0);
            $ez = 6.0 - ($walk ? ($f - 90) * 0.1 : 0.0);
            $eye = new Vec3($ex, $ground($ex, $ez) + 1.7, $ez);
            $camera = new SetCamera(
                Mat4::lookAt($eye, new Vec3($ex + 18.0, $ground($ex + 18.0, $ez - 12.0) + 0.6, $ez - 12.0), new Vec3(0.0, 1.0, 0.0)),
                Mat4::perspective(deg2rad(65.0), $mw / $mh, 0.05, 2000.0),
            );
            $list = new RenderCommandList();
            $list->add($camera);
            $list->add(new SetAmbientLight(new Color(0.75, 0.82, 0.95), 0.38));
            $list->add(new SetDirectionalLight((new Vec3(-0.45, -0.7, -0.55))->normalize(), new Color(1.0, 0.95, 0.86), 1.7));
            $list->add(new SetWind(new Vec3(1.0, 0.0, 0.35), 0.6));
            if ($system !== null) {
                if ($f === 0) {
                    $system->prewarm($world, $ex, $ez, 130.0);
                }
                $sysList->clear();
                $sysList->add($camera);
                $t0 = hrtime(true);
                $system->render($world);
                $ms = (hrtime(true) - $t0) / 1e6;
                if ($f >= 30) {
                    if ($walk) {
                        $cpuWalk[] = $ms;
                    } else {
                        $cpuRest[] = $ms;
                    }
                }
                foreach (array_slice($sysList->getCommands(), 1) as $c) {
                    $list->add($c);
                }
                $stats = ['tufts' => $system->lastInstanceCount, 'tris' => $system->lastTriangleCount, 'draws' => $system->lastDrawCount];
            }
            $list->add(new DrawMesh('gc_ground', 'gc_ground', Mat4::identity()));
            $list->add(new DrawMesh('gc_path', 'gc_path', Mat4::identity()));
            vio_begin($ctx);
            $renderer->setAnimationTime($f / 60.0);
            $renderer->beginFrame();
            $renderer->render($list);
            $renderer->endFrame();
            vio_end($ctx);
            if ($f >= 30 && !$walk && $renderer->gpuFrameTimeMs() > 0.0) {
                $gpu[] = $renderer->gpuFrameTimeMs();
            }
        }
        $median = static function (array $v): float {
            if ($v === []) {
                return -1.0;
            }
            sort($v);
            return (float) $v[intdiv(count($v), 2)];
        };
        return $stats + ['gpu' => $median($gpu), 'rest' => $median($cpuRest), 'walk' => $median($cpuWalk), 'walkMax' => $cpuWalk === [] ? 0.0 : max($cpuWalk)];
    };
    foreach ([[$w, $h]] as [$mw, $mh]) { // the headless context renders at its creation size
        $base = $measure(null, $mw, $mh);
        fwrite(STDERR, sprintf("\n%dx%d without grass: GPU %.2f ms\n", $mw, $mh, $base['gpu']));
        foreach ($levels as $q) {
            $m = $measure($q, $mw, $mh);
            fwrite(STDERR, sprintf(
                "%-10s draws %2d  tufts %6d  tris %6.2fM  GPU %6.2f ms (+%5.2f)  CPU rest %.3f ms  walk median %.3f / max %.2f ms\n",
                $q->value, $m['draws'], $m['tufts'], $m['tris'] / 1e6, $m['gpu'], $m['gpu'] - $base['gpu'], $m['rest'], $m['walk'], $m['walkMax'],
            ));
        }
    }
    exit(0);
}

$views = [
    // eye level beside the path, looking along it
    'a-augenhoehe' => [new Vec3(-6.0, $ground(-6.0, 6.0) + 1.7, 6.0), new Vec3(12.0, $ground(12.0, -6.0) + 0.6, -6.0), 65.0],
    // close to the ground, single blades
    'b-nah' => [new Vec3(1.2, $ground(1.2, 3.0) + 0.55, 3.0), new Vec3(3.5, $ground(3.5, -1.0) + 0.15, -1.0), 60.0],
    // overview: the fade into the distance
    'c-uebersicht' => [new Vec3(-30.0, 9.0, 30.0), new Vec3(10.0, 0.0, -20.0), 60.0],
    // the hill
    'd-hang' => [new Vec3(14.0, $ground(14.0, -8.0) + 1.7, -8.0), new Vec3(32.0, 6.0, -28.0), 60.0],
    // against the sun (sun low in front)
    'e-gegenlicht' => [new Vec3(-4.0, $ground(-4.0, 10.0) + 1.2, 10.0), new Vec3(-2.0, 0.4, -6.0), 60.0],
];
$results = [];
foreach ($levels as $q) {
    foreach ($views as $name => $cam) {
        $opt = $name === 'e-gegenlicht' ? ['sun' => new Vec3(0.1, -0.25, 1.0)] : [];
        $results[$q->value][$name] = $shoot($name, $q, $cam, $opt);
    }
}
// Wind 0 / 1 / 2 (medium), the figure bending the blades, night.
$side = [new Vec3(2.5, $ground(2.5, 8.0) + 1.0, 8.0), new Vec3(8.0, $ground(8.0, 2.0) + 0.3, 2.0), 55.0];
foreach ([0.0, 1.0, 2.0] as $k => $wind) {
    $shoot("f-wind{$k}", GrassQuality::Medium, $side, ['wind' => $wind, 'time' => 2.1]);
}
$figureView = [new Vec3(4.0, $figureAt->y + 3.2, 6.5), new Vec3($figureAt->x, $figureAt->y, $figureAt->z), 55.0];
$shoot('h-figur', GrassQuality::Medium, $figureView);
$shoot('h-figur', GrassQuality::Realistic, $figureView);
$shoot('g-nacht', GrassQuality::Medium, $views['a-augenhoehe'], ['night' => true]);
$shoot('g-nacht', GrassQuality::Realistic, $views['a-augenhoehe'], ['night' => true]);

fwrite(STDERR, "\nrebuild cost (first frame of a view, cells prewarmed):\n");
foreach ($results as $level => $byView) {
    foreach ($byView as $view => $s) {
        fwrite(STDERR, sprintf("%-10s %-14s draws %2d  tufts %6d  tris %7.2fM  cpu %6.2f ms\n", $level, $view, $s['draws'], $s['tufts'], $s['tris'] / 1e6, $s['cpu']));
    }
}
