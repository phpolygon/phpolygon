#version 410 core
// Downsample step of the image chain's bloom: 13 taps in five overlapping 2x2 groups
// (Jimenez, "Next Generation Post Processing in Call of Duty", 2014), each output half
// the size of its input. The first step reads the scene colour and prefilters it: the
// energy is capped like bloom_extract (a near-white cloud must not wash the frame), each
// group is weighted by 1 / (1 + luma) (one bright texel cannot make the glow flicker) and
// only what lies above the threshold passes, with the soft knee of bloom_extract.
in vec2 v_uv;
uniform sampler2D u_source;
uniform vec2  u_texel;      // 1 / size of u_source
uniform float u_first;      // 1 = first step, reads the scene
uniform float u_threshold;  // linear luma
out vec4 frag_color;

float luma(vec3 c) {
    return dot(c, vec3(0.2126, 0.7152, 0.0722));
}

vec3 tap(vec2 offset) {
    vec3 c = texture(u_source, v_uv + offset * u_texel).rgb;
    if (u_first > 0.5) {
        c = min(c, vec3(6.0));
        c *= smoothstep(u_threshold, u_threshold + 0.5, luma(c));
    }
    return c;
}

vec3 group(vec3 a, vec3 b, vec3 c, vec3 d) {
    vec3 m = 0.25 * (a + b + c + d);
    return u_first > 0.5 ? m / (1.0 + luma(m)) : m;
}

void main() {
    vec3 a = tap(vec2(-2.0, -2.0));
    vec3 b = tap(vec2( 0.0, -2.0));
    vec3 c = tap(vec2( 2.0, -2.0));
    vec3 d = tap(vec2(-2.0,  0.0));
    vec3 e = tap(vec2( 0.0,  0.0));
    vec3 f = tap(vec2( 2.0,  0.0));
    vec3 g = tap(vec2(-2.0,  2.0));
    vec3 h = tap(vec2( 0.0,  2.0));
    vec3 i = tap(vec2( 2.0,  2.0));
    vec3 j = tap(vec2(-1.0, -1.0));
    vec3 k = tap(vec2( 1.0, -1.0));
    vec3 l = tap(vec2(-1.0,  1.0));
    vec3 m = tap(vec2( 1.0,  1.0));

    vec3 sum = group(j, k, l, m) * 0.5
             + (group(a, b, d, e) + group(b, c, e, f) + group(d, e, g, h) + group(e, f, h, i)) * 0.125;
    if (u_first > 0.5) {
        // Undo the Karis weight on the mean so the glow keeps its energy.
        sum = sum / max(1.0 - luma(sum), 1e-3);
    }
    frag_color = vec4(sum, 1.0);
}
