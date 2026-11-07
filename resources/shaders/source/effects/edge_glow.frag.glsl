#version 410 core
// Built-in 2D effect 'edge_glow' (shader plan 8.1): a soft glow round a panel's edge,
// breathing slowly - the focus ring of a selected button, a terminal that calls.
in vec2 v_uv;
uniform vec2  u_resolution;
uniform vec4  u_rect;
uniform float u_time;
uniform float u_motion;
uniform vec4  u_color;      // glow colour, a = strength
uniform float u_corner;     // corner radius in pixels
uniform float u_width;      // glow width in pixels (default 10)
out vec4 frag_color;

void main() {
    vec2 p = v_uv * u_resolution;
    vec2 lo = u_rect.xy * u_resolution + u_corner;
    vec2 hi = u_rect.zw * u_resolution - u_corner;
    vec2 q = max(max(lo - p, p - hi), vec2(0.0));
    float d = length(q) - u_corner;            // < 0 inside the rounded rectangle
    float w = u_width > 0.0 ? u_width : 10.0;
    float glow = d < 0.0 ? 0.0 : exp(-d * d / (w * w * 0.35));
    float pulse = 0.8 + 0.2 * sin(u_time * 2.2) * u_motion;
    frag_color = vec4(u_color.rgb, glow * u_color.a * pulse);
}
