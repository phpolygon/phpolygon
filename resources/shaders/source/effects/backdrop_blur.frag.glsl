#version 410 core
// Built-in 2D effect 'backdrop_blur' (shader plan 8.1): the 3D image behind the
// rectangle, blurred, tinted and with rounded corners - frosted UI panels, and over
// the whole screen the blurred scene behind a menu. u_backdrop is the 3D scene
// before its tonemap (u_backdrop_hdr = 1) or after it; u_backdrop_rect is the padded
// rectangle in the backdrop's uv (0..1, row 0 at the top).
in vec2 v_uv;
uniform sampler2D u_backdrop;
uniform vec4  u_backdrop_rect;
uniform float u_backdrop_hdr;
uniform float u_backdrop_exposure;
uniform vec2  u_resolution;
uniform vec4  u_rect;
uniform float u_radius;     // blur radius in pixels of the panel (default 12)
uniform vec4  u_tint;       // rgb tint, a = how much of it
uniform float u_corner;     // corner radius in pixels
out vec4 frag_color;

vec3 toneMapACES(vec3 x) {
    const float a = 2.51; const float b = 0.03; const float c = 2.43; const float d = 0.59; const float e = 0.14;
    return clamp((x * (a * x + b)) / (x * (c * x + d) + e), 0.0, 1.0);
}

vec3 backdrop(vec2 uv) {
    vec3 c = texture(u_backdrop, clamp(uv, vec2(0.001), vec2(0.999))).rgb;
    if (u_backdrop_hdr > 0.5) {
        c = pow(toneMapACES(c * max(u_backdrop_exposure, 0.0001)), vec3(1.0 / 2.2));
    }
    return c;
}

void main() {
    vec2 buv = u_backdrop_rect.xy + v_uv * u_backdrop_rect.zw;
    float r = u_radius > 0.0 ? u_radius : 12.0;
    vec2 px = u_backdrop_rect.zw / max(u_resolution, vec2(1.0)) * r;
    vec3 acc = backdrop(buv) * 0.16;
    float wsum = 0.16;
    for (int i = 0; i < 12; i++) {
        float a = float(i) * 0.5236;
        vec2 dir = vec2(cos(a), sin(a));
        acc += backdrop(buv + dir * px * 0.5) * 0.09;
        acc += backdrop(buv + dir * px) * 0.05;
        wsum += 0.14;
    }
    vec3 col = mix(acc / wsum, u_tint.rgb, clamp(u_tint.a, 0.0, 1.0));
    // Rounded corners inside u_rect.
    vec2 p = v_uv * u_resolution;
    vec2 lo = u_rect.xy * u_resolution;
    vec2 hi = u_rect.zw * u_resolution;
    vec2 q = max(max(lo + u_corner - p, p - (hi - u_corner)), vec2(0.0));
    float inside = 1.0 - smoothstep(u_corner - 1.0, u_corner + 0.5, length(q));
    if (u_corner <= 0.0) inside = (p.x >= lo.x && p.x <= hi.x && p.y >= lo.y && p.y <= hi.y) ? 1.0 : 0.0;
    frag_color = vec4(col, inside);
}
