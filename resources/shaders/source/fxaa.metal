// FXAA 3.11 quality preset 12-equivalent (simplified port).
// Mirrors resources/shaders/source/fxaa.frag.glsl but written in Metal MSL.
// Used by MetalRenderer3D's Phase 1.5 offscreen pipeline to post-process the
// scaled / MSAA-resolved scene texture before presenting to the drawable.

#include <metal_stdlib>
using namespace metal;

// Fullscreen-triangle vertex shader (no vertex buffer; gl_VertexID trick).
// Three vertices cover the screen via NDC corners (-1,-1) (3,-1) (-1,3).
struct VS_OUT {
    float4 position [[position]];
    float2 uv;
};

vertex VS_OUT vertex_fxaa(uint vid [[vertex_id]])
{
    VS_OUT o;
    float2 ndc = float2(vid == 1 ? 3.0 : -1.0,
                        vid == 2 ? 3.0 : -1.0);
    o.position = float4(ndc, 0.0, 1.0);
    // Metal Y-up matches OpenGL: NDC->UV with (ndc+1)/2 sets v=0 at the
    // bottom of the texture. Our offscreen colour texture is rendered with
    // Metal Y-up too (no flip in uploadFrameUbo()), so this UV is correct.
    o.uv = (ndc + 1.0) * 0.5;
    return o;
}

struct FxaaParams {
    // (1/width, 1/height) of the input texture, plus padding for 16-byte align.
    float2 inv_resolution;
    float2 _pad;
};

constant float FXAA_EDGE_THRESHOLD     = 0.166;
constant float FXAA_EDGE_THRESHOLD_MIN = 0.0833;
constant float FXAA_SUBPIX_CAP         = 0.75;
constant int   FXAA_SEARCH_STEPS       = 12;

static inline float luma3(float3 rgb) {
    return dot(rgb, float3(0.299, 0.587, 0.114));
}

fragment float4 fragment_fxaa(VS_OUT in [[stage_in]],
                              texture2d<float> color_tex [[texture(0)]],
                              sampler color_sampler [[sampler(0)]],
                              constant FxaaParams& params [[buffer(0)]])
{
    float2 uv  = in.uv;
    float2 inv = params.inv_resolution;

    float3 rgbM = color_tex.sample(color_sampler, uv).rgb;
    float3 rgbN = color_tex.sample(color_sampler, uv + float2(0.0, -inv.y)).rgb;
    float3 rgbS = color_tex.sample(color_sampler, uv + float2(0.0,  inv.y)).rgb;
    float3 rgbE = color_tex.sample(color_sampler, uv + float2( inv.x, 0.0)).rgb;
    float3 rgbW = color_tex.sample(color_sampler, uv + float2(-inv.x, 0.0)).rgb;

    float lumaM = luma3(rgbM);
    float lumaN = luma3(rgbN);
    float lumaS = luma3(rgbS);
    float lumaE = luma3(rgbE);
    float lumaW = luma3(rgbW);

    float lumaMin = min(lumaM, min(min(lumaN, lumaS), min(lumaE, lumaW)));
    float lumaMax = max(lumaM, max(max(lumaN, lumaS), max(lumaE, lumaW)));
    float range   = lumaMax - lumaMin;

    if (range < max(FXAA_EDGE_THRESHOLD_MIN, lumaMax * FXAA_EDGE_THRESHOLD)) {
        return float4(rgbM, 1.0);
    }

    float3 rgbNW = color_tex.sample(color_sampler, uv + float2(-inv.x, -inv.y)).rgb;
    float3 rgbNE = color_tex.sample(color_sampler, uv + float2( inv.x, -inv.y)).rgb;
    float3 rgbSW = color_tex.sample(color_sampler, uv + float2(-inv.x,  inv.y)).rgb;
    float3 rgbSE = color_tex.sample(color_sampler, uv + float2( inv.x,  inv.y)).rgb;

    float lumaNW = luma3(rgbNW);
    float lumaNE = luma3(rgbNE);
    float lumaSW = luma3(rgbSW);
    float lumaSE = luma3(rgbSE);

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

    float2 currentUv = uv;
    if (horizontal) currentUv.y += stepLength * 0.5;
    else            currentUv.x += stepLength * 0.5;

    float2 offset = horizontal ? float2(inv.x, 0.0) : float2(0.0, inv.y);
    float2 uv1 = currentUv - offset;
    float2 uv2 = currentUv + offset;

    float lumaEnd1 = luma3(color_tex.sample(color_sampler, uv1).rgb) - lumaLocalAvg;
    float lumaEnd2 = luma3(color_tex.sample(color_sampler, uv2).rgb) - lumaLocalAvg;

    bool reached1 = abs(lumaEnd1) >= gradientScaled;
    bool reached2 = abs(lumaEnd2) >= gradientScaled;
    bool reachedBoth = reached1 && reached2;

    if (!reached1) uv1 -= offset;
    if (!reached2) uv2 += offset;

    if (!reachedBoth) {
        for (int i = 0; i < FXAA_SEARCH_STEPS; ++i) {
            if (!reached1) {
                lumaEnd1 = luma3(color_tex.sample(color_sampler, uv1).rgb) - lumaLocalAvg;
                reached1 = abs(lumaEnd1) >= gradientScaled;
            }
            if (!reached2) {
                lumaEnd2 = luma3(color_tex.sample(color_sampler, uv2).rgb) - lumaLocalAvg;
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

    float2 finalUv = uv;
    if (horizontal) finalUv.y += finalOffset * stepLength;
    else            finalUv.x += finalOffset * stepLength;

    return float4(color_tex.sample(color_sampler, finalUv).rgb, 1.0);
}

// Passthrough blit used when AA is off but render scale != 1.0.
// Reuses vertex_fxaa for the fullscreen triangle.
fragment float4 fragment_blit(VS_OUT in [[stage_in]],
                              texture2d<float> color_tex [[texture(0)]],
                              sampler color_sampler [[sampler(0)]])
{
    return float4(color_tex.sample(color_sampler, in.uv).rgb, 1.0);
}
