#version 410 core
// Accessibility pass (AccessibilitySettings, VioAccessibilityPass): the last step on
// the finished image, after the Final-stage passes and before the HDR10 encoding and
// the UI. Input and output carry the engine's 2.2 power encoding; colour work runs in
// linear light. Every option is neutral at its default value.
in vec2 v_uv;
uniform sampler2D u_source;
uniform float u_cvd_mode;      // ColorVisionMode::code(): 0 off, 1 protan, 2 deutan, 3 tritan
uniform float u_cvd_strength;  // 0..1
uniform float u_cvd_simulate;  // 1 = show what the viewer sees instead of correcting
uniform float u_saturation;    // 1 = unchanged, 0 = grey
uniform float u_contrast;      // 0 = off, 1 = strongest
uniform vec2  u_texel;         // 1 / size of u_source
uniform sampler2D u_state;     // 1x1 (a11y_state): r = flash gain, g = mean luminance of the input
uniform float u_has_state;     // 1 = u_state holds this frame's state
uniform float u_flash_limit;   // 1 = multiply by the flash gain
uniform float u_night_boost;   // 0 = off, 1 = strongest
uniform float u_vignette;      // comfort vignette of this frame, 0 = open, 1 = closed
uniform float u_aspect;        // width / height
out vec4 frag_color;

float luminance(vec3 lin) {
    return dot(lin, vec3(0.2126, 0.7152, 0.0722));
}

// Machado, Oliveira & Fernandes 2009, severity 1.0, rows of the linear-RGB matrix
// (same numbers as Accessibility\ColorVision::SIMULATION).
vec3 cvdSimulate(vec3 c) {
    if (u_cvd_mode < 1.5) {
        return vec3(dot(vec3(0.152286, 1.052583, -0.204868), c),
                    dot(vec3(0.114503, 0.786281, 0.099216), c),
                    dot(vec3(-0.003882, -0.048116, 1.051998), c));
    }
    if (u_cvd_mode < 2.5) {
        return vec3(dot(vec3(0.367322, 0.860646, -0.227968), c),
                    dot(vec3(0.280085, 0.672501, 0.047413), c),
                    dot(vec3(-0.011820, 0.042940, 0.968881), c));
    }
    return vec3(dot(vec3(1.255528, -0.076749, -0.178779), c),
                dot(vec3(-0.078411, 0.930809, 0.147602), c),
                dot(vec3(0.004733, 0.691367, 0.303900), c));
}

// Daltonisation: the difference the viewer cannot see goes into channels they can
// (protan / deutan: into green and blue, tritan: into red and green).
vec3 colourVision(vec3 lin) {
    if (u_cvd_mode < 0.5) {
        return lin;
    }
    vec3 sim = cvdSimulate(lin);
    if (u_cvd_simulate > 0.5) {
        return clamp(mix(lin, sim, u_cvd_strength), 0.0, 1.0);
    }
    vec3 e = lin - sim;
    vec3 shift = u_cvd_mode < 2.5
        ? vec3(0.0, 0.7 * e.r + e.g, 0.7 * e.r + e.b)
        : vec3(e.r + 0.7 * e.b, e.g + 0.7 * e.b, 0.0);
    return clamp(lin + shift * u_cvd_strength, 0.0, 1.0);
}

// Contrast mode, on encoded values (perceptual steps): a steeper curve around mid grey,
// and dark outlines where the luminance jumps (Sobel over the 3x3 neighbourhood).
vec3 contrastMode(vec3 e) {
    if (u_contrast <= 0.0) {
        return e;
    }
    vec3 w = vec3(0.2126, 0.7152, 0.0722);
    float tl = dot(texture(u_source, v_uv + u_texel * vec2(-1.0, -1.0)).rgb, w);
    float t  = dot(texture(u_source, v_uv + u_texel * vec2( 0.0, -1.0)).rgb, w);
    float tr = dot(texture(u_source, v_uv + u_texel * vec2( 1.0, -1.0)).rgb, w);
    float l  = dot(texture(u_source, v_uv + u_texel * vec2(-1.0,  0.0)).rgb, w);
    float r  = dot(texture(u_source, v_uv + u_texel * vec2( 1.0,  0.0)).rgb, w);
    float bl = dot(texture(u_source, v_uv + u_texel * vec2(-1.0,  1.0)).rgb, w);
    float b  = dot(texture(u_source, v_uv + u_texel * vec2( 0.0,  1.0)).rgb, w);
    float br = dot(texture(u_source, v_uv + u_texel * vec2( 1.0,  1.0)).rgb, w);
    float gx = (tr + 2.0 * r + br) - (tl + 2.0 * l + bl);
    float gy = (bl + 2.0 * b + br) - (tl + 2.0 * t + tr);
    float edge = smoothstep(0.1, 0.4, length(vec2(gx, gy)));
    e = clamp((e - 0.5) * (1.0 + 0.6 * u_contrast) + 0.5, 0.0, 1.0);
    return e * (1.0 - edge * 0.65 * u_contrast);
}

// Night boost: a gamma below 1 on the encoded image lifts the shadows and keeps black
// and white where they are. Weighted by the frame's mean linear luminance: full below
// 0.02 (night), none above 0.12 (an ordinary daylight frame).
vec3 nightBoost(vec3 e) {
    if (u_night_boost <= 0.0 || u_has_state < 0.5) {
        return e;
    }
    float w = u_night_boost * (1.0 - smoothstep(0.02, 0.12, texture(u_state, vec2(0.5)).g));
    return pow(max(e, vec3(0.0)), vec3(1.0 / (1.0 + w)));
}

void main() {
    vec3 c = contrastMode(nightBoost(texture(u_source, v_uv).rgb));
    vec3 lin = pow(max(c, vec3(0.0)), vec3(2.2));
    lin = mix(vec3(luminance(lin)), lin, u_saturation);
    lin = colourVision(lin);
    if (u_vignette > 0.0) {
        // 0 at the centre, 1 in the corners; the dark edge moves inwards as it closes.
        float r = length((v_uv - 0.5) * vec2(u_aspect, 1.0)) / length(vec2(u_aspect, 1.0) * 0.5);
        float inner = 1.0 - 0.6 * u_vignette;
        lin *= 1.0 - smoothstep(inner, inner + 0.25, r) * u_vignette;
    }
    if (u_has_state > 0.5 && u_flash_limit > 0.5) {
        lin *= texture(u_state, vec2(0.5)).r;
    }
    frag_color = vec4(pow(clamp(lin, 0.0, 1.0), vec3(1.0 / 2.2)), 1.0);
}
