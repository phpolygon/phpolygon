<?php

declare(strict_types=1);

/**
 * Fieldtracing — VOLUME-fed SDF trace demo (Tier-A path).
 *
 * The full pipeline, end to end:
 *   1. build an SDF scene in PHP (PHPolygon\Fieldtracing\Sdf),
 *   2. bake it to an SdfVolume on the CPU (SdfVolumeBaker),
 *   3. pack it to RGBA8 voxels (SdfVolume::toRgba8()),
 *   4. upload it to the GPU as a 3D texture (vio_texture_3d()),
 *   5. sphere-trace it in a fragment shader that samples the sampler3D.
 *
 * Unlike examples/fieldtracing_sdf.php (which marches an analytic SDF baked into
 * the shader), this marches the *baked volume* — so it works for any geometry,
 * including MeshData that doesn't come from analytic primitives.
 *
 * Requires a php-vio build that exposes vio_texture_3d() + VIO_FEATURE_TEXTURE_3D
 * (OpenGL / D3D11 / D3D12 / Vulkan). If the loaded extension predates that, the
 * demo prints what to rebuild and exits cleanly.
 *
 * Usage:
 *   php examples/fieldtracing_volume.php
 *   php examples/fieldtracing_volume.php --screenshot=out.png --res=64 --time=1.5
 */

require __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Fieldtracing\Bake\SdfVolumeBaker;
use PHPolygon\Fieldtracing\Sdf\BoxSdf;
use PHPolygon\Fieldtracing\Sdf\SdfComposite;
use PHPolygon\Fieldtracing\Sdf\SphereSdf;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\BackendConventions;

if (!extension_loaded('vio')) {
    fwrite(STDERR, "ext-vio is not loaded; this demo needs the vio backend.\n");
    exit(1);
}

$opt       = getopt('', ['screenshot::', 'width::', 'height::', 'frames::', 'mode::', 'time::', 'res::', 'backend::']);
$reqBackend = $opt['backend'] ?? 'auto';
$shotPath  = $opt['screenshot'] ?? null;
$headless  = $shotPath !== null;
$width     = (int)($opt['width']  ?? 1280);
$height    = (int)($opt['height'] ?? 720);
$frames    = (int)($opt['frames'] ?? ($headless ? 1 : 0));
$mode      = max(0, min(3, (int)($opt['mode'] ?? 3)));
$res       = (int)($opt['res'] ?? 56);
$fixedTime = isset($opt['time']) ? (float)$opt['time'] : null;
$range     = 4.0;  // must match the u_vol_range decode in the shader

$shaderDir = __DIR__ . '/../resources/shaders/source/vio/';

// ---- 1-3. Build + bake + pack the SDF volume (CPU, GPU-free) ----------------
$blob = SdfComposite::smoothUnion(
    SdfComposite::smoothUnion(
        new SphereSdf(1.0, new Vec3(-1.1, 1.0, 0.0)),
        new SphereSdf(0.8, new Vec3( 1.1, 1.0, 0.0)),
        0.6
    ),
    new BoxSdf(new Vec3(1.8, 1.4, 1.8), new Vec3(0.0, 0.7, -1.8)),
    0.4
);

$t0  = microtime(true);
$vol = SdfVolumeBaker::bakeAuto($blob, $res, 0.8);
$rgba = $vol->toRgba8($range);
fwrite(STDERR, sprintf(
    "[volume] baked %dx%dx%d (%d voxels, %.1f KB) in %.1f ms\n",
    $vol->nx, $vol->ny, $vol->nz, $vol->sampleCount(), strlen($rgba) / 1024.0,
    (microtime(true) - $t0) * 1000.0
));

// ---- Context ----------------------------------------------------------------
$ctx = vio_create($reqBackend, [
    'width'    => $width,
    'height'   => $height,
    'title'    => 'PHPolygon — Fieldtracing (volume)',
    'headless' => $headless,
    'visible'  => !$headless,
]);
if ($ctx === false) {
    fwrite(STDERR, "vio_create failed.\n");
    exit(1);
}

$backend = vio_backend_name($ctx);

// ---- Capability gate: needs the 3D-texture API ------------------------------
$has3dApi = function_exists('vio_texture_3d') && defined('VIO_FEATURE_TEXTURE_3D');
$has3dCap = $has3dApi && vio_supports_feature($ctx, VIO_FEATURE_TEXTURE_3D);
if (!$has3dCap) {
    fwrite(STDERR,
        "\n[volume] Backend '{$backend}' has no vio_texture_3d support in the loaded extension.\n" .
        "         The CPU bake above succeeded — only the GPU upload needs a rebuilt php-vio\n" .
        "         (vio_texture_3d + VIO_FEATURE_TEXTURE_3D for opengl/d3d11/d3d12/vulkan).\n" .
        "         Rebuild php-vio, then re-run; the analytic demo (examples/fieldtracing_sdf.php)\n" .
        "         runs today without any rebuild.\n");
    vio_destroy($ctx);
    exit(0);
}

$format = BackendConventions::forBackend($backend)->shaderSourceFormat();
fwrite(STDERR, "[volume] backend={$backend} shaderFormat={$format} mode={$mode}\n");

// ---- Fullscreen quad (shared vertex shader with the analytic demo) ----------
$quad = vio_mesh($ctx, [
    'vertices' => [
        -1, -1, 0,  0, 0,
         1, -1, 0,  1, 0,
         1,  1, 0,  1, 1,
        -1,  1, 0,  0, 1,
    ],
    'indices' => [0, 1, 2, 0, 2, 3],
    'layout'  => [VIO_FLOAT3, VIO_FLOAT2],
]);

$vertSrc = file_get_contents($shaderDir . 'fieldtrace.vert.glsl');
$fragSrc = file_get_contents($shaderDir . 'fieldtrace_volume.frag.glsl');
$shader  = vio_shader($ctx, ['vertex' => $vertSrc, 'fragment' => $fragSrc, 'format' => $format]);
if ($shader === false) {
    fwrite(STDERR, "volume shader compile failed (format={$format}).\n");
    exit(1);
}
$pipeline = vio_pipeline($ctx, [
    'shader'     => $shader,
    'depth_test' => false,
    'cull_mode'  => VIO_CULL_NONE,
    'blend'      => VIO_BLEND_NONE,
]);
if ($pipeline === false) {
    fwrite(STDERR, "volume pipeline create failed.\n");
    exit(1);
}

// ---- 4. Upload the baked volume as a 3D texture -----------------------------
$volTex = vio_texture_3d($ctx, [
    'data'   => $rgba,
    'width'  => $vol->nx,
    'height' => $vol->ny,
    'depth'  => $vol->nz,
    'filter' => VIO_FILTER_LINEAR,
    'wrap'   => VIO_WRAP_CLAMP,
]);
if ($volTex === false) {
    fwrite(STDERR, "vio_texture_3d upload failed on backend '{$backend}'.\n");
    exit(1);
}

$origin = [$vol->origin->x, $vol->origin->y, $vol->origin->z];
$max    = $vol->max();
$size   = [$max->x - $vol->origin->x, $max->y - $vol->origin->y, $max->z - $vol->origin->z];

// ---- Render loop ------------------------------------------------------------
$start = microtime(true);
$frame = 0;

while (true) {
    if (!$headless && vio_should_close($ctx)) {
        break;
    }
    $time = $fixedTime ?? (microtime(true) - $start);

    $camA = $time * 0.25;
    $camPos    = [sin($camA) * 6.0, 3.2, cos($camA) * 6.0];
    $camTarget = [0.0, 0.9, 0.0];
    $sunA = $time * 0.5;
    $sunDir = [cos($sunA) * 0.5, 0.85, sin($sunA) * 0.5];

    [$fbW, $fbH] = vio_framebuffer_size($ctx);
    if (!$headless) {
        vio_poll_events($ctx);
    }

    vio_begin($ctx);
    vio_viewport($ctx, 0, 0, $fbW, $fbH);
    vio_clear($ctx, 0.0, 0.0, 0.0, 1.0);

    vio_bind_pipeline($ctx, $pipeline);
    vio_bind_texture($ctx, $volTex, 0);
    vio_set_uniform($ctx, 'u_sdf_volume', 0);
    vio_set_uniform($ctx, 'u_vol_origin', $origin);
    vio_set_uniform($ctx, 'u_vol_size', $size);
    vio_set_uniform($ctx, 'u_vol_range', $range);
    vio_set_uniform($ctx, 'u_resolution', [(float)$fbW, (float)$fbH]);
    vio_set_uniform($ctx, 'u_time', $time);
    vio_set_uniform($ctx, 'u_cam_pos', $camPos);
    vio_set_uniform($ctx, 'u_cam_target', $camTarget);
    vio_set_uniform($ctx, 'u_sun_dir', $sunDir);
    vio_set_uniform($ctx, 'u_intensity', 1.0);
    vio_set_uniform($ctx, 'u_ao_radius', 1.5);
    vio_set_uniform($ctx, 'u_mode', (float)$mode);
    vio_draw($ctx, $quad);

    vio_end($ctx);

    $frame++;
    if ($shotPath !== null && $frame >= max(1, $frames)) {
        if (vio_save_screenshot($ctx, $shotPath)) {
            fwrite(STDERR, "[volume] saved screenshot -> {$shotPath} ({$fbW}x{$fbH})\n");
        } else {
            fwrite(STDERR, "[volume] vio_save_screenshot failed\n");
        }
        break;
    }
    if ($frames > 0 && $frame >= $frames) {
        break;
    }
}

vio_destroy($ctx);
fwrite(STDERR, "[volume] done ({$frame} frames).\n");
