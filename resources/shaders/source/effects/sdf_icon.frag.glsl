#version 410 core
// Built-in 2D effect 'sdf_icon' (shader plan 8.1): crisp icons at any size from signed
// distance functions, with a soft edge and an optional outline. u_shape: 0 circle,
// 1 check, 2 cross, 3 arrow right, 4 triangle (warning), 5 star, 6 lock, 7 play.
in vec2 v_uv;
uniform vec2  u_resolution;
uniform float u_shape;
uniform vec4  u_color;
uniform vec4  u_outline;     // rgb, a = width in pixels
out vec4 frag_color;

float sdSegment(vec2 p, vec2 a, vec2 b) {
    vec2 pa = p - a, ba = b - a;
    return length(pa - ba * clamp(dot(pa, ba) / dot(ba, ba), 0.0, 1.0));
}
float sdBox(vec2 p, vec2 b) { vec2 d = abs(p) - b; return length(max(d, 0.0)) + min(max(d.x, d.y), 0.0); }
float sdTriangle(vec2 p, float r) {
    const float k = sqrt(3.0);
    p.x = abs(p.x) - r; p.y = p.y + r / k;
    if (p.x + k * p.y > 0.0) p = vec2(p.x - k * p.y, -k * p.x - p.y) / 2.0;
    p.x -= clamp(p.x, -2.0 * r, 0.0);
    return -length(p) * sign(p.y);
}
float sdStar(vec2 p, float r, float rf) {
    const vec2 k1 = vec2(0.809016994375, -0.587785252292);
    const vec2 k2 = vec2(-k1.x, k1.y);
    p.x = abs(p.x);
    p -= 2.0 * max(dot(k1, p), 0.0) * k1;
    p -= 2.0 * max(dot(k2, p), 0.0) * k2;
    p.x = abs(p.x);
    p.y -= r;
    vec2 ba = rf * vec2(-k1.y, k1.x) - vec2(0, 1);
    float h = clamp(dot(p, ba) / dot(ba, ba), 0.0, r);
    return length(p - ba * h) * sign(p.y * ba.x - p.x * ba.y);
}

void main() {
    float px = 2.0 / max(min(u_resolution.x, u_resolution.y), 1.0);   // one pixel in p units
    vec2 p = (v_uv * 2.0 - 1.0) * vec2(u_resolution.x / max(u_resolution.y, 1.0), 1.0);
    p.y = -p.y;
    int s = int(u_shape + 0.5);
    float d;
    if (s == 0) d = length(p) - 0.8;
    else if (s == 1) d = min(sdSegment(p, vec2(-0.55, 0.0), vec2(-0.15, -0.4)), sdSegment(p, vec2(-0.15, -0.4), vec2(0.6, 0.45))) - 0.12;
    else if (s == 2) d = min(sdSegment(p, vec2(-0.5), vec2(0.5)), sdSegment(p, vec2(-0.5, 0.5), vec2(0.5, -0.5))) - 0.12;
    else if (s == 3) d = min(sdSegment(p, vec2(-0.6, 0.0), vec2(0.5, 0.0)), min(sdSegment(p, vec2(0.5, 0.0), vec2(0.1, 0.4)), sdSegment(p, vec2(0.5, 0.0), vec2(0.1, -0.4)))) - 0.11;
    else if (s == 4) d = sdTriangle(p * vec2(1.0, -1.0) + vec2(0.0, 0.1), 0.75) - 0.05;
    else if (s == 5) d = sdStar(p, 0.8, 0.45);
    else if (s == 6) d = min(sdBox(p - vec2(0.0, -0.25), vec2(0.5, 0.4)) - 0.06, abs(length(p - vec2(0.0, 0.2)) - 0.32) - 0.09);
    else d = sdTriangle(vec2(-p.y, p.x) - vec2(0.0, 0.05), 0.65) - 0.08;
    float fill = 1.0 - smoothstep(-px, px, d);
    float ow = u_outline.a * px;
    float outline = ow > 0.0 ? (1.0 - smoothstep(ow - px, ow + px, abs(d))) : 0.0;
    vec3 col = mix(u_color.rgb, u_outline.rgb, outline * (1.0 - fill));
    frag_color = vec4(col, max(fill * u_color.a, outline));
}
