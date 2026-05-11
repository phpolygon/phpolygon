<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

use VioContext;
use VioMesh;
use VioPipeline;
use VioTexture;

/**
 * vio FXAA post-process pass.
 *
 * Runs an FXAA fragment shader that samples a single-sample colour texture
 * (the offscreen target) and writes anti-aliased pixels to the bound render
 * target (or the swapchain when unbound).
 *
 * Unlike the OpenGL FXAA pass, this one uses an explicit fullscreen quad
 * mesh (`a_position`+`a_uv` layout) because vio's GLSL transpilation path
 * does not support the `gl_VertexID` trick consistently across all
 * backends (D3D11/D3D12/Metal/Vulkan).
 *
 * Lifecycle: shader + pipeline are compiled on first `apply()`. The screen
 * quad is owned by VioRenderer3D and passed in - this class never creates
 * GPU geometry. Resources are released via `release()`.
 */
final class VioFxaaPass
{
    private bool $initialised = false;
    private VioPipeline|false|null $pipeline = null;

    public function __construct(
        private readonly VioContext $ctx,
    ) {
    }

    /**
     * Run the FXAA pass.
     *
     * Caller binds the destination render target (or unbinds for swapchain),
     * sets the viewport to the destination resolution, then invokes apply().
     * `$inputTexture` must be the colour texture of the offscreen target;
     * `$sourceWidth`/`$sourceHeight` are its pixel dimensions (for the
     * `1/resolution` uniform).
     */
    public function apply(
        VioTexture $inputTexture,
        int $sourceWidth,
        int $sourceHeight,
        VioMesh $screenQuad,
    ): void {
        if (!$this->initialised) {
            $this->initialise();
        }

        if ($this->pipeline === null || $this->pipeline === false) {
            return;
        }

        $invW = $sourceWidth  > 0 ? 1.0 / (float)$sourceWidth  : 0.0;
        $invH = $sourceHeight > 0 ? 1.0 / (float)$sourceHeight : 0.0;

        vio_bind_pipeline($this->ctx, $this->pipeline);
        vio_bind_texture($this->ctx, $inputTexture, 0);
        vio_set_uniform($this->ctx, 'u_color_texture', 0);
        vio_set_uniform($this->ctx, 'u_inverse_resolution', [$invW, $invH]);

        vio_draw($this->ctx, $screenQuad);
    }

    public function release(): void
    {
        // vio releases shader + pipeline when references drop.
        $this->pipeline    = null;
        $this->initialised = false;
    }

    private function initialise(): void
    {
        // VIO_SHADER_GLSL_RAW is OpenGL passthrough; on Metal/Vulkan/D3D vio
        // transpiles via VIO_SHADER_GLSL. Mirror VioRenderer3D::compileShader().
        $format = vio_backend_name($this->ctx) === 'opengl'
            ? VIO_SHADER_GLSL_RAW
            : VIO_SHADER_GLSL;

        $shader = vio_shader($this->ctx, [
            'vertex'   => self::FXAA_VERT,
            'fragment' => self::FXAA_FRAG,
            'format'   => $format,
        ]);

        if ($shader === false) {
            $this->pipeline    = false;
            $this->initialised = true;
            fwrite(STDERR, "[VioFxaaPass] shader compile failed (format={$format}).\n");
            return;
        }

        $pipeline = vio_pipeline($this->ctx, [
            'shader'     => $shader,
            'depth_test' => false,
            'cull_mode'  => VIO_CULL_NONE,
            'blend'      => VIO_BLEND_NONE,
        ]);

        if ($pipeline === false) {
            $this->pipeline    = false;
            $this->initialised = true;
            fwrite(STDERR, "[VioFxaaPass] pipeline create failed.\n");
            return;
        }

        $this->pipeline    = $pipeline;
        $this->initialised = true;
    }

    /**
     * Fullscreen-quad vertex shader matching VioRenderer3D::POSTPROCESS_VERT
     * (location 0 = a_position vec3, location 1 = a_uv vec2). The quad is
     * already in NDC, so we pass through xy.
     */
    private const FXAA_VERT = <<<'GLSL'
#version 410 core

layout(location = 0) in vec3 a_position;
layout(location = 1) in vec2 a_uv;

out vec2 v_uv;

void main() {
    v_uv = a_uv;
    gl_Position = vec4(a_position.xy, 0.0, 1.0);
}
GLSL;

    /**
     * FXAA 3.11 quality preset 12-equivalent (simplified port). Identical
     * algorithm to `resources/shaders/source/fxaa.frag.glsl` - duplicated
     * inline so the vio compile path does not depend on filesystem access.
     */
    private const FXAA_FRAG = <<<'GLSL'
#version 410 core

in  vec2 v_uv;
out vec4 frag_color;

uniform sampler2D u_color_texture;
uniform vec2      u_inverse_resolution;

const float FXAA_EDGE_THRESHOLD     = 0.166;
const float FXAA_EDGE_THRESHOLD_MIN = 0.0833;
const float FXAA_SUBPIX_CAP         = 0.75;
const int   FXAA_SEARCH_STEPS       = 12;

float luma(vec3 rgb) {
    return dot(rgb, vec3(0.299, 0.587, 0.114));
}

void main() {
    vec2 uv  = v_uv;
    vec2 inv = u_inverse_resolution;

    vec3 rgbM = texture(u_color_texture, uv).rgb;
    vec3 rgbN = texture(u_color_texture, uv + vec2(0.0, -inv.y)).rgb;
    vec3 rgbS = texture(u_color_texture, uv + vec2(0.0,  inv.y)).rgb;
    vec3 rgbE = texture(u_color_texture, uv + vec2( inv.x, 0.0)).rgb;
    vec3 rgbW = texture(u_color_texture, uv + vec2(-inv.x, 0.0)).rgb;

    float lumaM = luma(rgbM);
    float lumaN = luma(rgbN);
    float lumaS = luma(rgbS);
    float lumaE = luma(rgbE);
    float lumaW = luma(rgbW);

    float lumaMin = min(lumaM, min(min(lumaN, lumaS), min(lumaE, lumaW)));
    float lumaMax = max(lumaM, max(max(lumaN, lumaS), max(lumaE, lumaW)));
    float range   = lumaMax - lumaMin;

    if (range < max(FXAA_EDGE_THRESHOLD_MIN, lumaMax * FXAA_EDGE_THRESHOLD)) {
        frag_color = vec4(rgbM, 1.0);
        return;
    }

    vec3 rgbNW = texture(u_color_texture, uv + vec2(-inv.x, -inv.y)).rgb;
    vec3 rgbNE = texture(u_color_texture, uv + vec2( inv.x, -inv.y)).rgb;
    vec3 rgbSW = texture(u_color_texture, uv + vec2(-inv.x,  inv.y)).rgb;
    vec3 rgbSE = texture(u_color_texture, uv + vec2( inv.x,  inv.y)).rgb;

    float lumaNW = luma(rgbNW);
    float lumaNE = luma(rgbNE);
    float lumaSW = luma(rgbSW);
    float lumaSE = luma(rgbSE);

    float edgeHorz = abs(lumaNW + lumaNE - 2.0 * lumaN) +
                     2.0 * abs(lumaW  + lumaE  - 2.0 * lumaM) +
                     abs(lumaSW + lumaSE - 2.0 * lumaS);
    float edgeVert = abs(lumaNW + lumaSW - 2.0 * lumaW) +
                     2.0 * abs(lumaN  + lumaS  - 2.0 * lumaM) +
                     abs(lumaNE + lumaSE - 2.0 * lumaE);

    bool horizontal = edgeHorz >= edgeVert;

    float luma1 = horizontal ? lumaN : lumaW;
    float luma2 = horizontal ? lumaS : lumaE;
    float gradient1 = luma1 - lumaM;
    float gradient2 = luma2 - lumaM;
    bool steepest1 = abs(gradient1) >= abs(gradient2);

    float gradientScaled = 0.25 * max(abs(gradient1), abs(gradient2));

    float stepLength = horizontal ? inv.y : inv.x;
    float lumaLocalAvg = 0.0;
    if (steepest1) {
        stepLength = -stepLength;
        lumaLocalAvg = 0.5 * (luma1 + lumaM);
    } else {
        lumaLocalAvg = 0.5 * (luma2 + lumaM);
    }

    vec2 currentUv = uv;
    if (horizontal) currentUv.y += stepLength * 0.5;
    else            currentUv.x += stepLength * 0.5;

    vec2 offset = horizontal ? vec2(inv.x, 0.0) : vec2(0.0, inv.y);
    vec2 uv1 = currentUv - offset;
    vec2 uv2 = currentUv + offset;

    float lumaEnd1 = luma(texture(u_color_texture, uv1).rgb) - lumaLocalAvg;
    float lumaEnd2 = luma(texture(u_color_texture, uv2).rgb) - lumaLocalAvg;

    bool reached1 = abs(lumaEnd1) >= gradientScaled;
    bool reached2 = abs(lumaEnd2) >= gradientScaled;
    bool reachedBoth = reached1 && reached2;

    if (!reached1) uv1 -= offset;
    if (!reached2) uv2 += offset;

    if (!reachedBoth) {
        for (int i = 0; i < FXAA_SEARCH_STEPS; ++i) {
            if (!reached1) {
                lumaEnd1 = luma(texture(u_color_texture, uv1).rgb) - lumaLocalAvg;
                reached1 = abs(lumaEnd1) >= gradientScaled;
            }
            if (!reached2) {
                lumaEnd2 = luma(texture(u_color_texture, uv2).rgb) - lumaLocalAvg;
                reached2 = abs(lumaEnd2) >= gradientScaled;
            }
            if (!reached1) uv1 -= offset;
            if (!reached2) uv2 += offset;
            if (reached1 && reached2) break;
        }
    }

    float distance1 = horizontal ? (uv.x - uv1.x) : (uv.y - uv1.y);
    float distance2 = horizontal ? (uv2.x - uv.x) : (uv2.y - uv.y);
    bool isDir1     = distance1 < distance2;
    float distanceFinal = min(distance1, distance2);
    float edgeLength    = distance1 + distance2;
    float pixelOffset   = -distanceFinal / edgeLength + 0.5;

    bool isLumaCenterSmaller = lumaM < lumaLocalAvg;
    bool correctVariation = ((isDir1 ? lumaEnd1 : lumaEnd2) < 0.0) != isLumaCenterSmaller;
    float finalOffset = correctVariation ? pixelOffset : 0.0;

    float lumaAvg = (1.0 / 12.0) *
        (2.0 * (lumaN + lumaS + lumaE + lumaW) +
              (lumaNW + lumaNE + lumaSW + lumaSE));
    float subPixelOffset1 = clamp(abs(lumaAvg - lumaM) / range, 0.0, 1.0);
    float subPixelOffset2 = (-2.0 * subPixelOffset1 + 3.0) * subPixelOffset1 * subPixelOffset1;
    float subPixelFinal   = subPixelOffset2 * subPixelOffset2 * FXAA_SUBPIX_CAP;
    finalOffset = max(finalOffset, subPixelFinal);

    vec2 finalUv = uv;
    if (horizontal) finalUv.y += finalOffset * stepLength;
    else            finalUv.x += finalOffset * stepLength;

    frag_color = vec4(texture(u_color_texture, finalUv).rgb, 1.0);
}
GLSL;
}
