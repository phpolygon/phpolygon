#version 410 core

// Temporal resolve, pass 2 of 2 (display resolution; VioTaaPass). TAA when the
// render and display sizes match, TAAU (temporal upsampling) when they do not.
//
//  1. Current frame: the 3x3 render texels around this display pixel, weighted
//     by a narrow Gaussian of their sample position's distance in display
//     pixels (sigma 0.25 px). The render texels sit at jittered positions, so
//     over the jitter cycle each display pixel collects the samples that fall
//     into its footprint - a box filter like supersampling, not the blur of a
//     wide reconstruction kernel. The largest weight is the confidence: a frame
//     whose samples all miss the pixel (upsampling) adds little. Karis-weighted
//     (1 / (1 + luma)) so single bright texels do not flicker.
//  2. History: last frame's resolve at uv + motion (dilated, from taa_dilate),
//     5-tap Catmull-Rom (sharp, no bilinear blur accumulating over frames).
//  3. Rejection: the history is clipped into the variance box (mean +- gamma *
//     sigma) of the current neighbourhood in YCoCg; dropped entirely when it
//     left the screen, there is none (first frame / cut / resize), or the
//     surface was hidden last frame (disocclusion: last frame's depth at the
//     reprojected spot is nearer than this surface was).
//  4. Weighted accumulation in a tonemapped (Karis) space: the history's alpha
//     carries the sample weight it gathered (capped lower under motion), this
//     frame adds its confidence; the reactive mask (transparent coverage)
//     raises the current frame's share.

in vec2 v_uv;
out vec4 frag_color;

uniform sampler2D u_color;          // scene colour, render resolution
uniform sampler2D u_history;        // previous resolve, display resolution
uniform sampler2D u_dilated;        // taa_dilate: rg motion, b depth, a expected last-frame depth
uniform sampler2D u_prev_dilated;   // last frame's taa_dilate (b = its depth)
uniform sampler2D u_reactive;       // MRT reactive attachment (R8)
uniform vec2  u_render_size;
uniform vec2  u_display_size;
uniform vec2  u_jitter_px;          // this frame's jitter in render px, texture space
uniform float u_history_valid;      // 0: first frame / cut / resize
uniform float u_history_weight_static; // accumulated sample weight cap where nothing moves
uniform float u_history_weight_moving; // ... and under motion
uniform float u_reactive_strength;  // how far reactive coverage pushes alpha to 1
uniform float u_variance_gamma;     // variance box size (standard deviations) under motion
uniform float u_variance_gamma_static; // ... and where nothing moves (keeps sub-pixel detail)

const float KERNEL = 8.0;          // exp(-8 d^2): sigma = 0.25 display px

float luma(vec3 c) {
    return dot(c, vec3(0.2126, 0.7152, 0.0722));
}

vec3 rgbToYCoCg(vec3 c) {
    return vec3(
        dot(c, vec3(0.25, 0.5, 0.25)),
        dot(c, vec3(0.5, 0.0, -0.5)),
        dot(c, vec3(-0.25, 0.5, -0.25))
    );
}

vec3 yCoCgToRgb(vec3 c) {
    float t = c.x - c.z;
    return vec3(t + c.y, c.x + c.z, t - c.y);
}

// Clip p towards the centre of the box [boxMin, boxMax] (Playdead / INSIDE).
vec3 clipToBox(vec3 boxMin, vec3 boxMax, vec3 p) {
    vec3 centre = 0.5 * (boxMax + boxMin);
    vec3 extent = 0.5 * (boxMax - boxMin) + 1e-5;
    vec3 v = p - centre;
    vec3 units = abs(v / extent);
    float m = max(units.x, max(units.y, units.z));
    return m > 1.0 ? centre + v / m : p;
}

// 5-tap Catmull-Rom (Jimenez, "Filmic SMAA"), the corner taps dropped.
vec3 sampleHistory(vec2 uv) {
    vec2 pos = uv * u_display_size;
    vec2 p1 = floor(pos - 0.5) + 0.5;
    vec2 f = pos - p1;
    vec2 w0 = f * (-0.5 + f * (1.0 - 0.5 * f));
    vec2 w1 = 1.0 + f * f * (-2.5 + 1.5 * f);
    vec2 w2 = f * (0.5 + f * (2.0 - 1.5 * f));
    vec2 w3 = f * f * (-0.5 + 0.5 * f);
    vec2 w12 = w1 + w2;
    vec2 p0 = (p1 - 1.0) / u_display_size;
    vec2 p3 = (p1 + 2.0) / u_display_size;
    vec2 p12 = (p1 + w2 / w12) / u_display_size;

    vec3 c = texture(u_history, vec2(p12.x, p0.y)).rgb * (w12.x * w0.y)
           + texture(u_history, vec2(p0.x, p12.y)).rgb * (w0.x * w12.y)
           + texture(u_history, p12).rgb * (w12.x * w12.y)
           + texture(u_history, vec2(p3.x, p12.y)).rgb * (w3.x * w12.y)
           + texture(u_history, vec2(p12.x, p3.y)).rgb * (w12.x * w3.y);
    float w = w12.x * w0.y + w0.x * w12.y + w12.x * w12.y + w3.x * w12.y + w12.x * w3.y;
    return max(c / w, vec3(0.0));
}

void main() {
    // Position of this display pixel in the jittered render texel grid.
    vec2 p = v_uv * u_render_size + u_jitter_px;
    vec2 centre = floor(p) + 0.5;

    vec3 sum = vec3(0.0);
    float sumW = 0.0;
    float confidence = 0.0;
    vec3 m1 = vec3(0.0);
    vec3 m2 = vec3(0.0);
    for (int y = -1; y <= 1; y++) {
        for (int x = -1; x <= 1; x++) {
            vec2 t = centre + vec2(float(x), float(y));
            vec3 c = max(texture(u_color, t / u_render_size).rgb, vec3(0.0));
            // Distance in DISPLAY pixels: the kernel covers about one display
            // pixel whatever the upscale ratio.
            vec2 d = (t - p) * u_display_size / u_render_size;
            float w = exp(-KERNEL * dot(d, d));
            float wk = w / (1.0 + luma(c));
            sum += c * wk;
            sumW += wk;
            confidence = max(confidence, w);
            vec3 ycc = rgbToYCoCg(c);
            m1 += ycc;
            m2 += ycc * ycc;
        }
    }
    vec3 current = sum / max(sumW, 1e-6);
    vec3 mean = m1 / 9.0;
    vec3 sigma = sqrt(max(m2 / 9.0 - mean * mean, vec3(0.0)));

    vec2 centreUv = centre / u_render_size;
    vec4 dilated = texture(u_dilated, centreUv);
    vec2 prevUv = v_uv + dilated.rg;

    bool onScreen = prevUv.x >= 0.0 && prevUv.y >= 0.0 && prevUv.x <= 1.0 && prevUv.y <= 1.0;
    bool valid = u_history_valid > 0.5 && onScreen;
    if (valid) {
        // Disocclusion: last frame even the farthest surface around the spot
        // was nearer than this one - it was hidden behind an occluder.
        float prevDepth = texture(u_prev_dilated, prevUv).b;
        if (prevDepth < dilated.a * 0.9 - 0.05) {
            valid = false;
        }
    }

    // Weighted accumulation: the history's alpha holds the sample weight it
    // has gathered (capped), this frame adds its confidence. A frame whose
    // samples miss the pixel adds little; an exact hit adds 1. No history:
    // the current frame alone.
    float motionPx = length(dilated.rg * u_display_size);
    float moving = clamp(motionPx, 0.0, 1.0);
    vec3 result = current;
    float weight = max(confidence, 1e-4);
    if (valid) {
        vec4 historySample = vec4(sampleHistory(prevUv), texture(u_history, prevUv).a);
        vec3 h = rgbToYCoCg(historySample.rgb);
        // Where the surface does not move, the reprojection is exact and the
        // history only needs guarding against changes the motion cannot see
        // (an object left, lighting changed); a wider box keeps sub-pixel
        // detail that a single jittered frame misses. Moving surfaces get the
        // tight box. Uniform neighbourhoods have sigma ~ 0 either way, so a
        // trail over plain background is clipped away at once.
        float gamma = mix(u_variance_gamma_static, u_variance_gamma, moving);
        h = clipToBox(mean - gamma * sigma, mean + gamma * sigma, h);
        vec3 history = max(yCoCgToRgb(h), vec3(0.0));

        // Moving content keeps a shorter memory (less blur from repeated
        // resampling, faster recovery from clipping).
        float cap = mix(u_history_weight_static, u_history_weight_moving, moving);
        float historyWeight = clamp(historySample.a, 0.0, cap);
        float alpha = weight / (historyWeight + weight);
        float reactive = clamp(texture(u_reactive, centreUv).r * u_reactive_strength, 0.0, 1.0);
        alpha = mix(alpha, 1.0, reactive);

        float wc = alpha / (1.0 + luma(current));
        float wh = (1.0 - alpha) / (1.0 + luma(history));
        result = (current * wc + history * wh) / max(wc + wh, 1e-6);
        weight = min(historyWeight + weight, cap) * (1.0 - reactive);
    }
    frag_color = vec4(max(result, vec3(0.0)), weight);
}
