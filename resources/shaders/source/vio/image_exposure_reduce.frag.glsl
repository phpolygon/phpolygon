#version 410 core
// Metering of the image chain's auto exposure: each output texel holds the mean of a 4x4
// block of its input, from four bilinear taps. The first step reads the linear scene colour
// and writes r = w * log2(luminance), g = w, with a centre weight w (the middle of the
// frame counts four times the corners); later steps average r and g. At 1x1, r / g is the
// weighted mean log2 luminance of the frame.
in vec2 v_uv;
uniform sampler2D u_source;
uniform vec2  u_texel;   // 1 / size of u_source
uniform float u_first;   // 1 = u_source is the scene colour
out vec4 frag_color;

vec2 meter(vec2 uv) {
    vec4 c = texture(u_source, uv);
    if (u_first < 0.5) {
        return c.rg;
    }
    float l = dot(max(c.rgb, vec3(0.0)), vec3(0.2126, 0.7152, 0.0722));
    float w = mix(1.0, 0.25, smoothstep(0.15, 0.6, length(uv - 0.5)));
    return vec2(w * clamp(log2(max(l, 1e-5)), -16.0, 16.0), w);
}

void main() {
    vec2 m = 0.25 * (meter(v_uv + u_texel * vec2(-1.0, -1.0)) + meter(v_uv + u_texel * vec2(1.0, -1.0))
                   + meter(v_uv + u_texel * vec2(-1.0, 1.0)) + meter(v_uv + u_texel * vec2(1.0, 1.0)));
    frag_color = vec4(m, 0.0, 1.0);
}
