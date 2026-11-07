#version 410 core
// Sky element 3/6: moon disc + soft cool glow (ADDITIVE).
in vec2 v_ndc;
uniform mat4 u_sky_inv_vp;
uniform vec3 u_moon_direction;
uniform vec3 u_moon_color;
uniform float u_moon_intensity;
// Phase 0..1 (0 new, 0.5 full): the lit part of the disc (shader plan 4.3).
uniform float u_moon_phase;
uniform float u_sun_size;       // moon disc sized relative to the sun disc
uniform float u_sun_glow_size;
// HDR scene path (FP16 target tonemapped on resolve). This is an ADDITIVE pass;
// linearise the additive contribution so the resolve's ACES+gamma maps it back
// to its authored display-referred look — keeping the moon HDR≈LDR (mirrors the
// blended sky passes: sky_gradient/clouds/haze).
uniform int u_linear_output;
#ifdef PHPOLYGON_MRT
// Drawn into the MRT scene target: sky is local (unlit) light in attachment 1; the
// renderer's pipeline masks the sun / ambient / G-buffer attachments off, so the
// G-buffer keeps its cleared 0 = "sky" for the AO and reflection passes.
layout(location = 0) out vec4 o_sun;
layout(location = 1) out vec4 o_local;
layout(location = 2) out vec4 o_ambient;
layout(location = 3) out vec4 o_gbuffer;
#define frag_color o_local
#else
out vec4 frag_color;
#endif

float smoothstep01(float e0, float e1, float x) {
    float t = clamp((x - e0) / (e1 - e0), 0.0, 1.0);
    return t * t * (3.0 - 2.0 * t);
}

// Inverse of pow(ACES(x), 1/2.2): positive root of (cy-a)x²+(dy-b)x+ey=0,
// y = display^2.2. Matches the resolve tonemap (exposure 1.0). See sky_gradient.frag.
vec3 invToneMapInvGamma(vec3 displayColor) {
    vec3 y = pow(clamp(displayColor, 0.0, 0.9965), vec3(2.2));
    const float a = 2.51, b = 0.03, c = 2.43, d = 0.59, e = 0.14;
    vec3 A = c * y - a;
    vec3 B = d * y - b;
    vec3 C = e * y;
    vec3 sq = sqrt(max(B * B - 4.0 * A * C, 0.0));
    return max((-B - sq) / (2.0 * A), 0.0);
}

void main() {
    vec4 world = u_sky_inv_vp * vec4(v_ndc, 1.0, 1.0);
    vec3 dir = normalize(world.xyz / world.w);

    float angle = acos(clamp(dot(dir, u_moon_direction), -1.0, 1.0));

    vec3 add = vec3(0.0);
    float disc = 1.0 - smoothstep01(u_sun_size * 0.7, u_sun_size * 1.4, angle);
    // The disc as a lit sphere: its normal from the position on the disc, the light
    // from the phase (behind the moon at new moon, from the viewer at full moon).
    // Maria from a soft noise; the dark side keeps a faint earthshine.
    vec3 md = normalize(u_moon_direction);
    vec3 right = normalize(cross(md, abs(md.y) < 0.99 ? vec3(0.0, 1.0, 0.0) : vec3(1.0, 0.0, 0.0)));
    vec3 upv = cross(right, md);
    vec2 q = vec2(dot(dir, right), dot(dir, upv)) / max(u_sun_size * 1.05, 1e-4);
    float r2 = dot(q, q);
    float lit = 1.0;
    float maria = 1.0;
    if (r2 < 1.0) {
        vec3 n = vec3(q, sqrt(1.0 - r2));
        float th = 6.2831853 * u_moon_phase;
        vec3 l = vec3(sin(th), 0.0, -cos(th));
        lit = smoothstep01(-0.04, 0.08, dot(n, l));
        vec2 m = q * 2.3;
        float mn = sin(m.x * 3.1 + 1.3) * sin(m.y * 2.7 - 0.4) + sin(m.x * 5.3 - m.y * 4.1) * 0.5;
        maria = 1.0 - 0.28 * smoothstep01(0.2, 0.9, mn);
    }
    add += u_moon_color * u_moon_intensity * disc * (lit * maria + 0.05);

    if (angle < u_sun_glow_size * 0.6) {
        float g = 1.0 - angle / (u_sun_glow_size * 0.6);
        g = g * g * 0.35 * u_moon_intensity * (0.2 + 0.8 * (1.0 - abs(u_moon_phase - 0.5) * 2.0));
        add += u_moon_color * g;
    }

    // Under HDR, linearise the additive contribution so the resolve ACES maps an
    // isolated moon back to its authored display look (no over-bright/over-spread).
    if (u_linear_output == 1) {
        add = invToneMapInvGamma(add);
    }
    frag_color = vec4(add, 1.0);
}
