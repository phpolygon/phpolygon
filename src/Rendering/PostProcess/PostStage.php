<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

/**
 * Where a registered {@see FullscreenPass} sits in the image chain. The stages run in
 * this order, inside a stage the pass with the lower `order` first:
 *
 *  scene geometry, composite, SSR, outlines
 *    → Scene     render resolution, scene colour (linear on an HDR target), before any
 *                temporal technique; what it writes also feeds a native upscaler
 *    → temporal resolve / upscale
 *    → Display   display resolution with a temporal technique, render resolution
 *                without; still scene colour, before bloom
 *    → bloom, tonemap, grade, vignette, FXAA / FSR 1 / RCAS
 *    → Final     display resolution, finished sRGB-encoded 0..1 colour, before the
 *                HDR10 encoding and the UI
 */
enum PostStage: string
{
    case Scene = 'scene';
    case Display = 'display';
    case Final = 'final';
}
