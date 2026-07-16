#version 150 core
// Fast Approximate Anti-Aliasing (FXAA 3.11-equivalent simplified port).
// Public-domain reference: NVIDIA FXAA 3.11 by Timothy Lottes (Apache 2.0).
// This is a self-contained quality preset with hard-coded edge thresholds
// chosen for character / building silhouettes against sky / terrain.

in  vec2 v_uv;
out vec4 frag_color;

uniform sampler2D u_color_texture;
uniform vec2      u_inverse_resolution; // (1/width, 1/height) of input texture

// Tunables (matching FXAA "quality preset 12-ish")
const float FXAA_EDGE_THRESHOLD      = 0.166; // Local contrast required to consider it an edge
const float FXAA_EDGE_THRESHOLD_MIN  = 0.0833;
const float FXAA_SUBPIX_TRIM         = 0.25;
const float FXAA_SUBPIX_CAP          = 0.75;
const int   FXAA_SEARCH_STEPS        = 12;

float luma(vec3 rgb) {
    // Rec. 601 luminance (FXAA convention)
    return dot(rgb, vec3(0.299, 0.587, 0.114));
}

void main()
{
    vec2 uv  = v_uv;
    vec2 inv = u_inverse_resolution;

    vec3 rgbM  = texture(u_color_texture, uv).rgb;
    vec3 rgbN  = texture(u_color_texture, uv + vec2(0.0, -inv.y)).rgb;
    vec3 rgbS  = texture(u_color_texture, uv + vec2(0.0,  inv.y)).rgb;
    vec3 rgbE  = texture(u_color_texture, uv + vec2( inv.x, 0.0)).rgb;
    vec3 rgbW  = texture(u_color_texture, uv + vec2(-inv.x, 0.0)).rgb;

    float lumaM = luma(rgbM);
    float lumaN = luma(rgbN);
    float lumaS = luma(rgbS);
    float lumaE = luma(rgbE);
    float lumaW = luma(rgbW);

    float lumaMin = min(lumaM, min(min(lumaN, lumaS), min(lumaE, lumaW)));
    float lumaMax = max(lumaM, max(max(lumaN, lumaS), max(lumaE, lumaW)));
    float range   = lumaMax - lumaMin;

    if (range < max(FXAA_EDGE_THRESHOLD_MIN, lumaMax * FXAA_EDGE_THRESHOLD)) {
        // Below contrast threshold - keep the original sample.
        frag_color = vec4(rgbM, 1.0);
        return;
    }

    // Diagonal samples for sub-pixel quality / direction estimate
    vec3 rgbNW = texture(u_color_texture, uv + vec2(-inv.x, -inv.y)).rgb;
    vec3 rgbNE = texture(u_color_texture, uv + vec2( inv.x, -inv.y)).rgb;
    vec3 rgbSW = texture(u_color_texture, uv + vec2(-inv.x,  inv.y)).rgb;
    vec3 rgbSE = texture(u_color_texture, uv + vec2( inv.x,  inv.y)).rgb;

    float lumaNW = luma(rgbNW);
    float lumaNE = luma(rgbNE);
    float lumaSW = luma(rgbSW);
    float lumaSE = luma(rgbSE);

    // Edge direction estimate
    float edgeHorz = abs(lumaNW + lumaNE - 2.0 * lumaN) +
                     2.0 * abs(lumaW  + lumaE  - 2.0 * lumaM) +
                     abs(lumaSW + lumaSE - 2.0 * lumaS);
    float edgeVert = abs(lumaNW + lumaSW - 2.0 * lumaW) +
                     2.0 * abs(lumaN  + lumaS  - 2.0 * lumaM) +
                     abs(lumaNE + lumaSE - 2.0 * lumaE);

    bool horizontal = edgeHorz >= edgeVert;

    // Choose the brighter neighbour along the edge as the search direction
    float luma1 = horizontal ? lumaN : lumaW;
    float luma2 = horizontal ? lumaS : lumaE;
    float gradient1 = luma1 - lumaM;
    float gradient2 = luma2 - lumaM;
    bool steepest1 = abs(gradient1) >= abs(gradient2);

    float gradientScaled = 0.25 * max(abs(gradient1), abs(gradient2));

    // Step half a pixel into the chosen direction
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

    // Search along edge in both directions until contrast drops
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

    // Distance to each end of the edge along the search direction
    float distance1 = horizontal ? (uv.x - uv1.x) : (uv.y - uv1.y);
    float distance2 = horizontal ? (uv2.x - uv.x) : (uv2.y - uv.y);
    bool isDir1     = distance1 < distance2;
    float distanceFinal = min(distance1, distance2);
    float edgeLength    = distance1 + distance2;
    float pixelOffset   = -distanceFinal / edgeLength + 0.5;

    bool isLumaCenterSmaller = lumaM < lumaLocalAvg;
    bool correctVariation = ((isDir1 ? lumaEnd1 : lumaEnd2) < 0.0) != isLumaCenterSmaller;
    float finalOffset = correctVariation ? pixelOffset : 0.0;

    // Sub-pixel anti-aliasing factor based on local contrast
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
