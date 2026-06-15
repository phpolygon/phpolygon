<?php

declare(strict_types=1);

/**
 * Fieldtracing — live SDF sphere-trace demo (Tier B fragment fallback).
 *
 * Renders PHPolygon's Fieldtracing technique as a fullscreen fragment pass: an
 * analytic Signed Distance Field is sphere-traced on the GPU for soft shadows,
 * ambient occlusion and a sky/sun model — no hardware raytracing, no compute,
 * no volume texture. It runs on the *existing* vio API (render straight to the
 * swapchain), which is exactly the cross-backend Tier-B path the design doc
 * ships first (PHPOLYGON_FIELDTRACING.md §9, steps 3-5).
 *
 * The SDF scene in resources/shaders/source/vio/fieldtrace.frag.glsl mirrors the
 * analytic primitives in src/Fieldtracing/Sdf; examples/fieldtracing_bake.php
 * bakes the same scene on the CPU so the two representations agree.
 *
 * Usage:
 *   php examples/fieldtracing_sdf.php                       # windowed, animated
 *   php examples/fieldtracing_sdf.php --screenshot=out.png  # headless 1-frame PNG
 *   php examples/fieldtracing_sdf.php --screenshot=out.png --mode=2 --time=1.5
 *
 * Flags: --screenshot=PATH  --width=N  --height=N  --frames=N
 *        --mode=0..3 (Off|ProbesOnly|SdfOcclusion|SdfBounce)  --time=SECONDS
 */

require __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Rendering\BackendConventions;

if (!extension_loaded('vio')) {
    fwrite(STDERR, "ext-vio is not loaded; this demo needs the vio backend.\n");
    exit(1);
}

// ---- CLI args ---------------------------------------------------------------
$opt = getopt('', ['screenshot::', 'width::', 'height::', 'frames::', 'mode::', 'time::', 'backend::']);
$reqBackend = $opt['backend'] ?? 'auto';
$shotPath  = $opt['screenshot'] ?? null;
$headless  = $shotPath !== null;
$width     = (int)($opt['width']  ?? 1280);
$height    = (int)($opt['height'] ?? 720);
$frames    = (int)($opt['frames'] ?? ($headless ? 1 : 0)); // 0 = run until close
$mode      = max(0, min(3, (int)($opt['mode'] ?? 3)));
$fixedTime = isset($opt['time']) ? (float)$opt['time'] : null;

$shaderDir = __DIR__ . '/../resources/shaders/source/vio/';

// ---- Context ----------------------------------------------------------------
$ctx = vio_create($reqBackend, [
    'width'    => $width,
    'height'   => $height,
    'title'    => 'PHPolygon — Fieldtracing (SDF)',
    'headless' => $headless,
    'visible'  => !$headless,
]);
if ($ctx === false) {
    fwrite(STDERR, "vio_create failed.\n");
    exit(1);
}

$backend = vio_backend_name($ctx);
$format  = BackendConventions::forBackend($backend)->shaderSourceFormat();
fwrite(STDERR, "[fieldtracing] backend={$backend} shaderFormat={$format} mode={$mode}\n");

// ---- Fullscreen quad --------------------------------------------------------
// Interleaved x,y,z,u,v. v=1 at the top so the image renders upright when drawn
// straight to the swapchain (this is NOT the v-flip used for RT sampling).
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
if ($quad === false) {
    fwrite(STDERR, "vio_mesh (fullscreen quad) failed.\n");
    exit(1);
}

// ---- Shader + pipeline ------------------------------------------------------
$vertSrc = file_get_contents($shaderDir . 'fieldtrace.vert.glsl');
$fragSrc = file_get_contents($shaderDir . 'fieldtrace.frag.glsl');
if ($vertSrc === false || $fragSrc === false) {
    fwrite(STDERR, "failed to read fieldtrace shader sources from {$shaderDir}\n");
    exit(1);
}

$shader = vio_shader($ctx, ['vertex' => $vertSrc, 'fragment' => $fragSrc, 'format' => $format]);
if ($shader === false) {
    fwrite(STDERR, "fieldtrace shader compile failed (format={$format}).\n");
    exit(1);
}

$pipeline = vio_pipeline($ctx, [
    'shader'     => $shader,
    'depth_test' => false,
    'cull_mode'  => VIO_CULL_NONE,
    'blend'      => VIO_BLEND_NONE,
]);
if ($pipeline === false) {
    fwrite(STDERR, "fieldtrace pipeline create failed.\n");
    exit(1);
}

// ---- Render loop ------------------------------------------------------------
$start = microtime(true);
$frame = 0;

while (true) {
    if (!$headless && vio_should_close($ctx)) {
        break;
    }

    $time = $fixedTime ?? (microtime(true) - $start);

    // Orbiting camera + slowly arcing sun, so shadows/AO are visibly dynamic.
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
            fwrite(STDERR, "[fieldtracing] saved screenshot -> {$shotPath} ({$fbW}x{$fbH})\n");
        } else {
            fwrite(STDERR, "[fieldtracing] vio_save_screenshot failed\n");
        }
        break;
    }
    if ($frames > 0 && $frame >= $frames) {
        break;
    }
}

vio_destroy($ctx);
fwrite(STDERR, "[fieldtracing] done ({$frame} frames).\n");
