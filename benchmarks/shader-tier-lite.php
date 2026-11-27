<?php

declare(strict_types=1);

/**
 * GPU cost of the three shader tiers (Full / Lite / Unlit) on a synthetic lit city:
 * about a thousand instanced draws, ~65 000 instances, one sun with shadows, a few
 * point lights, fog. Reports the median GPU milliseconds of the opaque pass and of the
 * whole frame over N frames, and can write the image of each tier as a PNG.
 *
 * Usage (run it under the GPU lock, one adapter at a time):
 *   php benchmarks/shader-tier-lite.php [--backend=d3d12] [--adapter=Intel] [--tiers=full,lite,unlit]
 *       [--frames=300] [--width=1280] [--height=720] [--draws=1024] [--png=DIR] [--mrt=0|1] [--ao=off|medium]
 *       [--shadows=1|0]
 *
 * One JSON line per tier on stdout.
 */

require __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetShader;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShaderQuality;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\Runtime\PerfProfiler;

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2];
    }
}
$backend = $options['backend'] ?? 'auto';
$adapter = $options['adapter'] ?? '';
$tiers = explode(',', $options['tiers'] ?? 'full,lite,unlit');
$frames = (int) ($options['frames'] ?? 300);
$width = (int) ($options['width'] ?? 1280);
$height = (int) ($options['height'] ?? 720);
$draws = (int) ($options['draws'] ?? 1024);
$pngDir = $options['png'] ?? '';
$shadows = ($options['shadows'] ?? '1') === '1';
$mrt = $options['mrt'] ?? '';
$aoMode = $options['ao'] ?? 'off';
$per = (int) ($options['per'] ?? 64); // instances per draw (a perfect square)
$perSide = max(1, (int) round(sqrt($per)));
$per = $perSide * $perSide;
$prepass = ($options['prepass'] ?? '0') === '1';
$lights = (int) ($options['lights'] ?? 6); // point lights

putenv('PHPOLYGON_PROFILE=1');
if ($mrt !== '') {
    putenv('PHPOLYGON_VIO_MRT=' . $mrt);
}
if ($backend !== 'opengl' && $adapter !== '') {
    $adapterOption = ctype_digit($adapter) ? (int) $adapter : $adapter;
}
putenv('VIO_D3D_HEADLESS_HARDWARE=1');

$config = ['width' => $width, 'height' => $height, 'headless' => true, 'vsync' => false,
    'headless_hardware' => true, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-bench-shader-cache'];
if (isset($adapterOption)) {
    $config['adapter'] = $adapterOption;
}
$ctx = vio_create($backend, $config);
if ($ctx === false) {
    fwrite(STDERR, "vio_create failed\n");
    exit(2);
}
$gpuName = 'unknown';
foreach (vio_adapters()[vio_backend_name($ctx)] ?? [] as $a) {
    if ($adapter === '' || stripos((string) $a['name'], $adapter) !== false || (string) $a['index'] === $adapter) {
        $gpuName = $a['name'];
        break;
    }
}

// --- the city -------------------------------------------------------------------
MeshRegistry::clear();
MaterialRegistry::clear();
CubemapRegistry::clear();
MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
MeshRegistry::register('tall', BoxMesh::generate(1.0, 3.0, 1.0));
$materialIds = [];
for ($i = 0; $i < 24; $i++) {
    $id = 'city_' . $i;
    $materialIds[] = $id;
    $shade = 0.35 + 0.5 * (($i * 37) % 17) / 17.0;
    MaterialRegistry::register($id, new Material(
        albedo: new Color($shade, $shade * (0.8 + 0.2 * (($i * 7) % 5) / 5.0), $shade * (0.7 + 0.3 * (($i * 3) % 4) / 4.0)),
        roughness: 0.35 + 0.5 * (($i * 13) % 7) / 7.0,
        metallic: $i % 8 === 0 ? 0.8 : 0.0,
        emission: $i % 6 === 0 ? new Color(0.6, 0.45, 0.2) : new Color(0.0, 0.0, 0.0),
        normalPattern: $i % 3 === 0 ? 'bricks' : ($i % 3 === 1 ? 'bumps' : null),
    ));
}
MaterialRegistry::register('ground', new Material(albedo: new Color(0.3, 0.32, 0.28), roughness: 0.9, normalPattern: 'bumps'));
MeshRegistry::register('ground', BoxMesh::generate(500.0, 0.5, 500.0));

mt_srand(1234);
$side = (int) ceil(sqrt($draws));
$cell = 12.0;
$batches = [];
for ($d = 0; $d < $draws; $d++) {
    $cx = ($d % $side - $side / 2) * $cell;
    $cz = (intdiv($d, $side) - $side / 2) * $cell;
    $matrices = [];
    for ($k = 0; $k < $per; $k++) {
        $x = $cx + ($k % $perSide) * 1.4 * 8 / $perSide;
        $z = $cz + intdiv($k, $perSide) * 1.4 * 8 / $perSide;
        $h = 1.0 + mt_rand(0, 70) / 10.0;
        $matrices[] = Mat4::translation($x, $h * 0.5, $z)->multiply(Mat4::scaling(1.0, $h, 1.0));
    }
    $batches[] = new DrawMeshInstanced($d % 2 === 0 ? 'box' : 'tall', $materialIds[$d % 24], $matrices, isStatic: true, staticKey: 'city' . $d);
}

function buildList(array $batches, int $w, int $h, ?string $override, int $lights): RenderCommandList
{
    $list = new RenderCommandList();
    $list->add(new SetCamera(
        Mat4::lookAt(new Vec3(0.0, 22.0, -70.0), new Vec3(0.0, 2.0, 10.0), new Vec3(0, 1, 0)),
        Mat4::perspective(deg2rad(60.0), $w / $h, 0.3, 400.0),
    ));
    $list->add(new SetAmbientLight(new Color(0.45, 0.5, 0.6), 0.35));
    $list->add(new SetDirectionalLight(new Vec3(-0.45, -0.8, -0.35), new Color(1.0, 0.93, 0.82), 1.3));
    for ($i = 0; $i < $lights; $i++) {
        $list->add(new AddPointLight(new Vec3(-30.0 + $i * 12.0, 4.0, -35.0 + ($i % 2) * 20.0), new Color(1.0, 0.7, 0.4), 6.0, 24.0));
    }
    $list->add(new SetFog(new Color(0.62, 0.7, 0.8), 80.0, 320.0));
    if ($override !== null) {
        $list->add(new SetShader($override));
    }
    $list->add(new \PHPolygon\Rendering\Command\DrawMesh('ground', 'ground', Mat4::translation(0.0, -0.25, 0.0)));
    foreach ($batches as $batch) {
        $list->add($batch);
    }
    return $list;
}

function median(array $values): float
{
    sort($values);
    $n = count($values);
    return $n === 0 ? -1.0 : $values[intdiv($n, 2)];
}

foreach ($tiers as $tierName) {
    $tier = ShaderQuality::from($tierName);
    $renderer = new VioRenderer3D($ctx, $width, $height);
    $renderer->applySettings(new GraphicsSettings(
        shadowQuality: $shadows ? ShadowQuality::Medium : ShadowQuality::Off,
        ambientOcclusion: $aoMode === 'medium' ? ScreenSpaceAO::Medium : ScreenSpaceAO::Off,
        depthPrepass: $prepass,
        antiAliasing: AntiAliasing::Off,
        bloom: false,
        volumetricFog: false,
        cloudShadows: false,
        shaderQuality: $tier,
    ));
    // Unlit is a global override: the engine's ShaderManager puts a standing SetShader into the list.
    $override = $tier->shaderId();
    $list = buildList($batches, $width, $height, $override, $lights);

    $opaque = [];
    $frame = [];
    for ($i = 0; $i < $frames + 12; $i++) {
        vio_begin($ctx);
        $renderer->beginFrame();
        $renderer->render($list);
        $renderer->endFrame();
        vio_end($ctx);
        if ($i >= 12) {
            $passes = $renderer->gpuPassTimesMs();
            if (isset($passes['opaque'])) {
                $opaque[] = $passes['opaque'];
            }
            $frame[] = $renderer->gpuFrameTimeMs();
        }
    }
    $image = '';
    if ($pngDir !== '') {
        $image = $renderer->renderToImage($list, $width, $height, new Color(0.62, 0.7, 0.8, 1.0));
        $gd = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $o = ($y * $width + $x) * 4;
                imagesetpixel($gd, $x, $y, (ord($image[$o]) << 16) | (ord($image[$o + 1]) << 8) | ord($image[$o + 2]));
            }
        }
        if (!is_dir($pngDir)) {
            mkdir($pngDir, 0777, true);
        }
        imagepng($gd, $pngDir . '/' . $tierName . '.png');
    }
    echo json_encode([
        'tier' => $tierName,
        'backend' => vio_backend_name($ctx),
        'adapter' => $gpuName,
        'resolution' => $width . 'x' . $height,
        'draws' => $draws,
        'instances' => $draws * $per,
        'frames' => count($frame),
        'opaque_ms_median' => round(median($opaque), 3),
        'frame_ms_median' => round(median($frame), 3),
        'mrt' => $renderer->mrtActive(),
        'base_variant' => $renderer->frameBaseProgramVariant(),
        'date' => date('Y-m-d'),
    ]), "\n";
    unset($renderer);
}
