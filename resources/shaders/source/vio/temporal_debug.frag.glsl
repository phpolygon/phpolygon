#version 410 core

// Debug view of the temporal inputs (PHPOLYGON_VIO_DEBUG_VIEW, see
// VioMotionDebugPass). Drawn fullscreen over the presented frame.
//
//   u_mode 0  motion    hue = direction, saturation = length (u_scale px = full);
//                       white = no motion. Texture space of the scene target.
//   u_mode 1  reactive  R8 coverage of transparent surfaces, grey ramp.
//   u_mode 2  depth     scene depth linearised with u_inv_proj, log ramp
//                       (white = near, black = far / sky).
//   u_mode 3  history   the temporal resolve's accumulated colour, tonemapped
//                       when it is linear HDR (u_hdr == 1).

in vec2 v_uv;
out vec4 frag_color;

uniform sampler2D u_source;
uniform float u_mode;
uniform float u_scale;          // motion: length in px that saturates the colour
uniform vec2  u_source_size;    // motion: texels of the source (uv -> px)
uniform mat4  u_inv_proj;       // depth: unjittered inverse projection
uniform float u_far;            // depth: far distance for the log ramp
uniform float u_hdr;            // history: 1 = linear HDR

vec3 hsv2rgb(vec3 c) {
    vec3 p = abs(fract(c.xxx + vec3(1.0, 2.0 / 3.0, 1.0 / 3.0)) * 6.0 - 3.0);
    return c.z * mix(vec3(1.0), clamp(p - 1.0, 0.0, 1.0), c.y);
}

void main() {
    vec4 s = texture(u_source, v_uv);
    vec3 col;
    if (u_mode < 0.5) {
        vec2 px = s.xy * u_source_size;
        float len = length(px);
        float hue = atan(px.y, px.x) / 6.2831853 + 0.5;
        col = hsv2rgb(vec3(hue, clamp(len / max(u_scale, 1e-4), 0.0, 1.0), 1.0));
    } else if (u_mode < 1.5) {
        col = vec3(s.r);
    } else if (u_mode < 2.5) {
        vec4 v = u_inv_proj * vec4(0.0, 0.0, s.r * 2.0 - 1.0, 1.0);
        float linear = max(-v.z / v.w, 0.0);
        col = vec3(1.0 - clamp(log2(1.0 + linear) / log2(1.0 + max(u_far, 1.0)), 0.0, 1.0));
    } else {
        col = s.rgb;
        if (u_hdr > 0.5) {
            col = col / (1.0 + col);
            col = pow(col, vec3(1.0 / 2.2));
        }
    }
    frag_color = vec4(col, 1.0);
}
