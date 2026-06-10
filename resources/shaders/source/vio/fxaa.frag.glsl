#version 410 core

in  vec2 v_uv;
out vec4 frag_color;

uniform sampler2D u_color_texture;
uniform vec2      u_inverse_resolution;

// Additive bloom composited in the same pass (u_bloom_intensity == 0 → off).
uniform sampler2D u_bloom;
uniform float     u_bloom_intensity;

// Full-screen finishing — colour grade + vignette applied to the FINAL image
// (geometry + sky + bloom), so they cover the whole frame uniformly. Neutral
// grade (lift 0, gamma 1, gain 1, saturation 1) + vignette 0 = identity.
uniform vec3  u_grade_lift;
uniform vec3  u_grade_gamma;
uniform vec3  u_grade_gain;
uniform float u_grade_saturation;
uniform float u_vignette_intensity;
uniform vec2  u_viewport_size;

vec3 addBloom(vec3 c) {
    if (u_bloom_intensity <= 0.0) return c;
    return c + texture(u_bloom, v_uv).rgb * u_bloom_intensity;
}

vec3 applyColorGrade(vec3 color) {
    color = color + u_grade_lift;
    vec3 gammaSafe = max(u_grade_gamma, vec3(1e-3)); // guard div-by-zero → black
    color = pow(max(color, vec3(0.0)), vec3(1.0) / gammaSafe);
    color = color * u_grade_gain;
    float luma = dot(color, vec3(0.2126, 0.7152, 0.0722));
    return mix(vec3(luma), color, u_grade_saturation);
}

vec3 applyVignette(vec3 color) {
    if (u_vignette_intensity <= 0.0 || u_viewport_size.x <= 0.0) return color;
    vec2 uv = gl_FragCoord.xy / u_viewport_size;
    float v = smoothstep(0.45, 0.85, length(uv - 0.5));
    return color * (1.0 - v * u_vignette_intensity);
}

// bloom → grade → vignette, in that order, on the resolved FXAA colour.
vec3 finishPost(vec3 c) {
    return applyVignette(applyColorGrade(addBloom(c)));
}

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
        frag_color = vec4(finishPost(rgbM), 1.0);
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

    frag_color = vec4(finishPost(texture(u_color_texture, finalUv).rgb), 1.0);
}
